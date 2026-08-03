<?php

namespace App\Services;

use App\Models\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UnauthorizedAccessLogger
{
    /**
     * Log an unrecognized (non-existent) account attempting to access the system.
     * Never stores the attempted email — UI/API always show "unknown".
     */
    public function logUnrecognizedAccount(Request $request, string $reason = 'Unrecognized account attempted to access the system.'): void
    {
        try {
            AuditTrail::create([
                'user_id' => null,
                'action' => 'unauthorized_access',
                'table_name' => 'security',
                'record_id' => null,
                'old_values' => null,
                'new_values' => [
                    'attempt_type' => 'unrecognized_account',
                    'reason' => Str::limit($reason, 250, ''),
                    'attempted_email' => 'unknown',
                    'route' => $request->method() . ' ' . $request->path(),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('unauthorized_access_log_failed', ['error' => $e->getMessage()]);
        }
    }
}
