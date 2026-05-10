<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class ExtendSanctumTokenExpiration
{
    /**
     * Slide Sanctum personal access token expiry on each authenticated API request
     * so it matches configured inactivity / session timeout.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user) {
            return $response;
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return $response;
        }

        $minutes = SystemSetting::sessionTimeoutMinutes();
        $token->forceFill(['expires_at' => now()->addMinutes($minutes)])->save();

        return $response;
    }
}
