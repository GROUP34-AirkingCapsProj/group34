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

        // Only log write operations — GET/HEAD/OPTIONS are read-only and create noise
        $writeMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        if (! \in_array($request->method(), $writeMethods, true)) {
            return $response;
        }

        // Skip internal/infrastructure paths that are not business actions
        $skipPaths = [
            'api/logout',
            'api/refresh-token',
            'api/alerts',
            'api/barcode-scans',
            'api/activity-log',
            'sanctum/',
            '_ignition/',
            'telescope/',
        ];
        $path = ltrim($request->path(), '/');
        foreach ($skipPaths as $skip) {
            if (str_starts_with($path, $skip)) {
                return $response;
            }
        }

        // Only log successful responses (2xx range)
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        if ($request->user()) {
            try {
                AuditTrail::create([
                    'user_id'    => $request->user()->user_id,
                    'action'     => strtolower($request->method()) . '_' . str_replace('/', '_', $path),
                    'table_name' => null,
                    'record_id'  => null,
                    'old_values' => null,
                    'new_values' => null,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Exception $e) {
                \Log::warning('Failed to log activity: ' . $e->getMessage());
            }
        }

        return $response;
    }
}
