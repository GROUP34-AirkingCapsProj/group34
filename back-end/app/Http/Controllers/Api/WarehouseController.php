<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;  // ← ADD THIS
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;  // ← ADD THIS

class WarehouseController extends Controller
{
    use ApiResponse;  // ← ADD THIS

    public function index(Request $request)
    {
        $query = Warehouse::with('branch');

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // Filter by branch
        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->get('per_page', 10);
        $warehouses = $query->paginate($perPage);

        // ✅ USING ApiResponse trait
        return $this->paginated($warehouses, 'Warehouses retrieved successfully');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:50|unique:warehouses',
                'branch_id' => 'required|exists:locations,location_id',
                'type' => 'required|in:Main Warehouse,Storage Warehouse,Distribution Center',
                'location' => 'required|string|max:500',
                'capacity' => 'required|integer|min:0',
                'contact_number' => 'nullable|string|max:20',
                'manager' => 'nullable|string|max:255',
                'opening_date' => 'nullable|date',
                'status' => 'required|in:Active,Inactive',
            ]);

            $warehouse = Warehouse::create($validated);

            // ✅ USING ApiResponse trait
            return $this->success(
                $warehouse->load('branch'),
                'Warehouse created successfully',
                201
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create warehouse: ' . $e->getMessage(), 500);
        }
    }

    public function show(Warehouse $warehouse)
    {
        // Load relationships
        $warehouse->load(['branch', 'inventories']);

        // Calculate warehouse stats
        $stats = [
            'total_items' => $warehouse->inventories->count(),
            'total_quantity' => $warehouse->inventories->sum('quantity'),
            'capacity' => $warehouse->capacity,
            'occupancy_percentage' => $warehouse->capacity > 0 
                ? round(($warehouse->inventories->sum('quantity') / $warehouse->capacity) * 100, 2)
                : 0,
        ];

        // ✅ USING ApiResponse trait
        return $this->success([
            'warehouse' => $warehouse,
            'stats' => $stats,
        ], 'Warehouse retrieved successfully');
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        try {
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'code' => 'sometimes|required|string|max:50|unique:warehouses,code,' . $warehouse->id,
                'branch_id' => 'sometimes|required|exists:locations,location_id',
                'type' => 'sometimes|required|in:Main Warehouse,Storage Warehouse,Distribution Center',
                'location' => 'sometimes|required|string|max:500',
                'capacity' => 'sometimes|required|integer|min:0',
                'contact_number' => 'nullable|string|max:20',
                'manager' => 'nullable|string|max:255',
                'opening_date' => 'nullable|date',
                'status' => 'sometimes|required|in:Active,Inactive',
            ]);

            $warehouse->update($validated);

            // ✅ USING ApiResponse trait
            return $this->success(
                $warehouse->fresh()->load('branch'),
                'Warehouse updated successfully'
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update warehouse: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Warehouse $warehouse)
    {
        try {
            // Check if warehouse has inventory
            if ($warehouse->inventories()->exists()) {
                return $this->error(
                    'Cannot delete warehouse with existing inventory. Please transfer or remove inventory first.',
                    422
                );
            }

            $warehouse->delete();

            // ✅ USING ApiResponse trait
            return $this->success(null, 'Warehouse deleted successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to delete warehouse: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get warehouse inventory
     */
    public function inventory($id)
    {
        try {
            $warehouse = Warehouse::find($id);

            if (!$warehouse) {
                return $this->notFound('Warehouse not found');
            }

            $inventory = $warehouse->inventories()
                ->with('item')
                ->paginate(20);

            return $this->paginated(
                $inventory,
                'Warehouse inventory retrieved successfully'
            );

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve inventory: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get warehouse capacity utilization
     */
    public function capacity($id)
    {
        try {
            $warehouse = Warehouse::with('inventories')->find($id);

            if (!$warehouse) {
                return $this->notFound('Warehouse not found');
            }

            $totalQuantity = $warehouse->inventories->sum('quantity');
            $capacity = $warehouse->capacity;
            $available = max(0, $capacity - $totalQuantity);
            $utilizationPercentage = $capacity > 0 
                ? round(($totalQuantity / $capacity) * 100, 2)
                : 0;

            $capacityData = [
                'warehouse_name' => $warehouse->name,
                'total_capacity' => $capacity,
                'current_stock' => $totalQuantity,
                'available_space' => $available,
                'utilization_percentage' => $utilizationPercentage,
                'status' => $this->getCapacityStatus($utilizationPercentage),
            ];

            return $this->success($capacityData, 'Warehouse capacity retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve capacity: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get warehouses by branch
     */
    public function byBranch($branchId)
    {
        try {
            $warehouses = Warehouse::where('branch_id', $branchId)
                ->withCount('inventories')
                ->get();

            return $this->success([
                'warehouses' => $warehouses,
                'count' => $warehouses->count(),
            ], 'Branch warehouses retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve warehouses: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get warehouse statistics
     */
    public function stats($id)
    {
        try {
            $warehouse = Warehouse::with('inventories.item')->find($id);

            if (!$warehouse) {
                return $this->notFound('Warehouse not found');
            }

            $stats = [
                'total_items' => $warehouse->inventories->count(),
                'total_quantity' => $warehouse->inventories->sum('quantity'),
                'low_stock_items' => $warehouse->inventories()
                    ->whereRaw('quantity <= reorder_level')
                    ->count(),
                'capacity_info' => [
                    'total_capacity' => $warehouse->capacity,
                    'used_space' => $warehouse->inventories->sum('quantity'),
                    'available_space' => max(0, $warehouse->capacity - $warehouse->inventories->sum('quantity')),
                    'utilization_percentage' => $warehouse->capacity > 0
                        ? round(($warehouse->inventories->sum('quantity') / $warehouse->capacity) * 100, 2)
                        : 0,
                ],
                'value_info' => [
                    'total_value' => $warehouse->inventories->sum(function($inv) {
                        return $inv->quantity * ($inv->item->unit_price ?? 0);
                    }),
                ],
            ];

            return $this->success($stats, 'Warehouse statistics retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve statistics: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper method to determine capacity status
     */
    private function getCapacityStatus($percentage)
    {
        if ($percentage >= 90) {
            return 'Critical - Nearly Full';
        } elseif ($percentage >= 75) {
            return 'High - Consider Expansion';
        } elseif ($percentage >= 50) {
            return 'Moderate - Good Utilization';
        } else {
            return 'Low - Ample Space Available';
        }
    }
}