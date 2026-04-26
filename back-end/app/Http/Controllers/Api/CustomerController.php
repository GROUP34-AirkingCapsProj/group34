<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = Customer::query()->withCount('posDeliveryReceipts');

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('customer_name', 'like', "%{$search}%")
                      ->orWhere('company_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('region', 'like', "%{$search}%");
                });
            }

            if ($request->has('customer_type')) {
                $query->where('customer_type', $request->customer_type);
            }

            if ($request->has('is_active')) {
                $val = $request->is_active;
                $isActive = $val === 'true' || $val === '1' || $val === true;
                $query->where('is_active', $isActive);
            }

            $perPage = $request->get('per_page', 50);
            $customers = $query->orderBy('customer_name')->paginate($perPage);

            return $this->paginated($customers, 'Customers retrieved successfully');
        } catch (\Exception $e) {
            report($e);
            return $this->error('Failed to retrieve customers: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_type' => 'required|in:Ordinary,Business,individual,corporate',
                'customer_name' => 'required|string|max:255',
                'customer_code' => 'nullable|string|max:50',
                'contact_number' => 'required|string|max:20',
                'email' => 'nullable|email|max:100',
                'company_name' => 'nullable|string|max:255',
                'address' => 'required|string',
                'city' => 'nullable|string|max:100',
                'province' => 'nullable|string|max:100',
                'region' => 'nullable|string|max:100',
                'tin' => 'nullable|string|max:50',
                'notes' => 'nullable|string',
                'is_active' => 'nullable|boolean',
                'credit_limit' => 'nullable|numeric|min:0',
                'outstanding_balance' => 'nullable|numeric|min:0',
            ]);

            if (in_array($validated['customer_type'], ['Ordinary', 'Business'])) {
                // Keep as is for POS
            } else {
                $validated['customer_type'] = $validated['customer_type'] === 'individual' ? 'Ordinary' : 'Business';
            }

            $validated['is_active'] = $validated['is_active'] ?? true;
            $validated['credit_limit'] = isset($validated['credit_limit']) ? (float) $validated['credit_limit'] : null;
            $validated['outstanding_balance'] = isset($validated['outstanding_balance']) ? (float) $validated['outstanding_balance'] : 0;

            $customer = Customer::create($validated);

            return $this->success($customer->loadCount('posDeliveryReceipts'), 'Customer created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create customer: ' . $e->getMessage(), 500);
        }
    }

    public function show(Customer $customer)
    {
        $customer->loadCount('posDeliveryReceipts');
        return $this->success($customer, 'Customer retrieved successfully');
    }

    public function update(Request $request, Customer $customer)
    {
        try {
            $validated = $request->validate([
                'customer_type' => 'sometimes|in:Ordinary,Business,individual,corporate',
                'customer_name' => 'sometimes|string|max:255',
                'customer_code' => 'nullable|string|max:50',
                'contact_number' => 'sometimes|string|max:20',
                'email' => 'nullable|email|max:100',
                'company_name' => 'nullable|string|max:255',
                'address' => 'sometimes|string',
                'city' => 'nullable|string|max:100',
                'province' => 'nullable|string|max:100',
                'region' => 'nullable|string|max:100',
                'tin' => 'nullable|string|max:50',
                'notes' => 'nullable|string',
                'is_active' => 'nullable|boolean',
                'credit_limit' => 'nullable|numeric|min:0',
                'outstanding_balance' => 'nullable|numeric|min:0',
            ]);

            if (array_key_exists('credit_limit', $validated)) {
                $validated['credit_limit'] = $validated['credit_limit'] !== null ? (float) $validated['credit_limit'] : null;
            }
            if (array_key_exists('outstanding_balance', $validated)) {
                $validated['outstanding_balance'] = (float) ($validated['outstanding_balance'] ?? 0);
            }

            $customer->update($validated);

            return $this->success($customer->fresh()->loadCount('posDeliveryReceipts'), 'Customer updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update customer: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Customer $customer)
    {
        try {
            $customer->delete();
            return $this->success(null, 'Customer deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete customer: ' . $e->getMessage(), 500);
        }
    }

    public function search(Request $request)
    {
        $search = $request->get('q', '');
        $customers = Customer::where('is_active', true)
            ->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })
            ->withCount('posDeliveryReceipts')
            ->limit(20)
            ->get();

        return $this->success($customers, 'Search results');
    }
}
