<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\PurchaseReturn;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class PurchaseReturnController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = PurchaseReturn::with(['purchaseOrder', 'supplier', 'receiving', 'status', 'requestedBy', 'approvedBy']);

            if ($request->has('supplier_id')) {
                $query->where('supplier_id', $request->supplier_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('pr_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Purchase returns retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve purchase returns: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'pc_id'        => 'required|exists:purchase_orders,po_id',
                'supplier_id'  => 'required|exists:suppliers,id',
                'receiving_id' => 'nullable|exists:receivings,receiving_id',
                'pr_number'    => 'required|string|max:50|unique:purchase_returns,pr_number',
                'return_date'  => 'required|date',
                'reason'       => 'nullable|string',
                'total_amount' => 'nullable|numeric|min:0',
                'status_id'    => 'nullable|exists:status_lookup,status_id',
                'requested_by' => 'nullable|exists:users,user_id',
                'approved_by'  => 'nullable|exists:users,user_id',
                'details'                      => 'required|array|min:1',
                'details.*.product_id'         => 'required|exists:products,product_id',
                'details.*.quantity_returned'  => 'required|integer|min:1',
                'details.*.unit_cost'          => 'required|numeric|min:0',
                'details.*.subtotal'           => 'nullable|numeric|min:0',
                'details.*.condition'          => 'nullable|string|max:100',
                'details.*.serial_id'          => 'nullable|exists:item_serial,serial_id',
            ]);

            DB::beginTransaction();

            $return = PurchaseReturn::create(array_except($validated, ['details']));

            $totalAmount = 0;
            foreach ($validated['details'] as $detail) {
                if (!isset($detail['subtotal'])) {
                    $detail['subtotal'] = $detail['quantity_returned'] * $detail['unit_cost'];
                }
                $return->details()->create($detail);
                $totalAmount += $detail['subtotal'];
            }

            if (!isset($validated['total_amount'])) {
                $return->update(['total_amount' => $totalAmount]);
            }

            DB::commit();

            return $this->success($return->load(['purchaseOrder', 'supplier', 'details.product']), 'Purchase return created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create purchase return: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $return = PurchaseReturn::with(['purchaseOrder', 'supplier', 'receiving', 'status', 'requestedBy', 'approvedBy', 'details.product', 'details.serial'])->findOrFail($id);
            return $this->success($return, 'Purchase return retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Purchase return not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $return = PurchaseReturn::findOrFail($id);

            $validated = $request->validate([
                'return_date'  => 'sometimes|date',
                'reason'       => 'nullable|string',
                'total_amount' => 'nullable|numeric|min:0',
                'status_id'    => 'nullable|exists:status_lookup,status_id',
                'approved_by'  => 'nullable|exists:users,user_id',
            ]);

            $return->update($validated);

            return $this->success($return->load(['purchaseOrder', 'supplier', 'details.product']), 'Purchase return updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update purchase return: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $return = PurchaseReturn::findOrFail($id);
            $return->delete();
            return $this->success(null, 'Purchase return deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete purchase return: ' . $e->getMessage(), 500);
        }
    }
}
