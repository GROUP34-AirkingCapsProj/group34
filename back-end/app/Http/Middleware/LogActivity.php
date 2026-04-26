<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\AuditTrail;

class LogActivity
{
    /**
     * Handle an incoming request.
     * Logs user activity for audit purposes.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Only log if user is authenticated
        if ($request->user()) {
            try {
                AuditTrail::create([
                    'user_id' => $request->user()->user_id,
                    'action' => $request->method() . ' ' . $request->path(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Exception $e) {
                // Silently fail - don't break the request if logging fails
                \Log::warning('Failed to log activity: ' . $e->getMessage());
            }
        }

        return $response;
    }
}
