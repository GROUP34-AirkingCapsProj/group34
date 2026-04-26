<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    private const WITH_RELATIONS = [
        'supplier',
        'location',
        'createdBy',
        'approvedBy',
        'status',
        'details.product',
        'details.approvedBy',
    ];

    public function index(Request $request)
    {
        try {
            $query = PurchaseOrder::with(self::WITH_RELATIONS);

            if ($request->has('supplier_id')) {
                $query->where('supplier_id', $request->supplier_id);
            }
            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('po_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated(
                $query->orderBy('created_at', 'desc')->paginate($perPage),
                'Purchase orders retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve purchase orders: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            // Normalize legacy field names from Draft PO Creator
            $payload = $request->all();
            if (empty($payload['po_number']) && !empty($payload['pc_number'])) {
                $payload['po_number'] = $payload['pc_number'];
            }
            if (empty($payload['po_number']) && !empty($payload['reference_no'])) {
                $payload['po_number'] = $payload['reference_no'];
            }
            if (empty($payload['order_date']) && !empty($payload['po_date'])) {
                $payload['order_date'] = $payload['po_date'];
            }
            if (empty($payload['location_id']) && !empty($payload['branch_id'])) {
                $payload['location_id'] = $payload['branch_id'];
            }
            if (empty($payload['details']) && !empty($payload['items']) && is_array($payload['items'])) {
                $payload['details'] = array_map(function ($item) {
                    return [
                        'product_id'      => $item['product_id'] ?? null,
                        'quantity_ordered' => $item['quantity'] ?? $item['qty'] ?? null,
                        'unit_price'       => $item['unit_price'] ?? $item['unit_cost'] ?? null,
                        'subtotal'         => $item['subtotal'] ?? $item['total'] ?? null,
                    ];
                }, $payload['items']);
            }

            $request->replace($payload);

            $validated = $request->validate([
                'supplier_id'                => 'required|exists:suppliers,supplier_id',
                'location_id'                => 'required|exists:locations,location_id',
                'po_number'                  => 'required|string|max:50|unique:purchase_orders,po_number',
                'order_date'                 => 'required|date',
                'expected_delivery_date'     => 'nullable|date',
                'total_amount'               => 'nullable|numeric|min:0',
                'grand_total'                => 'nullable|numeric|min:0',
                'status_id'                  => 'nullable|exists:status_lookup,status_id',
                'created_by'                 => 'nullable|exists:users,user_id',
                'approved_by'                => 'nullable|exists:users,user_id',
                'details'                    => 'required|array|min:1',
                'details.*.product_id'       => 'required|exists:products,product_id',
                'details.*.quantity_ordered'  => 'required|integer|min:1',
                'details.*.unit_price'       => 'required|numeric|min:0',
                'details.*.subtotal'         => 'nullable|numeric|min:0',
                'details.*.approved_by'      => 'nullable|exists:users,user_id',
            ]);

            $authUser = $request->user();
            if ($authUser) {
                $validated['created_by'] = $authUser->user_id;
            }

            DB::beginTransaction();

            $po = PurchaseOrder::create(Arr::except($validated, ['details']));

            $totalAmount = 0;
            foreach ($validated['details'] as $detail) {
                if (!isset($detail['subtotal'])) {
                    $detail['subtotal'] = $detail['quantity_ordered'] * $detail['unit_price'];
                }
                $po->details()->create($detail);
                $totalAmount += $detail['subtotal'];
            }

            if (!isset($validated['total_amount'])) {
                $po->update(['total_amount' => $totalAmount, 'grand_total' => $totalAmount]);
            }

            DB::commit();

            return $this->success(
                $po->load(self::WITH_RELATIONS),
                'Purchase order created successfully',
                201
            );
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create purchase order: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $po = PurchaseOrder::with(
                array_merge(self::WITH_RELATIONS, ['receivings', 'purchaseReturns'])
            )->findOrFail($id);
            return $this->success($po, 'Purchase order retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Purchase order not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $po = PurchaseOrder::findOrFail($id);

            $payload = $request->all();
            if (empty($payload['po_number']) && !empty($payload['pc_number'])) {
                $payload['po_number'] = $payload['pc_number'];
            }
            $request->replace($payload);

            $validated = $request->validate([
                'supplier_id'                => 'sometimes|exists:suppliers,supplier_id',
                'location_id'                => 'sometimes|exists:locations,location_id',
                'po_number'                  => ['sometimes', 'string', 'max:50', Rule::unique('purchase_orders', 'po_number')->ignore($po->po_id, 'po_id')],
                'order_date'                 => 'sometimes|date',
                'expected_delivery_date'     => 'nullable|date',
                'total_amount'               => 'nullable|numeric|min:0',
                'grand_total'                => 'nullable|numeric|min:0',
                'status_id'                  => 'nullable|exists:status_lookup,status_id',
                'approved_by'                => 'nullable|exists:users,user_id',
                'details'                    => 'sometimes|array|min:1',
                'details.*.product_id'       => 'required_with:details|exists:products,product_id',
                'details.*.quantity_ordered'  => 'required_with:details|integer|min:1',
                'details.*.unit_price'       => 'required_with:details|numeric|min:0',
                'details.*.subtotal'         => 'nullable|numeric|min:0',
                'details.*.approved_by'      => 'nullable|exists:users,user_id',
            ]);

            DB::beginTransaction();

            $header = Arr::except($validated, ['details']);
            if (!empty($header)) {
                $po->update($header);
            }

            if (isset($validated['details'])) {
                $po->details()->delete();
                $totalAmount = 0;
                foreach ($validated['details'] as $detail) {
                    if (!isset($detail['subtotal'])) {
                        $detail['subtotal'] = $detail['quantity_ordered'] * $detail['unit_price'];
                    }
                    $po->details()->create($detail);
                    $totalAmount += $detail['subtotal'];
                }
                $po->update([
                    'total_amount' => $totalAmount,
                    'grand_total'  => $validated['grand_total'] ?? $totalAmount,
                ]);
            }

            DB::commit();

            return $this->success(
                $po->fresh()->load(self::WITH_RELATIONS),
                'Purchase order updated successfully'
            );
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to update purchase order: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $po = PurchaseOrder::findOrFail($id);
            $po->delete();
            return $this->success(null, 'Purchase order deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete purchase order: ' . $e->getMessage(), 500);
        }
    }
}
