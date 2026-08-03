<?php

namespace App\Http\Controllers\Api;


use App\Mail\SendOTPMail;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use App\Models\User;
use App\Models\AuditTrail;
use App\Models\SystemSetting;
use App\Services\LoginCaptchaService;
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

        // Only unrecognized emails (no matching user) are flagged unauthorized.
        // Wrong passwords on existing accounts stay in login_activities only.
        if (! $success && ! $user) {
            app(UnauthorizedAccessLogger::class)->logUnrecognizedAccount($request);
        }
    }

    /**
     * Register new user
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|unique:users,username|max:50',
            'email' => 'required|email|unique:users,email|max:100',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'first_name' => 'required|max:50',
            'last_name' => 'required|max:50',
            'role_id' => 'required|exists:roles,role_id',
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password_hash' => Hash::make($validated['password']),
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'role_id' => $validated['role_id'],
            'status_id' => 1,
        ]);

        $token = $this->issueAuthToken($user);

        AuditTrail::create([
            'user_id' => $user->user_id,
            'action' => 'register',
            'table_name' => 'users',
            'record_id' => $user->user_id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user->load('role', 'status'),
            'token' => $token,
            'session_timeout_minutes' => SystemSetting::sessionTimeoutMinutes(),
        ], 201);
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
            $this->logLoginAttempt($request, null, false, 'Account temporarily locked (too many attempts).');

            throw ValidationException::withMessages([
                'email' => [
                    'Too many login attempts. Try again in ' . ceil($seconds / 60) . ' minute(s).',
                ],
            ]);
        }

        if (! $captcha->verify($request->input('captcha_token'), $request->input('captcha_answer'))) {
            RateLimiter::hit($captchaKey, self::LOGIN_DECAY_SECONDS);
            $this->logLoginAttempt($request, null, false, 'Invalid CAPTCHA.');

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

        if (SystemSetting::twoFactorAuthEnabled()) {
            $otp = (string) random_int(100000, 999999);

            $user->update([
                'otp_code' => $otp,
                'otp_expires_at' => now()->addMinutes(10),
            ]);

            Mail::to($user->email)->send(new SendOTPMail($otp));

            return response()->json([
                'requires_otp' => true,
                'email' => $user->email,
                'message' => 'OTP has been sent to your email.',
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

        $user = User::where('email', $request->email)
            ->where('otp_code', $otp)
            ->where('otp_expires_at', '>', now())
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        $user->update(['otp_code' => null, 'otp_expires_at' => null]);

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
