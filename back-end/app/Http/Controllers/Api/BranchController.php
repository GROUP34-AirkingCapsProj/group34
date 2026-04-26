<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Location::query()
            ->where('location_type', 'branch_office')
            ->with(['users' => function ($q) {
                $q->select('user_id', 'first_name', 'last_name', 'username', 'location_id', 'role_id');
            }]);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('location_name', 'like', "%{$search}%")
                  ->orWhere('location_code', 'like', "%{$search}%");
            });
        }

        // Filter by status (legacy compatibility: Active/Inactive maps to status_id)
        if ($request->has('status')) {
            $isActive = $request->status === 'Active';
            $query->where('status_id', $isActive ? 1 : 2);
        }

        $perPage = $request->get('per_page', 50);
        $branches = $query->paginate($perPage);

        return $this->paginated($branches, 'Branches retrieved successfully');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:50|unique:locations,location_code',
                'region' => 'nullable|string|max:100',
                'city' => 'nullable|string|max:100',
                'address' => 'nullable|string|max:500',
                'contact_number' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:255',
                'opening_date' => 'nullable|date',
                'capacity' => 'nullable|integer',
                'is_active' => 'sometimes|boolean',
            ]);

            $locationData = [
                'location_name'  => $validated['name'],
                'location_code'  => $validated['code'],
                'location_type'  => 'branch_office',
                'region'         => $validated['region'] ?? null,
                'city'           => $validated['city'] ?? null,
                'address'        => $validated['address'] ?? null,
                'contact_number' => $validated['contact_number'] ?? null,
                'email'          => $validated['email'] ?? null,
                'opening_date'   => $validated['opening_date'] ?? null,
                'capacity'       => $validated['capacity'] ?? null,
                'status_id'      => ($validated['is_active'] ?? true) ? 1 : 2,
            ];

            $branch = Location::create($locationData);

            return $this->success($branch, 'Branch created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create branch: ' . $e->getMessage(), 500);
        }
    }

    public function show(Location $branch)
    {
        $branch->load('users');
        return $this->success($branch, 'Branch retrieved successfully');
    }

    public function update(Request $request, Location $branch)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'code' => 'sometimes|required|string|max:50|unique:locations,location_code,' . $branch->location_id . ',location_id',
                'region' => 'nullable|string|max:100',
                'city' => 'nullable|string|max:100',
                'address' => 'nullable|string|max:500',
                'contact_number' => 'nullable|string|max:50',
                'email' => 'nullable|email|max:255',
                'opening_date' => 'nullable|date',
                'capacity' => 'nullable|integer',
                'is_active' => 'sometimes|boolean',
            ]);

            $updateData = [
                'location_type' => 'branch_office',
            ];

            if (array_key_exists('name', $validated)) {
                $updateData['location_name'] = $validated['name'];
            }
            if (array_key_exists('code', $validated)) {
                $updateData['location_code'] = $validated['code'];
            }
            if (array_key_exists('region', $validated)) {
                $updateData['region'] = $validated['region'];
            }
            if (array_key_exists('city', $validated)) {
                $updateData['city'] = $validated['city'];
            }
            if (array_key_exists('address', $validated)) {
                $updateData['address'] = $validated['address'];
            }
            if (array_key_exists('contact_number', $validated)) {
                $updateData['contact_number'] = $validated['contact_number'];
            }
            if (array_key_exists('email', $validated)) {
                $updateData['email'] = $validated['email'];
            }
            if (array_key_exists('opening_date', $validated)) {
                $updateData['opening_date'] = $validated['opening_date'];
            }
            if (array_key_exists('capacity', $validated)) {
                $updateData['capacity'] = $validated['capacity'];
            }
            if (array_key_exists('is_active', $validated)) {
                $updateData['status_id'] = $validated['is_active'] ? 1 : 2;
            }

            $branch->update($updateData);

            return $this->success($branch, 'Branch updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update branch: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Location $branch)
    {
        try {
            $branch->delete();
            return $this->success(null, 'Branch deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete branch: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get branch inventory
     */
    public function inventory($id)
    {
        try {
            $branch = Location::where('location_type', 'branch_office')
                ->where('location_id', $id)
                ->first();

            if (!$branch) {
                return $this->notFound('Branch not found');
            }

            $inventory = $branch->inventories()->with('product')->get();

            return $this->success([
                'branch' => $branch,
                'inventory' => $inventory,
            ], 'Branch inventory retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve inventory: ' . $e->getMessage(), 500);
        }
    }
}
