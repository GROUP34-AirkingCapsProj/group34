<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\WarrantyClaim;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WarrantyClaimController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = WarrantyClaim::query();

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('warranty_code', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('serial_number', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('branch')) {
            $query->where('branch', $request->branch);
        }
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 50);
        $claims = $query->paginate($perPage);

        return $this->paginated($claims, 'Warranty claims retrieved successfully');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer_name' => 'required|string|max:255',
                'customer_contact' => 'nullable|string|max:50',
                'item_name' => 'required|string|max:255',
                'serial_number' => 'required|string|max:100',
                'issue' => 'required|string|max:1000',
                'branch' => 'nullable|string|max:255',
                'priority' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL',
                'status' => 'required|in:Open,In-Repair,Completed,Closed',
                'technician' => 'nullable|string|max:255',
                'estimated_date' => 'nullable|date',
            ]);

            // Auto-generate warranty code
            $lastClaim = WarrantyClaim::orderBy('id', 'desc')->first();
            $nextNumber = $lastClaim ? ($lastClaim->id + 1) : 1;
            $validated['warranty_code'] = 'WAR-' . date('Y') . '-' . str_pad(1000 + $nextNumber, 4, '0', STR_PAD_LEFT);

            $claim = WarrantyClaim::create($validated);

            return $this->success($claim, 'Warranty claim created successfully', 201);

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create warranty claim: ' . $e->getMessage(), 500);
        }
    }

    public function show(WarrantyClaim $warrantyClaim)
    {
        return $this->success($warrantyClaim, 'Warranty claim retrieved successfully');
    }

    public function update(Request $request, WarrantyClaim $warrantyClaim)
    {
        try {
            $validated = $request->validate([
                'customer_name' => 'sometimes|required|string|max:255',
                'customer_contact' => 'nullable|string|max:50',
                'item_name' => 'sometimes|required|string|max:255',
                'serial_number' => 'sometimes|required|string|max:100',
                'issue' => 'sometimes|required|string|max:1000',
                'branch' => 'nullable|string|max:255',
                'priority' => 'sometimes|required|in:LOW,MEDIUM,HIGH,CRITICAL',
                'status' => 'sometimes|required|in:Open,In-Repair,Completed,Closed',
                'technician' => 'nullable|string|max:255',
                'estimated_date' => 'nullable|date',
            ]);

            $warrantyClaim->update($validated);

            return $this->success($warrantyClaim, 'Warranty claim updated successfully');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update warranty claim: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(WarrantyClaim $warrantyClaim)
    {
        try {
            $warrantyClaim->delete();

            return $this->success(null, 'Warranty claim deleted successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to delete warranty claim: ' . $e->getMessage(), 500);
        }
    }
}
