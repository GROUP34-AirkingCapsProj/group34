<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    use ApiResponse;  // ← ADD THIS

    public function index(Request $request)
    {
        $query = Transaction::with(['branch', 'user']);

        // Search (item relation removed - items table dropped; use item_name if column exists)
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                  ->orWhere('transaction_code', 'like', "%{$search}%");
                if (Schema::hasColumn('transactions', 'item_name')) {
                    $q->orWhere('item_name', 'like', "%{$search}%");
                }
            });
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by branch
        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Date range filter
        if ($request->has('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        $perPage = $request->get('per_page', 10);
        $transactions = $query->orderBy('date', 'desc')->paginate($perPage);

        // ✅ USING ApiResponse trait
        return $this->paginated($transactions, 'Transactions retrieved successfully');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'transaction_id' => 'required|string|unique:transactions,transaction_id|max:50',
                'date' => 'required|date',
                'type' => 'required|in:Sale,Purchase,Transfer,Restock,Adjustment',
                'item_name' => 'required|string|max:255',
                'branch_id' => 'nullable|exists:locations,location_id',
                'quantity' => 'required|integer|min:1',
                'unit_price' => 'required|numeric|min:0',
                'total_amount' => 'required|numeric|min:0',
                'status' => 'required|in:Completed,Pending,Cancelled',
                'notes' => 'nullable|string|max:500',
            ]);

            // Add authenticated user
            $validated['user_id'] = $request->user()->id;

            $transaction = Transaction::create($validated);

            // ✅ USING ApiResponse trait
            return $this->success(
                $transaction->load(['branch', 'user']),
                'Transaction created successfully',
                201
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create transaction: ' . $e->getMessage(), 500);
        }
    }

    public function show(Transaction $transaction)
    {
        // Load relationships
        $transaction->load(['branch', 'user']);

        // ✅ USING ApiResponse trait
        return $this->success($transaction, 'Transaction retrieved successfully');
    }

    public function update(Request $request, Transaction $transaction)
    {
        try {
            // Check if transaction is already completed
            if ($transaction->status === 'Completed') {
                return $this->error('Cannot update completed transaction', 422);
            }

            $validated = $request->validate([
                'transaction_id' => 'sometimes|required|string|unique:transactions,transaction_id,' . $transaction->id . '|max:50',
                'date' => 'sometimes|required|date',
                'type' => 'sometimes|required|in:Sale,Purchase,Transfer,Restock,Adjustment',
                'item_name' => 'sometimes|required|string|max:255',
                'branch_id' => 'nullable|exists:locations,location_id',
                'quantity' => 'sometimes|required|integer|min:1',
                'unit_price' => 'sometimes|required|numeric|min:0',
                'total_amount' => 'sometimes|required|numeric|min:0',
                'status' => 'sometimes|required|in:Completed,Pending,Cancelled',
                'notes' => 'nullable|string|max:500',
            ]);

            $transaction->update($validated);

            // ✅ USING ApiResponse trait
            return $this->success(
                $transaction->fresh()->load(['branch', 'user']),
                'Transaction updated successfully'
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update transaction: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Transaction $transaction)
    {
        try {
            // Check if transaction can be deleted
            if ($transaction->status === 'Completed') {
                return $this->error('Cannot delete completed transaction', 422);
            }

            $transaction->delete();

            // ✅ USING ApiResponse trait
            return $this->success(null, 'Transaction deleted successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to delete transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get transactions by branch
     */
    public function byBranch($branchId)
    {
        try {
            $transactions = Transaction::where('branch_id', $branchId)
                ->with(['user'])
                ->orderBy('date', 'desc')
                ->paginate(20);

            return $this->paginated($transactions, 'Branch transactions retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve transactions: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Complete a pending transaction
     */
    public function complete(Request $request, Transaction $transaction)
    {
        try {
            if ($transaction->status === 'Completed') {
                return $this->error('Transaction is already completed', 422);
            }

            if ($transaction->status === 'Cancelled') {
                return $this->error('Cannot complete cancelled transaction', 422);
            }

            $transaction->update([
                'status' => 'Completed',
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
            ]);

            return $this->success(
                $transaction->fresh()->load(['branch', 'user']),
                'Transaction completed successfully'
            );

        } catch (\Exception $e) {
            return $this->error('Failed to complete transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancel a transaction
     */
    public function cancel(Request $request, Transaction $transaction)
    {
        try {
            if ($transaction->status === 'Completed') {
                return $this->error('Cannot cancel completed transaction', 422);
            }

            if ($transaction->status === 'Cancelled') {
                return $this->error('Transaction is already cancelled', 422);
            }

            $validated = $request->validate([
                'cancellation_reason' => 'required|string|max:500',
            ]);

            $transaction->update([
                'status' => 'Cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $request->user()->id,
                'cancellation_reason' => $validated['cancellation_reason'],
            ]);

            return $this->success(
                $transaction->fresh()->load(['branch', 'user']),
                'Transaction cancelled successfully'
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to cancel transaction: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get transaction statistics
     */
    public function stats(Request $request)
    {
        try {
            $query = Transaction::query();

            // Filter by date range
            if ($request->has('date_from')) {
                $query->where('date', '>=', $request->date_from);
            }
            if ($request->has('date_to')) {
                $query->where('date', '<=', $request->date_to);
            }

            // Filter by branch
            if ($request->has('branch_id')) {
                $query->where('branch_id', $request->branch_id);
            }

            $stats = [
                'total_transactions' => $query->count(),
                'completed' => $query->clone()->where('status', 'Completed')->count(),
                'pending' => $query->clone()->where('status', 'Pending')->count(),
                'cancelled' => $query->clone()->where('status', 'Cancelled')->count(),
                'total_amount' => $query->clone()->where('status', 'Completed')->sum('total_amount'),
                'by_type' => [
                    'sales' => $query->clone()->where('type', 'Sale')->count(),
                    'purchases' => $query->clone()->where('type', 'Purchase')->count(),
                    'transfers' => $query->clone()->where('type', 'Transfer')->count(),
                    'restocks' => $query->clone()->where('type', 'Restock')->count(),
                ],
            ];

            return $this->success($stats, 'Transaction statistics retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve statistics: ' . $e->getMessage(), 500);
        }
    }
}
