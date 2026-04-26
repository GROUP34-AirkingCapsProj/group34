<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use App\Models\User;
use App\Models\AuditTrail;
use App\Services\LoginCaptchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 900;

    private function loginThrottleKey(Request $request): string
    {
        return 'login:' . sha1(strtolower((string) $request->input('email')) . '|' . $request->ip());
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
    }

    /**
     * Register new user
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|unique:users,username|max:50',
            'email' => 'required|email|unique:users,email|max:100',
            'password' => 'required|min:8|confirmed',
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

        $token = $user->createToken('auth-token')->plainTextToken;

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

        $throttleKey = $this->loginThrottleKey($request);
        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $this->logLoginAttempt($request, null, false, 'Account temporarily locked (too many attempts).');

            throw ValidationException::withMessages([
                'email' => [
                    'Too many login attempts. Try again in ' . ceil($seconds / 60) . ' minute(s).',
                ],
            ]);
        }

        if (! $captcha->verify($request->input('captcha_token'), $request->input('captcha_answer'))) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);
            $this->logLoginAttempt($request, null, false, 'Invalid CAPTCHA.');

            throw ValidationException::withMessages([
                'captcha_answer' => ['Security check failed. Refresh the challenge and try again.'],
            ]);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password_hash)) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);
            $this->logLoginAttempt($request, $user, false, 'Invalid credentials.');

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status_id !== 1) {
            RateLimiter::hit($throttleKey, self::LOGIN_DECAY_SECONDS);
            $this->logLoginAttempt($request, $user, false, 'Inactive account.');

            throw ValidationException::withMessages([
                'email' => ['Your account is inactive. Please contact administrator.'],
            ]);
        }

        RateLimiter::clear($throttleKey);
        $token = $user->createToken('auth-token')->plainTextToken;

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
     * Get current user
     */
    public function user(Request $request)
    {
        return response()->json([
            'user' => $request->user()->load('role', 'status'),
        ]);
    }

    /**
     * Refresh token (optional)
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        $request->user()->currentAccessToken()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
        ]);
    }

    /**
     * Forgot password — throttled; audit logged.
     */
    public function forgotPassword(Request $request)
    {
        $ipKey = 'forgot:' . sha1($request->ip() . '|' . (string) $request->input('email'));
        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many reset attempts. Try again later.'],
            ]);
        }
        RateLimiter::hit($ipKey, 3600);

        $validated = $request->validate([
            'email' => 'required|email|max:100',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['We could not find a matching account.'],
            ]);
        }

        $temporaryPassword = Str::random(10);
        $user->password_hash = Hash::make($temporaryPassword);
        $user->save();

        AuditTrail::create([
            'user_id' => $user->user_id,
            'action' => 'password_reset',
            'table_name' => 'users',
            'record_id' => $user->user_id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Password has been reset. Use the temporary password to log in and then change it.',
            'temporary_password' => $temporaryPassword,
        ]);
    }
}
