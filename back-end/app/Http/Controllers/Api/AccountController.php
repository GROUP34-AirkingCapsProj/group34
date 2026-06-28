<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    use ApiResponse;

    /** The single fixed email domain. The local-part is user-editable; this is not. */
    private const EMAIL_DOMAIN = '@airking.com';

    /**
     * Serialize the authenticated user for the settings page, splitting email
     * into its editable local-part and the fixed domain.
     *
     * Note: this mutates and returns the passed model (eager-loads relations via
     * loadMissing). The resulting array includes avatar_url plus the role, status,
     * and location relations, alongside email_local/email_domain.
     */
    private function presentProfile(User $user): array
    {
        $email = (string) $user->email;
        $atPos = strrpos($email, '@');
        $local = $atPos === false ? $email : substr($email, 0, $atPos);

        return array_merge($user->loadMissing(['role', 'status', 'location'])->toArray(), [
            'email_local'  => $local,
            'email_domain' => self::EMAIL_DOMAIN,
        ]);
    }

    public function show(Request $request)
    {
        try {
            return $this->success($this->presentProfile($request->user()), 'Profile retrieved');
        } catch (\Exception $e) {
            return $this->error('Failed to load profile: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:50',
                'last_name'  => 'required|string|max:50',
                'phone'      => 'nullable|string|max:20',
                'address'    => 'nullable|string|max:255',
            ]);

            $user = $request->user();
            $user->update($validated);

            return $this->success($this->presentProfile($user->fresh()), 'Profile updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update profile: ' . $e->getMessage(), 500);
        }
    }

    public function uploadAvatar(Request $request)
    {
        try {
            $request->validate([
                'avatar' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
            ]);

            $user = $request->user();
            $oldPath = $user->avatar_path;

            // Store the new file first; only delete the old one once this succeeds.
            $newPath = $request->file('avatar')->store('avatars', 'public');

            $user->update(['avatar_path' => $newPath]);

            if ($oldPath && $oldPath !== $newPath) {
                Storage::disk('public')->delete($oldPath);
            }

            return $this->success($this->presentProfile($user->fresh()), 'Avatar updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to upload avatar: ' . $e->getMessage(), 500);
        }
    }

    public function deleteAvatar(Request $request)
    {
        try {
            $user = $request->user();

            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
                $user->update(['avatar_path' => null]);
            }

            return $this->success($this->presentProfile($user->fresh()), 'Avatar removed');
        } catch (\Exception $e) {
            return $this->error('Failed to remove avatar: ' . $e->getMessage(), 500);
        }
    }

    public function updatePassword(Request $request)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'current_password' => ['required', 'string'],
                'new_password'     => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            ]);

            if (! Hash::check($validated['current_password'], $user->password_hash)) {
                return $this->error('Current password is incorrect', 422);
            }

            $user->update(['password_hash' => Hash::make($validated['new_password'])]);

            return $this->success(null, 'Password changed successfully');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to change password: ' . $e->getMessage(), 500);
        }
    }

    public function updateEmail(Request $request)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                // Local-part only: letters, digits, dot, underscore, %, +, -. No '@'.
                'email_local'      => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._%+\-]+$/'],
                'current_password' => ['required', 'string'],
            ]);

            if (! Hash::check($validated['current_password'], $user->password_hash)) {
                return $this->error('Current password is incorrect', 422);
            }

            $validated['email_local'] = strtolower($validated['email_local']);
            $newEmail = $validated['email_local'] . self::EMAIL_DOMAIN;

            $exists = User::where('email', $newEmail)
                ->where('user_id', '!=', $user->user_id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'email_local' => ['That email address is already taken.'],
                ]);
            }

            try {
                $user->update(['email' => $newEmail]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                return $this->validationError(['email_local' => ['That email address is already taken.']]);
            }

            return $this->success($this->presentProfile($user->fresh()), 'Email updated');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        } catch (\Exception $e) {
            return $this->error('Failed to update email: ' . $e->getMessage(), 500);
        }
    }
}
