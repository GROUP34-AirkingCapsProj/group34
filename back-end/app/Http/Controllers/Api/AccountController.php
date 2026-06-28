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
        return $this->success($this->presentProfile($request->user()), 'Profile retrieved');
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
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return $this->success($this->presentProfile($user->fresh()), 'Avatar removed');
    }
}
