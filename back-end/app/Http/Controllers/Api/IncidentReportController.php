<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentItem;
use App\Models\IncidentReport;
use App\Models\Inventory;
use App\Models\InventoryBatch;
use App\Models\ItemSerial;
use App\Models\StatusLookup;
use App\Services\IncidentService;
use App\Traits\NormalizesBarcode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncidentReportController extends Controller
{
    use NormalizesBarcode;

    public function index(Request $request): JsonResponse
    {
        $query = IncidentReport::with(['product.consumableDetail', 'batch', 'location', 'reportedBy', 'reviewedBy', 'relatedIssuance'])
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
                // Packaging so the list, drawer and confirmation dialogs can show
                // a pack breakdown. quantity is PIECES; a reviewer approving a
                // write-off needs to see "25 pcs · 2 Box + 5 pcs", not a bare 25.
                'pieces_per_package'      => (int) ($i->product?->pieces_per_package ?? 1),
                'package_unit'            => $i->product?->consumableDetail?->package_unit,
                'batch_id'                => $i->batch_id !== null ? (int) $i->batch_id : null,
                'expected_zone'           => $i->expected_zone,
                'actual_zone'             => $i->actual_zone,
                'reason'                  => $i->reason,
                // The cause fields carry what the collapsed reasons used to say.
                // Without these the list and the resolve drawer can only show
                // "Missing", not "Missing — suspected theft".
                'last_known_location'     => $i->last_known_location,
                'suspected_cause'         => $i->suspected_cause,
                'root_cause'              => $i->root_cause,
                'return_direction'        => $i->return_direction,
                'return_reason'           => $i->return_reason,
                'related_transfer_id'     => $i->related_transfer_id !== null ? (int) $i->related_transfer_id : null,
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

    /**
     * Resolve a scanned or typed barcode to the product (and serial) an incident
     * is about.
     *
     * Uses resolveScanAnyState, NOT resolveScan: a filer reporting theft or
     * damage is usually reporting a unit that is no longer in stock, and the
     * strict resolver would report "not found" for exactly those cases.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'barcode'     => 'required|string|max:255',
            'location_id' => 'required|integer|exists:locations,location_id',
        ]);

        $locationId = (int) $validated['location_id'];

        // Location scoping: never resolve a barcode at a location the caller
        // cannot see. Location does NOT use ScopesToUserLocation (that trait
        // filters a location_id COLUMN, which the locations table lacks), so
        // the check goes through the user exactly as the trait does internally:
        // centralized users are unrestricted; a scoped user is pinned to their
        // one location; a scoped user with none is refused.
        $user = Auth::user();
        if (! $user->isCentralized() && $user->scopedLocationId() !== $locationId) {
            return response()->json([
                'success' => false,
                'message' => 'That location is outside your access.',
            ], 403);
        }

        $hit = $this->resolveScanAnyState($validated['barcode'], $locationId);

        if ($hit['product'] === null) {
            return response()->json([
                'success' => false,
                'message' => 'No product matches that barcode at this location.',
            ], 404);
        }

        $product = $hit['product'];
        $serial  = $hit['serial'];

        $inventory = Inventory::where('location_id', $locationId)
            ->where('product_id', $product->product_id)
            ->first();

        $batches = InventoryBatch::where('location_id', $locationId)
            ->where('product_id', $product->product_id)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->get(['batch_id', 'zone_label', 'quantity_remaining', 'received_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'matched_by' => $hit['matched_by'],
                'product'    => [
                    'product_id'         => (int) $product->product_id,
                    'product_name'       => $product->product_name,
                    'product_code'       => $product->product_code,
                    'barcode'            => $product->barcode,
                    // Packaging, so the form can offer pack + loose-pieces entry
                    // instead of one unlabelled box. pieces_per_package is an
                    // appended accessor on Product (defaults to 1); package_unit
                    // lives on the consumable detail row and is null for
                    // appliances, which is the client's "no pack size" signal.
                    'pieces_per_package' => (int) ($product->pieces_per_package ?? 1),
                    'package_unit'       => $product->consumableDetail?->package_unit,
                ],
                'serial' => $serial === null ? null : [
                    'serial_id'     => (int) $serial->serial_id,
                    'serial_number' => $serial->serial_number,
                    // status_id is nullable, so a serial may carry no status.
                    'status_label'  => $serial->status_id
                        ? (StatusLookup::find($serial->status_id)?->status_name ?? 'Unknown')
                        : 'Unknown',
                    'in_stock'      => (int) $serial->status_id === ItemSerial::IN_STOCK_STATUS_ID,
                ],
                'location_quantity' => (int) ($inventory->quantity_on_hand ?? 0),
                'batches' => $batches->map(fn ($b) => [
                    'batch_id'           => (int) $b->batch_id,
                    'zone_label'         => $b->zone_label,
                    'quantity_remaining' => (int) $b->quantity_remaining,
                    'received_at'        => optional($b->received_at)->toDateString(),
                ])->all(),
            ],
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
            // Cause fields. Each belongs to one reason, but none is required
            // here: the guided flow enforces "a missing report needs a suspected
            // cause" at the step it is asked on, and a picking-modal filing has
            // no step to ask it in at all.
            'last_known_location' => 'nullable|string|max:150',
            'suspected_cause'     => ['nullable', Rule::in(IncidentReport::SUSPECTED_CAUSES)],
            'root_cause'          => ['nullable', Rule::in(IncidentReport::ROOT_CAUSES)],
            'return_direction'    => ['nullable', Rule::in(IncidentReport::RETURN_DIRECTIONS)],
            'return_reason'       => 'nullable|string|max:150',
            'related_transfer_id' => 'nullable|integer',

            // The physical things the report is about — one row per unit, so a
            // split-system's indoor and outdoor halves arrive as two.
            //
            // These MUST be listed. validate() returns only the keys it
            // validated, and that return value is what reaches
            // IncidentService::file() below — an unlisted key is not rejected,
            // it is silently dropped, and a guided filing would quietly fall
            // back to a single flat item.
            'items'                 => 'nullable|array|max:20',
            'items.*.product_id'    => 'required_with:items|integer|exists:products,product_id',
            'items.*.batch_id'      => 'nullable|integer|exists:inventory_batches,batch_id',
            'items.*.serial_id'     => 'nullable|integer|exists:item_serial,serial_id',
            'items.*.quantity'      => 'required_with:items|integer|min:1',
            'items.*.unit_role'     => ['nullable', Rule::in(IncidentItem::ROLES)],
            // Kept verbatim from the sticker and deliberately NOT checked
            // against the catalogue: a unit whose label matches nothing is
            // exactly the case a wrong-type report exists to record.
            'items.*.model_code'    => 'nullable|string|max:100',
            'items.*.serial_number' => 'nullable|string|max:100',

            // The client's document block, filled by the reporter. All optional:
            // the person who spots a fault often will not know the dealer's
            // email, and blocking on it would teach people to type junk.
            'dealer_name'         => 'nullable|string|max:150',
            'dealer_phone'        => 'nullable|string|max:40',
            'dealer_mobile'       => 'nullable|string|max:40',
            'dealer_email'        => 'nullable|email|max:150',
            'branch_address'      => 'nullable|string|max:255',
            'customer_name'       => 'nullable|string|max:150',
            'customer_contact'    => 'nullable|string|max:40',
            'site_address'        => 'nullable|string|max:255',
            'purchased_at'        => 'nullable|date',
            'received_at_dor'     => 'nullable|date',
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

    // resolve() is gone. A report is no longer approved and then inspected: the
    // inspector submits findings first and the admin's decision comes last, so
    // both halves live on IncidentInspectionController — POST /inspection and
    // POST /decide.
}
