<?php

namespace App\Http\Controllers\Api;


use App\Mail\LoginOtpMail;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use App\Models\User;
use App\Models\AuditTrail;
use App\Models\SystemSetting;
use App\Services\LoginCaptchaService;
use App\Services\LoginOtpIssuer;
use App\Services\TwoFactorPolicy;
use App\Services\UnauthorizedAccessLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const LOGIN_DECAY_SECONDS = 900;

    /**
     * Wrong guesses allowed against one issued login code before it is burned.
     *
     * A six-digit code is a 900,000 keyspace living for ten minutes. Per-IP
     * throttling cannot cap that -- the attacker picks the IP, and a few hundred
     * proxies buy enough guesses to make it a real chance. Capping attempts per
     * issued code is source-address independent, so it holds however many
     * addresses they rotate through.
     */
    private const MAX_OTP_ATTEMPTS = 5;

    private function issueAuthToken(User $user): string
    {
        $minutes = SystemSetting::sessionTimeoutMinutes();

        return $user->createToken('auth-token', ['*'], now()->addMinutes($minutes))->plainTextToken;
    }

    private function loginCredentialsThrottleKey(Request $request): string
    {
        return 'login-credentials:' . sha1(strtolower((string) $request->input('email')) . '|' . $request->ip());
    }

    private function loginCaptchaThrottleKey(Request $request): string
    {
        return 'login-captcha:' . sha1(strtolower((string) $request->input('email')) . '|' . $request->ip());
    }

    /**
     * Record that a second-factor code was issued and the login is pending.
     *
     * Deliberately NOT logLoginAttempt(): that routes every unsuccessful call
     * into an `login_failed` audit row, and issuing a code is not a failure.
     * Using it here would stamp a bogus failed login on the audit trail for
     * every successful admin sign-in, drowning the signal an admin reviewing
     * "who has been failing to get in" actually needs.
     *
     * It still has to be recorded somewhere. Someone holding a stolen password
     * can trigger codes at will, and that is the highest-signal event in an
     * account-takeover investigation -- unlogged, it leaves no trace anywhere
     * but the victim's inbox.
     */
    private function logOtpIssued(Request $request, User $user, bool $delivered): void
    {
        try {
            LoginActivity::create([
                'user_id' => $user->user_id,
                'username_attempt' => Str::limit((string) $request->input('email'), 100, ''),
                // Not a success: no token was issued and the login is unfinished.
                'success' => false,
                'failure_reason' => $delivered
                    ? 'Login code issued; awaiting verification.'
                    : 'Login code could not be delivered.',
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 2000, ''),
                'created_at' => now(),
            ]);

            AuditTrail::create([
                'user_id'    => $user->user_id,
                'action'     => $delivered ? 'login_code_issued' : 'login_code_undeliverable',
                'table_name' => 'security',
                'record_id'  => $user->user_id,
                'old_values' => null,
                'new_values' => ['account' => $user->email],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);
        } catch (\Throwable $e) {
            // A failed log must never block a legitimate login.
            Log::warning('login_otp_issue_log_failed', ['error' => $e->getMessage()]);
        }
    }

    private function logLoginAttempt(Request $request, ?User $user, bool $success, ?string $failureReason = null): void
    {
        try {
            LoginActivity::create([
                'user_id' => $user?->user_id,
                // DB column is `username_attempt` for legacy reasons; we now store email attempts here.
                'username_attempt' => Str::limit((string) $request->input('email'), 100, ''),
                'success' => $success,
                'failure_reason' => $failureReason ? Str::limit($failureReason, 250, '') : null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 2000, ''),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('login_activity_log_failed', ['error' => $e->getMessage()]);
        }

        if ($success) {
            return;
        }

        // Two distinct records, because they answer different questions.
        // An unrecognized email is a stranger probing the system: logged without
        // the attempted address, by long-standing policy. A failure on a real
        // account is attributable, so it lands in the audit trail against that
        // account -- an admin reviewing "who has been failing to get in" needs
        // to see these, not just the login_activities table.
        if (! $user) {
            app(UnauthorizedAccessLogger::class)->logUnrecognizedAccount($request);

            return;
        }

        try {
            AuditTrail::create([
                'user_id'    => $user->user_id,
                'action'     => 'login_failed',
                'table_name' => 'security',
                'record_id'  => $user->user_id,
                'old_values' => null,
                'new_values' => [
                    'account' => $user->email,
                    'reason'  => $failureReason ? Str::limit($failureReason, 250, '') : 'Invalid credentials.',
                ],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('login_failed_audit_log_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * CAPTCHA challenge for login (no auth required).
     */
    public function loginChallenge(LoginCaptchaService $captcha)
    {
        return response()->json([
            'success' => true,
            'data' => $captcha->createChallenge(),
            'max_login_attempts' => SystemSetting::maxLoginAttempts(),
        ]);
    }

    /**
     * Login user (bcrypt password check, rate limit, CAPTCHA, activity log).
     */
    public function login(Request $request, LoginCaptchaService $captcha)
    {
        $request->validate([
            'email' => 'required|email|max:100',
            'password' => 'required|string|max:255',
            'captcha_token' => 'required|string',
            'captcha_answer' => 'required|string|max:10',
        ]);

        $credentialsKey = $this->loginCredentialsThrottleKey($request);
        $captchaKey = $this->loginCaptchaThrottleKey($request);
        $maxAttempts = SystemSetting::maxLoginAttempts();
        if (RateLimiter::tooManyAttempts($credentialsKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($credentialsKey);
            $this->logLoginAttempt(
                $request,
                User::where('email', $request->input('email'))->first(),
                false,
                'Account temporarily locked (too many attempts).'
            );

            throw ValidationException::withMessages([
                'email' => [
                    'Too many login attempts. Try again in ' . ceil($seconds / 60) . ' minute(s).',
                ],
            ]);
        }

        if (! $captcha->verify($request->input('captcha_token'), $request->input('captcha_answer'))) {
            RateLimiter::hit($captchaKey, self::LOGIN_DECAY_SECONDS);
            // Resolve the account so a real user failing the bot check is filed
            // against their own record rather than as an unrecognized stranger.
            // An unknown email here still falls through to the anonymous path.
            $this->logLoginAttempt(
                $request,
                User::where('email', $request->input('email'))->first(),
                false,
                'Invalid CAPTCHA.'
            );

            throw ValidationException::withMessages([
                'captcha_answer' => ['Security check failed. Refresh the challenge and try again.'],
            ]);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password_hash)) {
            RateLimiter::hit($credentialsKey, self::LOGIN_DECAY_SECONDS);
            $this->logLoginAttempt($request, $user, false, 'Invalid credentials.');

            $attemptsAfter = RateLimiter::attempts($credentialsKey);
            $remaining = max(0, $maxAttempts - $attemptsAfter);

            return response()->json([
                'message' => 'The provided credentials are incorrect.',
                'errors' => [
                    'email' => ['Invalid email or password.'],
                ],
                'login_attempts_remaining' => $remaining,
            ], 422);
        }

        if ($user->status_id !== 1) {
            RateLimiter::hit($credentialsKey, self::LOGIN_DECAY_SECONDS);
            $this->logLoginAttempt($request, $user, false, 'Inactive account.');

            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact administrator.'],
            ]);
        }

        RateLimiter::clear($credentialsKey);
        RateLimiter::clear($captchaKey);

        $policy = app(TwoFactorPolicy::class);

        if ($policy->requiresRecoveryEmail($user)) {
            // The account must clear a second factor but has nowhere to receive
            // it. Collect an address rather than mailing into the void -- for an
            // admin, refusing here would lock the system's owner out of it with
            // no self-service way back in.
            return response()->json([
                'requires_recovery_email' => true,
                'email'   => $user->email,
                'message' => 'Add a personal recovery email to continue signing in.',
            ]);
        }

        if ($policy->requiresOtp($user)) {
            // Recorded whether or not delivery succeeds. Someone holding a
            // stolen password can trigger codes at will, and that is the single
            // highest-signal event in an account-takeover investigation --
            // without this it would leave no trace but the victim's inbox.
            // Logged as unsuccessful because the password was right but the
            // login is not finished; calling it a success would make the
            // activity log claim an authentication that never completed.
            if (! app(LoginOtpIssuer::class)->issue($user)) {
                $this->logOtpIssued($request, $user, false);

                // Fail closed. No code was delivered, so no code can be entered.
                return response()->json([
                    'message' => 'We could not send your login code to '
                        . $user->recovery_email . '. Contact your administrator.',
                ], 503);
            }

            $this->logOtpIssued($request, $user, true);

            return response()->json([
                'requires_otp' => true,
                'email'   => $user->email,
                'message' => 'A login code has been sent to your recovery email.',
            ]);
        }

        $token = $this->issueAuthToken($user);

        $this->logLoginAttempt($request, $user, true, null);

        AuditTrail::create([
            'user_id' => $user->user_id,
            'action' => 'login',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load('role', 'status'),
            'token' => $token,
            'session_timeout_minutes' => SystemSetting::sessionTimeoutMinutes(),
            'requires_otp' => false,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|numeric',
        ]);

        $otp = (string) $request->input('otp');

        // The stored value is a hash, so the code cannot be matched in the
        // query any more. Resolve the account first, then compare in PHP.
        $user = User::where('email', $request->email)->first();

        $pending = $user
            && filled($user->otp_code)
            && $user->otp_expires_at
            && $user->otp_expires_at->isFuture();

        if (! $pending || ! Hash::check($otp, $user->otp_code)) {
            if ($pending) {
                // Only count guesses against a live code. Attempts on an
                // already-burned or expired code must not accumulate, or the
                // next legitimate code would start with a spent budget.
                $attempts = (int) $user->otp_attempts + 1;

                $user->fill([
                    'otp_attempts' => $attempts,
                    // Burn the code outright at the cap. Leaving it live but
                    // refusing guesses would let an attacker simply wait out the
                    // limiter; this is the defence that holds regardless of how
                    // many source addresses they rotate through.
                    'otp_code'     => $attempts >= self::MAX_OTP_ATTEMPTS ? null : $user->otp_code,
                ])->save();
            }

            // One message for every failure mode: a caller must not be able to
            // tell "no such account" from "wrong code" from "expired" from
            // "burned".
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        $user->update(['otp_code' => null, 'otp_expires_at' => null, 'otp_attempts' => 0]);

        $token = $this->issueAuthToken($user);
        $this->logLoginAttempt($request, $user, true);

        AuditTrail::create([
            'user_id' => $user->user_id,
            'action' => 'login',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'user' => $user->load('role', 'status'),
            'session_timeout_minutes' => SystemSetting::sessionTimeoutMinutes(),
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        AuditTrail::create([
            'user_id' => $request->user()->user_id,
            'action' => 'logout',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get current user — includes location enforcement data for scan modules.
     */
    public function user(Request $request)
    {
        $user = $request->user()->load('location', 'role', 'status');

        $enforcedId  = $user->getEnforcedLocationId();
        $allowedIds  = $user->getAllowedLocationIds();

        $allowedLocations = [];
        if (! $user->hasStrictLocation()) {
            $allowedLocations = \App\Models\Location::whereIn('location_id', $allowedIds)
                ->where('is_active', 1)
                ->get(['location_id', 'location_name', 'location_type', 'location_code'])
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge($user->toArray(), [
                'enforced_location_id' => $enforcedId,
                'allowed_location_ids' => $allowedIds,
                'allowed_locations'    => $allowedLocations,
                'has_strict_location'  => $user->hasStrictLocation(),
                'primary_location'     => $user->location ? [
                    'location_id'   => $user->location->location_id,
                    'location_name' => $user->location->location_name,
                    'location_type' => $user->location->location_type,
                    'location_code' => $user->location->location_code,
                ] : null,
            ]),
        ]);
    }

    /**
     * Refresh token (optional)
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        $request->user()->currentAccessToken()->delete();
        $token = $this->issueAuthToken($user);

        return response()->json([
            'token' => $token,
            'session_timeout_minutes' => SystemSetting::sessionTimeoutMinutes(),
        ]);
    }

}
