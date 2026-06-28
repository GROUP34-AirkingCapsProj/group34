<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     */
    private function presentProfile($user): array
    {
        $email = (string) $user->email;
        $atPos = strrpos($email, '@');
        $local = $atPos === false ? $email : substr($email, 0, $atPos);

        return array_merge($user->load(['role', 'status', 'location'])->toArray(), [
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
        }
    }
}
