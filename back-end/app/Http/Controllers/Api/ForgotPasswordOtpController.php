<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Forgot-password OTP flow only.
 * Does not touch login 2FA (AuthController + users.otp_code).
 */
class ForgotPasswordOtpController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    /**
     * POST /forgot-password
     * Generate a 6-digit OTP, hash + store (10 min), email it.
     *
     * The submitted address is the account's RECOVERY email, not its login
     * email. Reset codes only ever go to the recovery address recorded in the
     * system; there is no way to nominate a different destination here, and no
     * fallback to the company inbox the employee is locked out of. Accounts
     * with no recovery address on file cannot self-recover and must be reset
     * by an admin through the authorization-code flow.
     */
    public function forgot(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:100',
        ]);

        $email = strtolower(trim($validated['email']));

        // Deliberate product decision: an unrecognised address is named as such
        // rather than answered with a uniform "if it exists" message. This tells
        // a user who mistyped straight away, at the cost of letting someone probe
        // which personal inboxes belong to staff. The route's throttle (8/min,
        // routes/api.php) is what limits bulk probing. Revert to a uniform
        // response here if this system is ever exposed to the public internet.
        $user = User::whereNotNull('recovery_email')
            ->whereRaw('LOWER(recovery_email) = ?', [$email])
            ->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => [
                    'That email is not registered as a recovery email. Check the address, '
                    . 'or contact your administrator to have your password reset.',
                ],
            ]);
        }

        $plainOtp = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(self::OTP_TTL_MINUTES);

        // Email first — do not keep an OTP row if delivery fails.
        try {
            Mail::to($user->recovery_email)->send(new PasswordResetOtpMail($plainOtp));
        } catch (\Throwable $e) {
            Log::error('password_reset_otp_mail_failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['Could not send the reset code by email. Please try again later.'],
            ]);
        }

        DB::transaction(function () use ($email, $plainOtp, $expiresAt) {
            // Replace any prior unused challenges for this email (single active OTP).
            PasswordResetOtp::query()->where('email', $email)->delete();

            PasswordResetOtp::create([
                'email' => $email,
                'otp_hash' => Hash::make($plainOtp),
                'expires_at' => $expiresAt,
                'verified_token' => null,
            ]);
        });

        return response()->json([
            'message' => 'A 6-digit reset code has been sent to your recovery email address.',
            'expires_in_minutes' => self::OTP_TTL_MINUTES,
        ]);
    }

    /**
     * POST /verify-reset-otp
     * Validate OTP hash + expiry, then issue a single-use reset_token.
     */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:100',
            'otp' => 'required|string|size:6',
        ]);

        $email = strtolower(trim($validated['email']));
        $otp = $validated['otp'];

        $row = PasswordResetOtp::query()
            ->where('email', $email)
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired reset code.'],
            ]);
        }

        if ($row->isVerified()) {
            throw ValidationException::withMessages([
                'otp' => ['This reset code has already been used. Request a new one.'],
            ]);
        }

        if ($row->isExpired()) {
            $row->delete();
            throw ValidationException::withMessages([
                'otp' => ['This reset code has expired. Request a new one.'],
            ]);
        }

        if (! Hash::check($otp, $row->otp_hash)) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired reset code.'],
            ]);
        }

        $resetToken = Str::random(64);

        $row->forceFill([
            // Invalidate the OTP for further use; only reset_token may proceed.
            'otp_hash' => Hash::make(Str::random(32)),
            'verified_token' => $resetToken,
        ])->save();

        return response()->json([
            'message' => 'Code verified. You may now set a new password.',
            'reset_token' => $resetToken,
        ]);
    }

    /**
     * POST /reset-password
     * Require reset_token (not OTP) to update password, then delete the OTP row.
     */
    public function reset(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:100',
            'reset_token' => 'required|string|size:64',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $email = strtolower(trim($validated['email']));

        $row = PasswordResetOtp::query()
            ->where('email', $email)
            ->where('verified_token', $validated['reset_token'])
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'reset_token' => ['Invalid or expired reset token.'],
            ]);
        }

        if ($row->isExpired()) {
            $row->delete();
            throw ValidationException::withMessages([
                'reset_token' => ['This reset token has expired. Request a new code.'],
            ]);
        }

        // Keyed the same way forgot() issued it: the challenge row's email column
        // holds the recovery address, so the account resolves through it too.
        $user = User::whereNotNull('recovery_email')
            ->whereRaw('LOWER(recovery_email) = ?', [$email])
            ->first();
        if (! $user) {
            $row->delete();
            throw ValidationException::withMessages([
                'email' => ['Unable to reset password for this account.'],
            ]);
        }

        DB::transaction(function () use ($user, $row, $validated) {
            $user->update([
                'password_hash' => Hash::make($validated['password']),
                // The user has now chosen their own password; release the gate.
                'must_change_password' => false,
            ]);

            // Single-use: remove the challenge entirely after success.
            $row->delete();
        });

        return response()->json([
            'message' => 'Your password has been reset. You can sign in with your new password.',
        ]);
    }
}
