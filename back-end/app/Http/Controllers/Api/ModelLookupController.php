<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\ModelLookup;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ModelLookupController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = ModelLookup::with(['brand', 'status']);

            if ($request->has('brand_id')) {
                $query->where('brand_id', $request->brand_id);
            }
            if ($request->has('search')) {
                $query->where('model_code', 'like', '%' . $request->search . '%');
            }

            $perPage = $request->get('per_page', 50);
            return $this->paginated($query->orderBy('model_code')->paginate($perPage), 'Models retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve models: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'brand_id'   => 'required|exists:brand_lookup,brand_id',
                'model_code' => 'required|string|max:100',
                'variant'    => 'nullable|string|max:100',
                'capacity'   => 'nullable|string|max:100',
                'status_id'  => 'nullable|exists:status_lookup,status_id',
            ]);

            $model = ModelLookup::create($validated);

            return $this->success($model->load('brand'), 'Model created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create model: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $model = ModelLookup::with(['brand', 'status'])->findOrFail($id);
            return $this->success($model, 'Model retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Model not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $model = ModelLookup::findOrFail($id);

            $validated = $request->validate([
                'brand_id'   => 'sometimes|exists:brand_lookup,brand_id',
                'model_code' => 'sometimes|string|max:100',
                'variant'    => 'nullable|string|max:100',
                'capacity'   => 'nullable|string|max:100',
                'status_id'  => 'nullable|exists:status_lookup,status_id',
            ]);

            $model->update($validated);

            return $this->success($model->load('brand'), 'Model updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update model: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $model = ModelLookup::findOrFail($id);
            $model->delete();
            return $this->success(null, 'Model deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete model: ' . $e->getMessage(), 500);
        }
    }
}
