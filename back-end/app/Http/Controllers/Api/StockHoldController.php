<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockHold;
use App\Services\StockHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The held-stock worklist and the inspection decision.
 *
 * Everything that mutates goes through StockHoldService — this controller
 * validates, authorizes and serializes, and owns none of the stock arithmetic.
 */
class StockHoldController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockHold::with(['product.consumableDetail', 'location', 'incident', 'disposedBy'])
            ->forUser(Auth::user());   // location scoping, same trait every other list uses

        if ($request->query('status', 'held') !== 'all') {
            $query->where('status', $request->query('status', 'held'));
        }
        if ($condition = $request->query('condition')) {
            $query->where('condition', $condition);
        }
        if ($locationId = $request->query('location_id')) {
            $query->where('location_id', (int) $locationId);
        }

        $holds = $query->orderByDesc('created_at')->limit(500)->get();

        return response()->json([
            'success' => true,
            'data'    => $holds->map(fn (StockHold $h) => [
                'hold_id'          => (int) $h->hold_id,
                'incident_id'      => (int) $h->incident_id,
                'incident_number'  => $h->incident?->incident_number,
                'product_id'       => (int) $h->product_id,
                'product_name'     => $h->product?->product_name,
                // Packaging, so the worklist can show "25 pcs · 2 Box + 5 pcs"
                // exactly as the incident list does. quantity is PIECES.
                'pieces_per_package' => (int) ($h->product?->pieces_per_package ?? 1),
                'package_unit'     => $h->product?->consumableDetail?->package_unit,
                'location_id'      => (int) $h->location_id,
                'location_name'    => $h->location?->location_name,
                'batch_id'         => $h->batch_id !== null ? (int) $h->batch_id : null,
                'serial_id'        => $h->serial_id !== null ? (int) $h->serial_id : null,
                'condition'        => $h->condition,
                'quantity'         => (int) $h->quantity,
                'status'           => $h->status,
                'disposition'      => $h->disposition,
                'disposition_note' => $h->disposition_note,
                'disposed_by_name' => $h->disposedBy !== null
                    ? trim(($h->disposedBy->first_name ?? '') . ' ' . ($h->disposedBy->last_name ?? ''))
                    : null,
                'disposed_at'      => optional($h->disposed_at)->toIso8601String(),
                // The client decides which buttons to show from this, so the
                // rule lives in ONE place — the model — not in the UI too.
                'allowed_dispositions' => $h->status === StockHold::STATUS_HELD
                    ? (StockHold::ALLOWED_DISPOSITIONS[$h->condition] ?? [])
                    : [],
                'created_at'       => optional($h->created_at)->toIso8601String(),
            ])->all(),
        ]);
    }

    public function dispose(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'disposition' => ['required', Rule::in([
                StockHold::DISPOSITION_RESTOCKED,
                StockHold::DISPOSITION_DEFECTIVE,
                StockHold::DISPOSITION_RETURNED_TO_SUPPLIER,
                StockHold::DISPOSITION_SCRAPPED,
            ])],
            'note' => 'nullable|string|max:2000',
        ]);

        $hold = StockHold::forUser(Auth::user())->find($id);

        if ($hold === null) {
            return response()->json(['success' => false, 'message' => 'Hold not found.'], 404);
        }

        try {
            $disposed = app(StockHoldService::class)->dispose(
                $hold,
                $validated['disposition'],
                $validated['note'] ?? null,
                (int) Auth::id(),
            );
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            // 422, not 500: "already disposed" and "not allowed for this
            // condition" are both the caller asking for something invalid.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Hold disposed.',
            'data'    => $disposed,
        ]);
    }
}
