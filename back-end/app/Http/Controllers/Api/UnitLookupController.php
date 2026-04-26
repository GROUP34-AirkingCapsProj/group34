<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\UnitLookup;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UnitLookupController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = UnitLookup::orderBy('unit_name');

            if ($request->has('search')) {
                $query->where('unit_name', 'like', '%' . $request->search . '%');
            }

            return $this->success($query->get(), 'Units retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve units: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'unit_name'         => 'required|string|max:100|unique:unit_lookup,unit_name',
                'unit_abbreviation' => 'nullable|string|max:20',
            ]);

            $unit = UnitLookup::create($validated);

            return $this->success($unit, 'Unit created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create unit: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $unit = UnitLookup::findOrFail($id);
            return $this->success($unit, 'Unit retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Unit not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $unit = UnitLookup::findOrFail($id);

            $validated = $request->validate([
                'unit_name'         => 'sometimes|string|max:100|unique:unit_lookup,unit_name,' . $id . ',unit_id',
                'unit_abbreviation' => 'nullable|string|max:20',
            ]);

            $unit->update($validated);

            return $this->success($unit, 'Unit updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update unit: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $unit = UnitLookup::findOrFail($id);
            $unit->delete();
            return $this->success(null, 'Unit deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete unit: ' . $e->getMessage(), 500);
        }
    }
}
