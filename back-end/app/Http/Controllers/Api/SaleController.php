<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Sale;
use App\Models\SaleDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = Sale::with(['customer', 'location', 'paymentStatus', 'soldBy']);

            if ($request->has('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }
            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            if ($request->has('payment_status_id')) {
                $query->where('payment_status_id', $request->payment_status_id);
            }
            if ($request->has('search')) {
                $query->where('invoice_number', 'like', '%' . $request->search . '%');
            }
            if ($request->has('date_from')) {
                $query->where('sale_date', '>=', $request->date_from);
            }
            if ($request->has('date_to')) {
                $query->where('sale_date', '<=', $request->date_to);
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Sales retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve sales: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_id'       => 'required|exists:customers,id',
                'location_id'       => 'required|exists:locations,id',
                'invoice_number'    => 'required|string|max:50|unique:sales,invoice_number',
                'sale_date'         => 'required|date',
                'total_amount'      => 'nullable|numeric|min:0',
                'payment_method'    => 'nullable|string|max:50',
                'payment_status_id' => 'nullable|exists:status_lookup,status_id',
                'amount_paid'       => 'nullable|numeric|min:0',
                'balance_due'       => 'nullable|numeric|min:0',
                'sold_by'           => 'nullable|exists:users,user_id',
                'details'                    => 'required|array|min:1',
                'details.*.product_id'       => 'required|exists:products,product_id',
                'details.*.quantity'         => 'required|integer|min:1',
                'details.*.unit_price'       => 'required|numeric|min:0',
                'details.*.subtotal'         => 'nullable|numeric|min:0',
            ]);

            DB::beginTransaction();

            $sale = Sale::create(array_except($validated, ['details']));

            $totalAmount = 0;
            foreach ($validated['details'] as $detail) {
                if (!isset($detail['subtotal'])) {
                    $detail['subtotal'] = $detail['quantity'] * $detail['unit_price'];
                }
                $sale->details()->create($detail);
                $totalAmount += $detail['subtotal'];
            }

            if (!isset($validated['total_amount'])) {
                $sale->update([
                    'total_amount' => $totalAmount,
                    'balance_due'  => $totalAmount - ($validated['amount_paid'] ?? 0),
                ]);
            }

            DB::commit();

            return $this->success($sale->load(['customer', 'location', 'details.product']), 'Sale created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create sale: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $sale = Sale::with(['customer', 'location', 'paymentStatus', 'soldBy', 'details.product', 'deliveryReceipts'])->findOrFail($id);
            return $this->success($sale, 'Sale retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Sale not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $sale = Sale::findOrFail($id);

            $validated = $request->validate([
                'sale_date'         => 'sometimes|date',
                'payment_method'    => 'nullable|string|max:50',
                'payment_status_id' => 'nullable|exists:status_lookup,status_id',
                'amount_paid'       => 'nullable|numeric|min:0',
                'balance_due'       => 'nullable|numeric|min:0',
            ]);

            $sale->update($validated);

            return $this->success($sale->load(['customer', 'location', 'paymentStatus', 'details.product']), 'Sale updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update sale: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $sale = Sale::findOrFail($id);
            $sale->delete();
            return $this->success(null, 'Sale deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete sale: ' . $e->getMessage(), 500);
        }
    }
}
