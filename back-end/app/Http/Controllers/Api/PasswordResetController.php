<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    private const CODE_PREFIX = 'RST-';

    private const CODE_RANDOM_LEN = 8;

    private const CODE_TTL_MINUTES = 1440;

    public static function normalizeCode(string $raw): string
    {
        $s = strtoupper(preg_replace('/\s+/', '', trim($raw)));

        return $s;
    }

    /**
     * Admin issues a one-time reset code for a user (System Configuration → Manual Password Reset).
     */
    public function issue(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,user_id',
        ]);

        $user = User::where('user_id', $validated['user_id'])->firstOrFail();

        $plain = self::CODE_PREFIX . strtoupper(Str::random(self::CODE_RANDOM_LEN));
        $normalized = self::normalizeCode($plain);
        $expiresAt = now()->addMinutes(self::CODE_TTL_MINUTES);

        DB::transaction(function () use ($user, $normalized, $expiresAt, $plain) {
            PasswordResetCode::query()
                ->where('user_id', $user->user_id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now()]);

            PasswordResetCode::create([
                'user_id' => $user->user_id,
                'code_hash' => Hash::make($normalized),
                'code_plain' => $plain,
                'expires_at' => $expiresAt,
            ]);
        });

        return response()->json([
            'message' => 'Reset code issued. Share it with the user through a secure channel.',
            'code' => $plain,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Public: set a new password using email + admin-issued code.
     */
    public function complete(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:100',
            'code' => 'required|string|max:40',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $user = User::where('email', $validated['email'])->first();
        $normalized = self::normalizeCode($validated['code']);

        if (! $user || ! str_starts_with($normalized, self::CODE_PREFIX)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or authorization code.'],
            ]);
        }

        $row = PasswordResetCode::query()
            ->where('user_id', $user->user_id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $row || ! Hash::check($normalized, $row->code_hash)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid email or authorization code.'],
            ]);
        }

        $user->update([
            'password_hash' => Hash::make($validated['password']),
            // The admin issues the code, but the employee chooses the password
            // here — so this releases the gate, same as every other self-chosen
            // password. Without this, CheckPasswordChange would immediately
            // re-gate anyone who recovered through this flow.
            'must_change_password' => false,
        ]);

        $row->forceFill(['used_at' => now()])->save();

        PasswordResetCode::query()
            ->where('user_id', $user->user_id)
            ->whereNull('used_at')
            ->where('id', '!=', $row->id)
            ->update(['used_at' => now()]);

        return response()->json([
            'message' => 'Your password has been reset. You can sign in with your new password.',
        ]);
    }

    /**
     * Admin: list unused, non-expired reset codes (for System Configuration UI).
     */
    public function active()
    {
        $rows = PasswordResetCode::query()
            ->with('user')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get();

        $data = $rows->map(function (PasswordResetCode $row) {
            $u = $row->user;
            $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
            if ($name === '') {
                $name = $u->username ?? 'User';
            }

            return [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'name' => $name,
                'email' => $u->email,
                'code' => $row->code_plain ?? '—',
                'generated_at' => $row->created_at?->toIso8601String(),
                'expires_at' => $row->expires_at?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Admin: invalidate an active reset code.
     */
    public function revoke(PasswordResetCode $passwordResetCode)
    {
        if ($passwordResetCode->used_at !== null) {
            return response()->json(['message' => 'This code is no longer active.'], 422);
        }

        if ($passwordResetCode->expires_at <= now()) {
            return response()->json(['message' => 'This code has expired.'], 422);
        }

        $passwordResetCode->forceFill(['used_at' => now()])->save();

        return response()->json(['message' => 'Code revoked.']);
    }
}
