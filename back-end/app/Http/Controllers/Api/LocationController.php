<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LocationController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = Location::with(['status', 'parent']);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('location_name', 'like', "%{$search}%")
                      ->orWhere('location_code', 'like', "%{$search}%")
                      ->orWhere('address', 'like', "%{$search}%")
                      ->orWhere('city', 'like', "%{$search}%")
                      ->orWhere('region', 'like', "%{$search}%");
                });
            }
            if ($request->has('status_id')) {
                $query->where('status_id', $request->status_id);
            }
            if ($request->has('parent_location_id') && $request->parent_location_id !== '' && $request->parent_location_id !== null) {
                $query->where('parent_location_id', $request->parent_location_id);
            }
            if ($request->has('location_type')) {
                $query->where('location_type', $request->location_type);
            }

            $perPage = $request->get('per_page', 50);
            return $this->paginated($query->orderBy('created_at', 'desc')->paginate($perPage), 'Locations retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve locations: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'parent_location_id' => 'nullable|exists:locations,location_id',
                'location_code'      => 'required|string|max:20|unique:locations,location_code',
                'location_name'      => 'required|string|max:255',
                'location_type'      => 'required|in:warehouse,showroom,branch_office',
                'address'            => 'nullable|string',
                'city'               => 'nullable|string|max:100',
                'province'           => 'nullable|string|max:100',
                'region'             => 'nullable|string|max:100',
                'contact_number'     => 'nullable|string|max:50',
                'email'              => 'nullable|email|max:255',
                'manager_name'       => 'nullable|string|max:255',
                'capacity'           => 'nullable|integer|min:0',
                'opening_date'       => 'nullable|date',
                'status_id'          => 'nullable|exists:status_lookup,status_id',
            ]);

            $location = Location::create($validated);

            return $this->success($location->load(['status', 'parent']), 'Location created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create location: ' . $e->getMessage(), 500);
        }
    }

    public function show(Location $location)
    {
        $location->load(['status', 'parent', 'children', 'users', 'sales', 'purchaseOrders', 'adjustments']);
        return $this->success($location, 'Location retrieved successfully');
    }

    public function update(Request $request, Location $location)
    {
        try {
            $validated = $request->validate([
                'parent_location_id' => 'nullable|exists:locations,location_id',
                'location_code'      => 'sometimes|required|string|max:20|unique:locations,location_code,' . $location->location_id . ',location_id',
                'location_name'      => 'sometimes|required|string|max:255',
                'location_type'      => 'sometimes|required|in:warehouse,showroom,branch_office',
                'address'            => 'nullable|string',
                'city'               => 'nullable|string|max:100',
                'province'           => 'nullable|string|max:100',
                'region'             => 'nullable|string|max:100',
                'contact_number'     => 'nullable|string|max:50',
                'email'              => 'nullable|email|max:255',
                'manager_name'       => 'nullable|string|max:255',
                'capacity'           => 'nullable|integer|min:0',
                'opening_date'       => 'nullable|date',
                'status_id'          => 'nullable|exists:status_lookup,status_id',
            ]);

            $location->update($validated);

            return $this->success($location->load(['status', 'parent']), 'Location updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update location: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Location $location)
    {
        try {
            $location->delete();
            return $this->success(null, 'Location deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete location: ' . $e->getMessage(), 500);
        }
    }
}
