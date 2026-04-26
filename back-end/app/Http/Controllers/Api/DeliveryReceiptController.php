<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\DeliveryReceipt;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeliveryReceiptController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = DeliveryReceipt::with(['sale.customer', 'issuedBy', 'status']);

            if ($request->has('sales_id')) {
                $query->where('sales_id', $request->sales_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('search')) {
                $query->where('dr_number', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Delivery receipts retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve delivery receipts: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'dr_number' => 'required|string|max:50|unique:delivery_receipts,dr_number',
                'sales_id'  => 'required|exists:sales,sales_id',
                'issued_by' => 'nullable|exists:users,user_id',
                'status_id' => 'nullable|exists:status_lookup,status_id',
            ]);

            $dr = DeliveryReceipt::create($validated);

            return $this->success($dr->load(['sale.customer', 'issuedBy', 'status']), 'Delivery receipt created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create delivery receipt: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $dr = DeliveryReceipt::with(['sale.customer', 'sale.details.product', 'issuedBy', 'status'])->findOrFail($id);
            return $this->success($dr, 'Delivery receipt retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Delivery receipt not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $dr = DeliveryReceipt::findOrFail($id);

            $validated = $request->validate([
                'issued_by' => 'nullable|exists:users,user_id',
                'status_id' => 'nullable|exists:status_lookup,status_id',
            ]);

            $dr->update($validated);

            return $this->success($dr->load(['sale', 'issuedBy', 'status']), 'Delivery receipt updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update delivery receipt: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $dr = DeliveryReceipt::findOrFail($id);
            $dr->delete();
            return $this->success(null, 'Delivery receipt deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete delivery receipt: ' . $e->getMessage(), 500);
        }
    }
}
