<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IncidentReport;
use App\Services\IncidentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class IncidentReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = IncidentReport::with(['product', 'batch', 'location', 'reportedBy', 'reviewedBy'])
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
                'source_id'               => (int) $i->source_id,
                'location_id'             => (int) $i->location_id,
                'location_name'           => $i->location?->location_name,
                'product_id'              => (int) $i->product_id,
                'product_name'            => $i->product?->product_name,
                'batch_id'                => $i->batch_id !== null ? (int) $i->batch_id : null,
                'expected_zone'           => $i->expected_zone,
                'actual_zone'             => $i->actual_zone,
                'reason'                  => $i->reason,
                'quantity'                => (int) $i->quantity,
                'narrative'               => $i->narrative,
                'photo_path'              => $i->photo_path,
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
            'source_type'   => ['required', Rule::in(['issuance', 'transfer'])],
            'source_id'     => 'required|integer',
            'location_id'   => 'required|integer|exists:locations,location_id',
            'product_id'    => 'required|integer|exists:products,product_id',
            'batch_id'      => 'nullable|integer|exists:inventory_batches,batch_id',
            'serial_id'     => 'nullable|integer|exists:item_serial,serial_id',
            'expected_zone' => 'nullable|string|max:100',
            'actual_zone'   => 'nullable|string|max:100',
            'reason'        => ['required', Rule::in(IncidentReport::REASONS)],
            'quantity'      => 'required|integer|min:1',
            'narrative'     => 'required|string|max:2000',
            'photo_path'    => 'nullable|string|max:255',
        ]);

        $incident = app(IncidentService::class)->file($validated, (int) Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Incident filed. Picking may continue with the next batch.',
            'data'    => $incident,
        ], 201);
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
