<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use App\Models\User;
use App\Services\PasswordAuditLogger;
use App\Services\TemporaryCredentialIssuer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use ApiResponse;

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
                // Personal inbox for password recovery. `different:email` stops an
                // admin pasting the company address into both fields, which would
                // silently defeat the whole mechanism. Both `unique` rules are
                // required because forgot-password looks accounts up BY this
                // address: sharing it with another recovery address, or with
                // any account's login address, would resolve the wrong account.
                'recovery_email' => 'required|email|max:100|different:email'
                    . '|unique:users,recovery_email|unique:users,email',
                'username' => 'required|string|unique:users|max:50',
                'phone' => 'nullable|string|max:20',
                'role_id' => 'required|exists:roles,role_id',
                'location_id' => 'nullable|exists:locations,location_id',
                'status_id' => 'required|exists:status_lookup,status_id',
                'location_ids' => 'nullable|array',
                'location_ids.*' => 'integer|exists:locations,location_id',
            ]);

            // Ensure integer IDs (request may send strings)
            $validated['role_id'] = (int) $validated['role_id'];
            $validated['status_id'] = (int) $validated['status_id'];

            $locationIds = $validated['location_ids'] ?? null;
            unset($validated['location_ids']);

            // A placeholder only. The row needs a non-null hash to be insertable;
            // the issuer overwrites it a few lines down with the password that is
            // actually mailed. Nobody can authenticate with this value -- it is a
            // hash of random bytes that are immediately discarded.
            $validated['password_hash'] = Hash::make(bin2hex(random_bytes(32)));

            $user = User::create($validated);

            // Sync location assignments via user_locations pivot
            if ($locationIds !== null) {
                $user->locations()->sync($locationIds);
            }

            $emailed = app(TemporaryCredentialIssuer::class)->issue($user, $request->user());

            return $this->success(
                [
                    'user'                => $user->fresh()->load(['role', 'status', 'location', 'locations']),
                    'credentials_emailed' => $emailed,
                ],
                $emailed
                    ? 'User created. Login details have been emailed to their recovery address.'
                    : 'User created, but the credentials email could not be sent. Use Resend to try again.',
                201
            );

        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to create user: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Re-issue and re-send the temporary credentials for an account that has
     * never completed its first login.
     *
     * Restricted to `must_change_password` accounts on purpose. Resending mints
     * a NEW password and invalidates the old one -- harmless for someone who
     * never received the first email, but for a user who already chose their own
     * password it would silently lock them out. Those accounts use the
     * forgot-password flow, which is what it is for.
     */
    public function resendCredentials(Request $request, User $user)
    {
        try {
            if ($user->trashed()) {
                return $this->error('Cannot issue credentials for an archived account.', 422);
            }

            if (! $user->must_change_password) {
                return $this->error(
                    'This user has already set their own password. Ask them to use "Forgot password" instead.',
                    422
                );
            }

            // `recovery_email` is only required for accounts created through
            // store(). Seeded and legacy rows predate the column and can be null
            // while still carrying the default must_change_password = true, so
            // they reach this point looking eligible. Without this check the
            // issuer would rotate the password and only then discover there is
            // nowhere to send it -- destroying a working credential and blaming
            // the mail server for it.
            if (! $user->recovery_email) {
                return $this->error(
                    'This account has no recovery email on file. Add one via Edit before resending credentials.',
                    422
                );
            }

            $emailed = app(TemporaryCredentialIssuer::class)->issue($user, $request->user());

            return $this->success(
                ['credentials_emailed' => $emailed],
                $emailed
                    ? 'New login details have been emailed to their recovery address.'
                    : 'The credentials email could not be sent. Check the mail configuration and try again.',
            );
        } catch (\Exception $e) {
            return $this->error('Failed to resend credentials: ' . $e->getMessage(), 500);
        }
    }

    public function show(User $user)
    {
        $user->load(['role', 'status', 'location', 'locations']);

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
                'recovery_email' => 'sometimes|email|max:100|different:email'
                    . '|unique:users,recovery_email,' . $user->user_id . ',user_id'
                    . '|unique:users,email,' . $user->user_id . ',user_id',
                'username' => 'sometimes|required|string|unique:users,username,' . $user->user_id . ',user_id|max:50',
                'phone' => 'nullable|string|max:20',
                'role_id' => 'sometimes|required|exists:roles,role_id',
                'location_id' => 'nullable|exists:locations,location_id',
                'status_id' => 'sometimes|required|exists:status_lookup,status_id',
                'location_ids' => 'nullable|array',
                'location_ids.*' => 'integer|exists:locations,location_id',
            ]);

            $locationIds = array_key_exists('location_ids', $validated) ? $validated['location_ids'] : false;
            unset($validated['location_ids']);

            $user->update($validated);

            // Sync location assignments via user_locations pivot
            if ($locationIds !== false) {
                $user->locations()->sync($locationIds ?? []);
            }

            return $this->success(
                $user->fresh()->load(['role', 'status', 'location', 'locations']),
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
                'new_password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            ]);

            // If user is changing their own password, verify current password
            if ($request->user()->user_id === $user->user_id) {
                if (!Hash::check($validated['current_password'], $user->password_hash)) {
                    return $this->error('Current password is incorrect', 422);
                }
            }

            // Self-service change releases the gate. An admin setting someone
            // else's password is issuing a temporary credential, so it re-arms
            // the gate — same reasoning as a freshly created account.
            $isSelf = $request->user()->user_id === $user->user_id;

            $user->update([
                'password_hash' => Hash::make($validated['new_password']),
                'must_change_password' => ! $isSelf,
            ]);

            app(PasswordAuditLogger::class)->log(
                $isSelf ? PasswordAuditLogger::SELF_CHANGE : PasswordAuditLogger::ADMIN_CHANGE,
                $user,
                $isSelf
                    ? ['method' => 'self_service']
                    : ['method' => 'admin_set_temporary', 'must_change_password' => true],
                $request->user(),
            );

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

    public function archived(Request $request)
    {
        $query = User::onlyTrashed()->with(['role', 'status', 'location']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'like', "%{$s}%")
                  ->orWhere('last_name', 'like', "%{$s}%")
                  ->orWhere('username', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%");
            });
        }

        return $this->paginated(
            $query->orderBy('deleted_at', 'desc')->paginate($request->integer('per_page', 15)),
            'Archived users retrieved'
        );
    }

    public function restore($id)
    {
        $user = User::onlyTrashed()->find($id);
        if (! $user) {
            return $this->notFound('Archived user not found');
        }
        $user->restore();
        return $this->success($user->fresh()->load(['role', 'status', 'location']), 'User restored successfully');
    }

    public function forceDestroy($id)
    {
        $user = User::onlyTrashed()->find($id);
        if (! $user) {
            return $this->notFound('Archived user not found');
        }
        $user->forceDelete();
        return $this->success(null, 'User permanently deleted');
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

}