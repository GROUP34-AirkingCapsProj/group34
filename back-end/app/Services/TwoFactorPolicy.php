<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;

/**
 * Decides who must clear a second factor at login.
 *
 * Admins are deliberately exempt from the global toggle. The admin UI can turn
 * two-factor off, and before this the account with the most to lose could
 * therefore disable its own protection. Keeping the rule here rather than
 * inline in AuthController means there is exactly one place to read when
 * someone asks "which roles are covered?", and it can be tested without going
 * through HTTP.
 */
class TwoFactorPolicy
{
    public function requiresOtp(User $user): bool
    {
        // Defence in depth. users.role_id is NOT NULL behind a foreign key to
        // roles, so an unresolvable role should be impossible -- but this is the
        // gate that decides whether an account gets a second factor, and the
        // cost of being wrong is asymmetric. An anomalous account gets
        // challenged rather than quietly taking the weaker path.
        if ($user->role === null) {
            return true;
        }

        return $user->isAdmin() || SystemSetting::twoFactorAuthEnabled();
    }

    /**
     * True when the user must clear a second factor but has no address to
     * receive it at. Callers collect one before issuing a code, rather than
     * mailing into the void or locking the account out.
     */
    public function requiresRecoveryEmail(User $user): bool
    {
        return $this->requiresOtp($user) && blank($user->recovery_email);
    }
}
