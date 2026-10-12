<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditors may only use read-only HTTP methods (GET/HEAD/OPTIONS).
 *
 * Carve-outs, each justified at its check below: auth maintenance (logout,
 * token refresh), self-service account routes, PO verification, resolving an
 * Audit & Evidence drift finding, and running a report.
 */
class AuditorReadOnly
{
    private const ALLOWED_PATH_SUFFIXES = [
        '/logout',
        '/refresh-token',
        '/user',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $user->loadMissing('role');
        $roleName = $user->role?->role_name;
        if ($roleName !== 'auditor') {
            return $next($request);
        }

        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $path = '/' . ltrim($request->path(), '/');

        // Self-service account routes are always allowed — every user, including
        // auditors, may manage their own account (profile, avatar, email, password).
        if (str_starts_with($path, '/api/me/')) {
            return $next($request);
        }

        // PO verification is the auditor's substantive write capability — the
        // permission middleware on these routes still enforces who may call them.
        if (preg_match('#^/api/purchase-orders/\d+/(verify|reject)$#', $path)) {
            return $next($request);
        }

        // The other: resolving a drift finding in the Audit & Evidence Explorer.
        // That page is read-only apart from recording what was done about an
        // exception, and a finding closed with no explanation would leave the
        // discrepancy unexplained while looking dealt with — so the auditor has to
        // be able to write the note. permission:audit_evidence,resolve_exception on
        // the route still decides who may.
        if (preg_match('#^/api/audit-evidence/exceptions/\d+/resolve$#', $path)) {
            return $next($request);
        }

        // Report generation. The matrix grants the auditor report_viewing.create
        // deliberately, but this middleware blocked the POST, so the role could
        // only read reports somebody else had already run — which is no use to
        // the person whose job is checking for discrepancies. It is an export,
        // not a mutation: it writes a file and a report_runs row and touches no
        // business data. permission:report_viewing,create still decides who may.
        if ($path === '/api/reports/generate') {
            return $next($request);
        }

        foreach (self::ALLOWED_PATH_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Auditor role is read-only. You cannot modify data through the API.',
        ], 403);
    }
}
