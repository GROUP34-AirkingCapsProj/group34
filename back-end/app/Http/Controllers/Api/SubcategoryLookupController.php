<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\SubcategoryLookup;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SubcategoryLookupController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = SubcategoryLookup::with('category');

            if ($request->has('category_id')) {
                $query->where('category_id', $request->category_id);
            }
            if ($request->has('search')) {
                $query->where('subcategory_name', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 50);
            return $this->paginated($query->orderBy('subcategory_name')->paginate($perPage), 'Subcategories retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve subcategories: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'category_id'      => 'required|exists:category_lookup,category_id',
                'subcategory_name' => 'required|string|max:255',
                'description'      => 'nullable|string',
            ]);

            $subcategory = SubcategoryLookup::create($validated);

            return $this->success($subcategory->load('category'), 'Subcategory created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create subcategory: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $subcategory = SubcategoryLookup::with('category')->findOrFail($id);
            return $this->success($subcategory, 'Subcategory retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Subcategory not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $subcategory = SubcategoryLookup::findOrFail($id);

            $validated = $request->validate([
                'category_id'      => 'sometimes|exists:category_lookup,category_id',
                'subcategory_name' => 'sometimes|string|max:255',
                'description'      => 'nullable|string',
            ]);

            $subcategory->update($validated);

            return $this->success($subcategory->load('category'), 'Subcategory updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update subcategory: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $subcategory = SubcategoryLookup::findOrFail($id);
            $subcategory->delete();
            return $this->success(null, 'Subcategory deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete subcategory: ' . $e->getMessage(), 500);
        }
    }
}
