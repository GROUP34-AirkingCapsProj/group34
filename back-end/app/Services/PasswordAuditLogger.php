<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Audit-trail entries for every event that changes a password or hands out the
 * means to change one.
 *
 * Never records a secret: no password, no OTP, no RST- authorization code. The
 * trail answers "who changed whose password, when, and by which route" -- it is
 * not a place to look up a live credential.
 *
 * `user_id` is the actor and `record_id` the account acted upon. For the
 * unauthenticated forgot-password routes there is no session, so the subject is
 * recorded as the actor: the account holder is the one driving the flow.
 */
class PasswordAuditLogger
{
    public const SELF_CHANGE       = 'password_changed';
    public const ADMIN_CHANGE      = 'password_changed_by_admin';
    public const RESET_REQUESTED   = 'password_reset_requested';
    public const RESET_COMPLETED   = 'password_reset_completed';
    public const CODE_ISSUED       = 'reset_code_issued';
    public const CODE_REVOKED      = 'reset_code_revoked';

    /**
     * @param  User  $subject  the account whose password is at stake
     * @param  User|null  $actor  who performed it; defaults to the subject
     */
    public function log(string $action, User $subject, array $context = [], ?User $actor = null): void
    {
        try {
            AuditTrail::create([
                'user_id'    => ($actor ?? $subject)->user_id,
                'action'     => $action,
                'table_name' => 'users',
                'record_id'  => $subject->user_id,
                'old_values' => null,
                'new_values' => array_merge(['account' => $subject->email], $context),
                'ip_address' => request()?->ip(),
                'user_agent' => Str::limit((string) request()?->userAgent(), 500, ''),
            ]);
        } catch (\Throwable $e) {
            // A failed audit write must never break the password operation that
            // triggered it -- the user is mid-recovery and may be locked out.
            Log::warning('password_audit_log_failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
