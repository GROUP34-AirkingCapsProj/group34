<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        try {
            $query = Role::orderBy('role_name');

            if ($request->has('search')) {
                $query->where('role_name', 'like', '%' . $request->search . '%');
            }

            return $this->success($query->get(), 'Roles retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve roles: ' . $e->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'role_name'   => 'required|string|max:100|unique:roles,role_name',
                'description' => 'nullable|string|max:255',
            ]);

            $role = Role::create($validated);

            return $this->success($role, 'Role created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create role: ' . $e->getMessage(), 500);
        }
    }

    public function show($id)
    {
        try {
            $role = Role::findOrFail($id);
            return $this->success($role, 'Role retrieved successfully');
        } catch (\Exception $e) {
            return $this->error('Role not found', 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $role = Role::findOrFail($id);

            $validated = $request->validate([
                'role_name'   => 'sometimes|string|max:100|unique:roles,role_name,' . $id . ',role_id',
                'description' => 'nullable|string|max:255',
            ]);

            $role->update($validated);

            return $this->success($role, 'Role updated successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update role: ' . $e->getMessage(), 500);
        }
    }

    public function destroy($id)
    {
        try {
            $role = Role::findOrFail($id);
            $role->delete();
            return $this->success(null, 'Role deleted successfully');
        } catch (\Exception $e) {
            return $this->error('Failed to delete role: ' . $e->getMessage(), 500);
        }
    }
}
