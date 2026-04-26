<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\ProfitLoss;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProfitLossController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = ProfitLoss::with(['model', 'product', 'recordedBy', 'approvedBy', 'status']);

            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }
            if ($request->has('model_id')) {
                $query->where('model_id', $request->model_id);
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('reference_type')) {
                $query->where('reference_type', $request->reference_type);
            }

            $perPage = $request->get('per_page', 20);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Profit/loss records retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve profit/loss records: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'model_id'          => 'nullable|exists:model_lookup,model_id',
                'product_id'        => 'required|exists:products,product_id',
                'reference_type'    => 'nullable|string|max:50',
                'transaction_date'  => 'nullable|date',
                'incident_date'     => 'nullable|date',
                'serial_number'     => 'nullable|string|max:100',
                'quantity_lost'     => 'required|integer|min:0',
                'unit_cost'         => 'required|numeric|min:0',
                'total_loss_amount' => 'nullable|numeric|min:0',
                'recorded_by'       => 'nullable|exists:users,user_id',
                'approved_by'       => 'nullable|exists:users,user_id',
                'status_id'         => 'nullable|exists:status_lookup,status_id',
            ]);

            // Auto-calculate total_loss_amount if not provided
            if (!isset($validated['total_loss_amount'])) {
                $validated['total_loss_amount'] = $validated['quantity_lost'] * $validated['unit_cost'];
            }

            $record = ProfitLoss::create($validated);

            return $this->success($record->load(['model', 'product', 'recordedBy', 'status']), 'Profit/loss record created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create profit/loss record: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $record = ProfitLoss::with(['model', 'product', 'recordedBy', 'approvedBy', 'status', 'receivings'])->findOrFail($id);
            return $this->success($record, 'Profit/loss record retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Profit/loss record not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $record = ProfitLoss::findOrFail($id);

            $validated = $request->validate([
                'model_id'          => 'nullable|exists:model_lookup,model_id',
                'product_id'        => 'sometimes|exists:products,product_id',
                'reference_type'    => 'nullable|string|max:50',
                'transaction_date'  => 'nullable|date',
                'incident_date'     => 'nullable|date',
                'serial_number'     => 'nullable|string|max:100',
                'quantity_lost'     => 'sometimes|integer|min:0',
                'unit_cost'         => 'sometimes|numeric|min:0',
                'total_loss_amount' => 'nullable|numeric|min:0',
                'recorded_by'       => 'nullable|exists:users,user_id',
                'approved_by'       => 'nullable|exists:users,user_id',
                'status_id'         => 'nullable|exists:status_lookup,status_id',
            ]);

            $record->update($validated);

            return $this->success($record->load(['model', 'product', 'recordedBy', 'approvedBy', 'status']), 'Profit/loss record updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update profit/loss record: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $record = ProfitLoss::findOrFail($id);
            $record->delete();
            return $this->success(null, 'Profit/loss record deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete profit/loss record: ' . $e->getMessage(), 500);
        }
    }
}
