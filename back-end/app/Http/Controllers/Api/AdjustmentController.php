<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\Inventory;
use App\Models\Product;
use App\Services\Ledger\LedgerPoster;
use App\Support\RealtimeBroadcaster;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class AdjustmentController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = Adjustment::query()
                ->forUser($request->user())
                ->with(['location', 'createdBy', 'approvedBy', 'status']);

            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('adjustment_type')) {
                $query->where('adjustment_type', $request->adjustment_type);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('adjustment_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Adjustments retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve adjustments: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'location_id'       => 'required|exists:locations,location_id',
                'created_by'        => 'nullable|exists:users,user_id',
                'approved_by'       => 'nullable|exists:users,user_id',
                'adjustment_number' => 'required|string|max:50|unique:adjustments,adjustment_number',
                'adjustment_date'   => 'required|date',
                'adjustment_type'   => 'required|string|max:50',
                'adjusted_by'       => 'nullable|string|max:255',
                'status_id'         => 'nullable|exists:status_lookup,status_id',
                'details'                       => 'required|array|min:1',
                'details.*.product_id'          => 'required|exists:products,product_id',
                'details.*.add_quantity'        => 'nullable|integer|min:0',
                'details.*.deduct_quantity'     => 'nullable|integer|min:0',
            ]);

            DB::beginTransaction();

            $adjustment = Adjustment::create(Arr::except($validated, ['details']));

            // Preloaded outside the loop: packaging comes off the product and
            // resolving it per iteration would be an N+1 across the detail lines.
            $products = Product::with(['installationMaterialDetail', 'applianceDetail'])
                ->whereIn('product_id', collect($validated['details'])->pluck('product_id')->all())
                ->get()
                ->keyBy('product_id');

            foreach ($validated['details'] as $detail) {
                $detail['add_quantity']    = $detail['add_quantity'] ?? 0;
                $detail['deduct_quantity'] = $detail['deduct_quantity'] ?? 0;
                $detailRow = $adjustment->details()->create($detail);

                $addQty = (int) $detail['add_quantity'];
                $deductQty = (int) $detail['deduct_quantity'];
                $net = $addQty - $deductQty;

                $inventory = Inventory::firstOrCreate(
                    [
                        'location_id' => $validated['location_id'],
                        'product_id' => $detail['product_id'],
                    ],
                    [
                        'quantity_on_hand' => 0,
                        'available_quantity' => 0,
                        'reorder_level' => 0,
                    ]
                );

                if ($net < 0) {
                    $available = (int) ($inventory->available_quantity ?? $inventory->quantity_on_hand ?? 0);
                    if ($available < abs($net)) {
                        throw new \RuntimeException("Insufficient stock for adjustment product_id {$detail['product_id']}.");
                    }
                }

                $adjProduct = $products->get($detail['product_id']);
                $adjPpp = ($adjProduct && ! $adjProduct->isSerialized())
                    ? $adjProduct->getPiecesPerPackage()
                    : 0;
                $inventory->setPieces((int) $inventory->quantity_on_hand + $net, $adjPpp);
                $inventory->save();

                // Stock moves here, at creation — adjustments have no approval step
                // in this system, so this is the only moment the movement becomes
                // real. A net-zero line moved nothing and posts nothing, which keeps
                // a correction that cancels itself out of the auditor's totals.
                if ($net !== 0) {
                    $adjBatch = \App\Models\InventoryBatch::where('product_id', $detail['product_id'])
                        ->where('location_id', $validated['location_id'])
                        ->whereNotNull('unit_cost')
                        ->orderBy('received_at')
                        ->first();
                    $adjCost = $adjBatch?->unit_cost;

                    app(LedgerPoster::class)->post([
                        'occurred_at'      => $validated['adjustment_date'],
                        'direction'        => $net > 0 ? 'in' : 'out',
                        'movement_type'    => 'adjustment',
                        'product_id'       => (int) $detail['product_id'],
                        'batch_id'         => $adjBatch?->batch_id,
                        'quantity'         => abs($net),
                        'unit_cost'        => $adjCost !== null ? (float) $adjCost : null,
                        'cost_basis'       => $adjCost !== null ? ($adjBatch->cost_basis ?? 'actual') : 'unavailable',
                        'from_location_id' => $net < 0 ? (int) $validated['location_id'] : null,
                        'to_location_id'   => $net > 0 ? (int) $validated['location_id'] : null,
                        'source_type'      => 'adjustments',
                        'source_id'        => (int) $adjustment->adjustment_id,
                        'source_line_id'   => (int) $detailRow->adjustment_detail_id,
                        'correlation_id'   => 'adjustments:' . $adjustment->adjustment_id,
                        'actor_user_id'    => $validated['created_by'] ?? auth()->id(),
                        'notes'            => $validated['adjustment_type'],
                        'posting_key'      => 'adjustment:' . $adjustment->adjustment_id
                                              . ':line:' . $detailRow->adjustment_detail_id
                                              . ':' . ($net > 0 ? 'in' : 'out'),
                    ]);
                }
            }

            DB::commit();

            RealtimeBroadcaster::inventoryChanged((int) $adjustment->location_id, 'stock_adjusted');

            return $this->success($adjustment->load(['location', 'createdBy', 'details.product']), 'Adjustment created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create adjustment: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $adjustment = Adjustment::with(['location', 'createdBy', 'approvedBy', 'status', 'details.product'])->findOrFail($id);
            return $this->success($adjustment, 'Adjustment retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Adjustment not found', 404);
        }
    }

    public function pdf(Request $request, $id)
    {
        $adjustment = Adjustment::with([
            'location', 'createdBy', 'approvedBy', 'status', 'details.product',
        ])->findOrFail($id);

        $paperSize = strtolower((string) $request->query('paper_size', \App\Models\SystemSetting::pdfPaperSize()));
        if (! in_array($paperSize, ['a4', 'letter', 'legal', 'a5'], true)) {
            $paperSize = \App\Models\SystemSetting::pdfPaperSize();
        }
        $paperWidths = ['a4' => 595.28, 'letter' => 612, 'legal' => 612, 'a5' => 419.53];
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('adjustments.document', [
            'adjustment' => $adjustment,
            'printable_width_pt' => $paperWidths[$paperSize] - 72,
            'company' => [
                'name' => \App\Models\SystemSetting::companyName(),
                'address' => \App\Models\SystemSetting::companyAddress(),
                'email' => \App\Models\SystemSetting::companyEmail(),
                'phone' => \App\Models\SystemSetting::companyPhone(),
            ],
        ])->setPaper($paperSize);

        $filename = $adjustment->adjustment_number . '.pdf';
        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    public function update(Request $request, $id)
    {
        try {
            $adjustment = Adjustment::findOrFail($id);

            $validated = $request->validate([
                'adjustment_date' => 'sometimes|date',
                'adjustment_type' => 'sometimes|string|max:50',
                'adjusted_by'     => 'nullable|string|max:255',
                'approved_by'     => 'nullable|exists:users,user_id',
                'status_id'       => 'nullable|exists:status_lookup,status_id',
            ]);

            $adjustment->update($validated);

            return $this->success($adjustment->load(['location', 'createdBy', 'details.product']), 'Adjustment updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update adjustment: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $adjustment = Adjustment::findOrFail($id);
            $adjustment->delete();
            return $this->success(null, 'Adjustment archived successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete adjustment: ' . $e->getMessage(), 500);
        }
    }

    public function archived(Request $request)
    {
        $query = Adjustment::onlyTrashed()->with(['location', 'createdBy', 'status']);

        if ($request->filled('search')) {
            $query->where('adjustment_number', 'like', '%' . $request->search . '%');
        }

        return $this->paginated(
            $query->orderBy('deleted_at', 'desc')->paginate($request->integer('per_page', 15)),
            'Archived adjustments retrieved'
        );
    }

    public function restore($id)
    {
        $adjustment = Adjustment::onlyTrashed()->find($id);
        if (! $adjustment) {
            return $this->notFound('Archived adjustment not found');
        }
        $adjustment->restore();
        return $this->success($adjustment->fresh()->load(['location', 'createdBy', 'status']), 'Adjustment restored successfully');
    }

    public function forceDestroy($id)
    {
        $adjustment = Adjustment::onlyTrashed()->find($id);
        if (! $adjustment) {
            return $this->notFound('Archived adjustment not found');
        }
        $adjustment->forceDelete();
        return $this->success(null, 'Adjustment permanently deleted');
    }
}
