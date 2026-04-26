<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\StatusLookup;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StatusLookupController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = StatusLookup::orderBy('status_category')->orderBy('status_name');

            if ($request->has('category')) {
                $query->where('status_category', $request->category);
            }
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            return $this->success($query->get(), 'Status lookup retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve status lookup: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'status_name'     => 'required|string|max:100',
                'status_category' => 'required|string|max:100',
                'is_active'       => 'boolean',
            ]);

            $status = StatusLookup::create($validated);

            return $this->success($status, 'Status created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create status: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $status = StatusLookup::findOrFail($id);
            return $this->success($status, 'Status retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Status not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $status = StatusLookup::findOrFail($id);

            $validated = $request->validate([
                'status_name'     => 'sometimes|string|max:100',
                'status_category' => 'sometimes|string|max:100',
                'is_active'       => 'boolean',
            ]);

            $status->update($validated);

            return $this->success($status, 'Status updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update status: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $status = StatusLookup::findOrFail($id);
            $status->delete();
            return $this->success(null, 'Status deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete status: ' . $e->getMessage(), 500);
        }
    }
}
