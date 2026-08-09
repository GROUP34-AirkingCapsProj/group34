<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Services\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = IncidentReport::with(['product', 'batch', 'location', 'reportedBy', 'reviewedBy', 'relatedIssuance'])
            ->forUser(Auth::user());   // location scoping, same trait every other list uses

        if ($request->query('status') === 'open') {
            $query->open();
        }
        if ($reason = $request->query('reason')) {
            $query->where('reason', $reason);
        }
        if ($locationId = $request->query('location_id')) {
            $query->where('location_id', (int) $locationId);
        }

        $incidents = $query->orderByDesc('created_at')->limit(500)->get();
        $service   = app(IncidentService::class);

        $counts = $incidents->pluck('reported_by')->unique()
            ->mapWithKeys(fn ($userId) => [(int) $userId => $service->incidentCountFor((int) $userId)]);

        return response()->json([
            'success' => true,
            'data'    => $incidents->map(fn (IncidentReport $i) => [
                'incident_id'             => (int) $i->incident_id,
                'incident_number'         => $i->incident_number,
                'source_type'             => $i->source_type,
                'source_id'               => $i->source_id !== null ? (int) $i->source_id : null,
                'related_issuance_id'     => $i->related_issuance_id !== null ? (int) $i->related_issuance_id : null,
                // Issuances are identified by their delivery-receipt number;
                // there is no `issuance_number` column on the table.
                'related_issuance_number' => $i->relatedIssuance?->dr_number,
                'location_id'             => (int) $i->location_id,
                'location_name'           => $i->location?->location_name,
                // (int) null is 0 — a real product id. An `other` incident has no
                // product and must serialize as null, like batch_id does below.
                'product_id'              => $i->product_id !== null ? (int) $i->product_id : null,
                'product_name'            => $i->product?->product_name,
                'batch_id'                => $i->batch_id !== null ? (int) $i->batch_id : null,
                'expected_zone'           => $i->expected_zone,
                'actual_zone'             => $i->actual_zone,
                'reason'                  => $i->reason,
                'quantity'                => (int) $i->quantity,
                'narrative'               => $i->narrative,
                'photo_path'              => $i->photo_path,
                // Evidence photos live on the private `local` disk (not `public`),
                // so this is an authenticated API route, not a /storage/ URL.
                'photo_url'               => $i->photo_path ? "/incident-reports/{$i->incident_id}/photo" : null,
                'photo_source'            => $i->photo_source,
                // ISO 8601 WITH the offset, not toDateTimeString(). The app runs
                // in UTC; a bare "Y-m-d H:i:s" is parsed by `new Date()` as the
                // viewer's local time, so a UTC 08:02 would display as 08:02 in
                // Manila instead of 16:02. The offset makes it unambiguous.
                'occurred_at'             => optional($i->occurred_at)->toIso8601String(),
                'reported_by'             => (int) $i->reported_by,
                'reported_by_name'        => trim(($i->reportedBy?->first_name ?? '') . ' ' . ($i->reportedBy?->last_name ?? '')),
                'reporter_incident_count' => (int) ($counts[(int) $i->reported_by] ?? 0),
                'status_id'               => (int) $i->status_id,
                'is_open'                 => (int) $i->status_id === IncidentReport::statusId('Open'),
                'reviewed_by_name'        => $i->reviewedBy !== null
                    ? trim(($i->reviewedBy->first_name ?? '') . ' ' . ($i->reviewedBy->last_name ?? ''))
                    : null,
                'reviewed_at'             => optional($i->reviewed_at)->toDateTimeString(),
                'adjustment_id'           => $i->adjustment_id !== null ? (int) $i->adjustment_id : null,
                'correction_id'           => $i->correction_id !== null ? (int) $i->correction_id : null,
                'resolution_note'         => $i->resolution_note,
                'created_at'              => optional($i->created_at)->toDateTimeString(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_type'         => ['required', Rule::in(['issuance', 'transfer', 'standalone'])],
            // A standalone report has no source transaction to point at.
            'source_id'           => 'required_unless:source_type,standalone|nullable|integer',
            'related_issuance_id' => 'nullable|integer|exists:issuances,issuance_id',
            'location_id'         => 'required|integer|exists:locations,location_id',
            // An `other` incident has no product and no meaningful quantity.
            'product_id'          => 'required_unless:reason,' . IncidentReport::REASON_OTHER
                                     . '|nullable|integer|exists:products,product_id',
            'batch_id'            => 'nullable|integer|exists:inventory_batches,batch_id',
            'serial_id'           => 'nullable|integer|exists:item_serial,serial_id',
            'expected_zone'       => 'nullable|string|max:100',
            'actual_zone'         => 'nullable|string|max:100',
            'reason'              => ['required', Rule::in(IncidentReport::REASONS)],
            // `min:1` stays armed whenever the key is present, so it must be
            // lifted for `other` — 0 is that reason's correct quantity, and the
            // service stores 0 anyway. required_unless only exempts absence.
            'quantity'            => [
                'required_unless:reason,' . IncidentReport::REASON_OTHER, 'nullable', 'integer',
                Rule::when(
                    fn ($i) => ($i->reason ?? null) !== IncidentReport::REASON_OTHER,
                    ['min:1']
                ),
            ],
            'narrative'           => 'required|string|max:2000',
            // You cannot report something that has not happened yet.
            'occurred_at'         => 'nullable|date|before_or_equal:now',
            // Constrained to exactly the shape uploadPhoto() can produce
            // (incidents/YYYY/MM/<40-char hashName>.<ext>), so a filer cannot
            // submit an arbitrary path (another user's avatar) or an absolute
            // URL, which the frontend's resolveStorageUrl() would fetch as-is.
            'photo_path'          => ['nullable', 'required_with:photo_source', 'string', 'max:255',
                                       'regex:/^incidents\/\d{4}\/\d{2}\/[A-Za-z0-9]{40}\.(jpg|jpeg|png|webp)$/'],
            // Only one direction is required: a provenance claim with no photo
            // attached is a claim about nothing, so photo_path is
            // required_with:photo_source above. The reverse must NOT hold — a
            // photo with no stated source is the pre-camera client's normal
            // request, and stores a null source rather than being rejected.
            'photo_source'        => ['nullable', Rule::in(IncidentReport::PHOTO_SOURCES)],
        ]);

        $incident = app(IncidentService::class)->file($validated, (int) Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Incident filed. Picking may continue with the next batch.',
            'data'    => $incident,
        ], 201);
    }

    /**
     * Store an evidence photo and hand back its disk path, which the caller
     * then submits as `photo_path` when filing. Two steps rather than a
     * multipart file on the file endpoint, so the picking modal can upload
     * while the user is still typing the narrative.
     *
     * Evidence photos may show named staff, stock layout, or the subject of a
     * live loss investigation, and incident_reports is permission-gated
     * everywhere else — so these live on the private `local` disk, not the
     * unauthenticated `public` one, and are only ever served back through
     * showPhoto() behind auth:sanctum. The image/mimes combination mirrors
     * AccountController's avatar upload for consistency with the rest of the
     * codebase (image validates via the same mimes list under the hood, so
     * mimes: alone would do — both are kept for parity with that precedent).
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        // Year/month subdirectory so any future prune of orphaned uploads is a
        // directory-age scan, not a join against incident_reports.photo_path.
        $path = $request->file('photo')->store('incidents/' . now()->format('Y/m'), 'local');

        return response()->json([
            'success' => true,
            'message' => 'Photo uploaded.',
            'data'    => ['path' => $path],
        ], 201);
    }

    /**
     * Stream an evidence photo back inline (for an <img> tag), scoped to
     * incidents the requesting user can see. auth:sanctum + Bearer token
     * (not a ?token= query param), so the credential never appears in the
     * URL — same pattern as the Goods Received Note downloads in api.php.
     */
    public function showPhoto(int $id): StreamedResponse|JsonResponse
    {
        $incident = IncidentReport::forUser(Auth::user())->find($id);

        if (! $incident || ! $incident->photo_path) {
            return response()->json(['success' => false, 'message' => 'Photo not found.'], 404);
        }

        // Defensive: the upload path can't produce anything outside
        // incidents/, but store()'s regex is the only thing actually
        // guaranteeing that once a path round-trips through the database.
        if (! str_starts_with($incident->photo_path, 'incidents/')) {
            return response()->json(['success' => false, 'message' => 'Photo not found.'], 404);
        }

        if (! Storage::disk('local')->exists($incident->photo_path)) {
            return response()->json(['success' => false, 'message' => 'Photo not found.'], 404);
        }

        return Storage::disk('local')->response($incident->photo_path);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'action'               => ['required', Rule::in(['confirm', 'dismiss'])],
            'corrected_product_id' => 'nullable|integer|exists:products,product_id',
            'resolution_note'      => 'nullable|string|max:2000',
        ]);

        $incident = IncidentReport::findOrFail($id);

        try {
            $resolved = app(IncidentService::class)->resolve(
                $incident,
                $validated['action'],
                $validated,
                (int) Auth::id()
            );
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Incident reviewed.',
            'data'    => $resolved,
        ]);
    }
}
