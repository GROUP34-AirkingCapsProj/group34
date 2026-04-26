<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Adjustment;
use App\Models\AdjustmentDetail;
use App\Models\Inventory;
use App\Models\Product;
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
            $query = Adjustment::with(['location', 'createdBy', 'approvedBy', 'status']);

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

            foreach ($validated['details'] as $detail) {
                $detail['add_quantity']    = $detail['add_quantity'] ?? 0;
                $detail['deduct_quantity'] = $detail['deduct_quantity'] ?? 0;
                $adjustment->details()->create($detail);

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

                $inventory->quantity_on_hand = max(0, (int) $inventory->quantity_on_hand + $net);
                $inventory->available_quantity = max(0, (int) $inventory->available_quantity + $net);
                $inventory->save();
            }

            DB::commit();

            $productIds = array_map(fn ($d) => (int) $d['product_id'], $validated['details']);
            Product::syncQuantityFromInventoryMany($productIds);

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
            return $this->success(null, 'Adjustment deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete adjustment: ' . $e->getMessage(), 500);
        }
    }
}
