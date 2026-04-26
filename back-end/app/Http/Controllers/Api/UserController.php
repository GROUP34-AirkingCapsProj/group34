<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;  // ← ADD THIS
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;  // ← ADD THIS

class UserController extends Controller
{
    use ApiResponse;  // ← ADD THIS

    /** Built-in admin account keeps short passwords; all other users use min 8. */
    private function isPrimaryAdminUsername(?string $username): bool
    {
        return strcasecmp((string) $username, 'admin') === 0;
    }

    public function index(Request $request)
    {
        $query = User::with(['role', 'status', 'location']);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->has('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        // Filter by status
        if ($request->has('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        $perPage = $request->get('per_page', 10);
        $users = $query->paginate($perPage);

        // ✅ USING ApiResponse trait
        return $this->paginated($users, 'Users retrieved successfully');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:50',
                'last_name' => 'required|string|max:50',
                'email' => 'required|email|unique:users|max:100',
                'username' => 'required|string|unique:users|max:50',
                'password' => [
                    'required',
                    'string',
                    'max:255',
                    'confirmed',
                    Rule::when(
                        ! $this->isPrimaryAdminUsername($request->input('username')),
                        ['min:8'],
                        ['min:4']
                    ),
                ],
                'phone' => 'nullable|string|max:20',
                'role_id' => 'required|exists:roles,role_id',
                'location_id' => 'nullable|exists:locations,location_id',
                'status_id' => 'required|exists:status_lookup,status_id',
                'assigned_location_ids' => 'nullable|array',
                'assigned_location_ids.*' => 'integer|exists:locations,location_id',
            ]);

            // Ensure integer IDs (request may send strings)
            $validated['role_id'] = (int) $validated['role_id'];
            $validated['status_id'] = (int) $validated['status_id'];

            // Hash password
            $validated['password_hash'] = Hash::make($validated['password']);
            unset($validated['password']);
            unset($validated['password_confirmation']);

            $user = User::create($validated);

            // ✅ USING ApiResponse trait
            return $this->success(
                $user->load(['role', 'status', 'location']),
                'User created successfully',
                201
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create user: ' . $e->getMessage(), 500);
        }
    }

    public function show(User $user)
    {
        $user->load(['role', 'status', 'location']);

        // ✅ USING ApiResponse trait
        return $this->success($user, 'User retrieved successfully');
    }

    public function update(Request $request, User $user)
    {
        try {
            // Prevent user from changing their own role or status
            if ($request->user()->user_id === $user->user_id) {
                if ($request->has('role_id') || $request->has('status_id')) {
                    return $this->error('You cannot change your own role or status', 403);
                }
            }

            $validated = $request->validate([
                'first_name' => 'sometimes|required|string|max:50',
                'last_name' => 'sometimes|required|string|max:50',
                'email' => 'sometimes|required|email|unique:users,email,' . $user->user_id . ',user_id|max:100',
                'username' => 'sometimes|required|string|unique:users,username,' . $user->user_id . ',user_id|max:50',
                'phone' => 'nullable|string|max:20',
                'role_id' => 'sometimes|required|exists:roles,role_id',
                'location_id' => 'nullable|exists:locations,location_id',
                'status_id' => 'sometimes|required|exists:status_lookup,status_id',
                'assigned_location_ids' => 'nullable|array',
                'assigned_location_ids.*' => 'integer|exists:locations,location_id',
            ]);

            $user->update($validated);

            // ✅ USING ApiResponse trait
            return $this->success(
                $user->fresh()->load(['role', 'status', 'location']),
                'User updated successfully'
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update user: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Request $request, User $user)
    {
        try {
            // Prevent user from deleting themselves
            if ($request->user()->user_id === $user->user_id) {
                return $this->error('You cannot delete your own account', 403);
            }

            // Check if user has any related data
            // You can add more checks here based on your business rules
            
            $user->delete();

            // ✅ USING ApiResponse trait
            return $this->success(null, 'User deleted successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to delete user: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Change user password
     */
    public function changePassword(Request $request, User $user)
    {
        try {
            // Only allow users to change their own password or admins to change any
            if ($request->user()->user_id !== $user->user_id && $request->user()->role->role_name !== 'admin') {
                return $this->forbidden('You can only change your own password');
            }

            $validated = $request->validate([
                'current_password' => 'required_if:self,true|string',
                'new_password' => [
                    'required',
                    'string',
                    'max:255',
                    'confirmed',
                    Rule::when(
                        ! $this->isPrimaryAdminUsername($user->username),
                        ['min:8'],
                        ['min:4']
                    ),
                ],
            ]);

            // If user is changing their own password, verify current password
            if ($request->user()->user_id === $user->user_id) {
                if (!Hash::check($validated['current_password'], $user->password_hash)) {
                    return $this->error('Current password is incorrect', 422);
                }
            }

            $user->update([
                'password_hash' => Hash::make($validated['new_password'])
            ]);

            return $this->success(null, 'Password changed successfully');

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to change password: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update user status (activate/deactivate)
     */
    public function updateStatus(Request $request, User $user)
    {
        try {
            // Prevent user from changing their own status
            if ($request->user()->user_id === $user->user_id) {
                return $this->error('You cannot change your own status', 403);
            }

            $validated = $request->validate([
                'status_id' => 'required|exists:status_lookup,status_id',
            ]);

            $user->update($validated);

            return $this->success(
                $user->fresh()->load(['role', 'status', 'location']),
                'User status updated successfully'
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get users by location
     */
    public function byLocation($locationId)
    {
        try {
            $users = User::where('location_id', $locationId)
                ->with(['role', 'status', 'location'])
                ->get();

            return $this->success([
                'users' => $users,
                'count' => $users->count(),
            ], 'Location users retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve users: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get users by role
     */
    public function byRole($roleId)
    {
        try {
            $users = User::where('role_id', $roleId)
                ->with(['role', 'status', 'location'])
                ->get();

            return $this->success([
                'users' => $users,
                'count' => $users->count(),
            ], 'Role users retrieved successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to retrieve users: ' . $e->getMessage(), 500);
        }
    }
}