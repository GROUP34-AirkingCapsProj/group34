<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Issuance;
use App\Models\IssuanceDetail;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class IssuanceController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = Issuance::with(['location', 'issuedToUser', 'issuedBy', 'approvedBy', 'status', 'details.product']);

            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('issuance_type')) {
                $query->where('issuance_type', $request->issuance_type);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('issuance_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Issuances retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve issuances: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'location_id'          => 'required|exists:locations,location_id',
                'issuance_date'        => 'required|date',
                'issuance_type'        => 'required|string|max:50',
                'purpose'              => 'nullable|string',
                'issued_to_user_id'    => 'nullable|exists:users,user_id',
                'expected_return_date' => 'nullable|date',
                'actual_return_date'   => 'nullable|date',
                'issued_by'            => 'nullable|exists:users,user_id',
                'approved_by'          => 'nullable|exists:users,user_id',
                'status_id'            => 'nullable|exists:status_lookup,status_id',
                'details'              => 'required|array|min:1',
                'details.*.product_id'        => 'required|exists:products,product_id',
                'details.*.quantity_issued'   => 'required|integer|min:1',
                'details.*.quantity_returned' => 'nullable|integer|min:0',
                'details.*.condition_issued'  => 'nullable|string|max:50',
                'details.*.condition_returned'=> 'nullable|string|max:50',
            ]);

            DB::beginTransaction();

            $issuance = Issuance::create(Arr::except($validated, ['details']));

            foreach ($validated['details'] as $detail) {
                $issuance->details()->create($detail);

                // Deduct inventory at issuance location
                $qty = (int) ($detail['quantity_issued'] ?? 0);
                $inventory = Inventory::where('location_id', $validated['location_id'])
                    ->where('product_id', $detail['product_id'])
                    ->first();

                if (!$inventory) {
                    throw new \RuntimeException('Insufficient stock: inventory record not found for selected location/product.');
                }

                $available = (int) ($inventory->available_quantity ?? $inventory->quantity_on_hand ?? 0);
                if ($available < $qty) {
                    throw new \RuntimeException("Insufficient stock for product_id {$detail['product_id']}.");
                }

                $inventory->quantity_on_hand = max(0, (int) $inventory->quantity_on_hand - $qty);
                $inventory->available_quantity = max(0, (int) $inventory->available_quantity - $qty);
                $inventory->save();
            }

            DB::commit();

            $productIds = array_map(fn ($d) => (int) $d['product_id'], $validated['details']);
            Product::syncQuantityFromInventoryMany($productIds);

            return $this->success($issuance->load(['location', 'issuedToUser', 'details.product']), 'Issuance created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create issuance: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $issuance = Issuance::with(['location', 'issuedToUser', 'issuedBy', 'approvedBy', 'status', 'details.product'])->findOrFail($id);
            return $this->success($issuance, 'Issuance retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Issuance not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $issuance = Issuance::findOrFail($id);

            $validated = $request->validate([
                'location_id'          => 'sometimes|exists:locations,location_id',
                'issuance_date'        => 'sometimes|date',
                'issuance_type'        => 'sometimes|string|max:50',
                'purpose'              => 'nullable|string',
                'issued_to_user_id'    => 'nullable|exists:users,user_id',
                'expected_return_date' => 'nullable|date',
                'actual_return_date'   => 'nullable|date',
                'issued_by'            => 'nullable|exists:users,user_id',
                'approved_by'          => 'nullable|exists:users,user_id',
                'status_id'            => 'nullable|exists:status_lookup,status_id',
            ]);

            $issuance->update($validated);

            return $this->success($issuance->load(['location', 'issuedToUser', 'details.product']), 'Issuance updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update issuance: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $issuance = Issuance::findOrFail($id);
            $issuance->delete();
            return $this->success(null, 'Issuance deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete issuance: ' . $e->getMessage(), 500);
        }
    }
}
