<?php

namespace App\Services;

use App\Mail\LoginOtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mints the one-time login code, stores it hashed, and mails it to the user's
 * recovery address.
 *
 * Both the login path and the recovery-address bootstrap issue codes. Keeping
 * that here means the two cannot drift apart on code length, expiry, or
 * delivery target.
 *
 * The plaintext code exists only as a local variable and in the outgoing
 * message. Only its hash is persisted, so a database read cannot complete a
 * login.
 */
class LoginOtpIssuer
{
    private const TTL_MINUTES = 10;

    /**
     * @return bool whether the message was actually accepted for delivery
     */
    public function issue(User $user): bool
    {
        $code = (string) random_int(100000, 999999);

        // Overwrites any code still outstanding for this user, which is what
        // makes issuing a new one invalidate the old.
        $user->fill([
            'otp_code'       => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            // A new code is a new budget. Carrying failures across would leave a
            // user who fumbled once permanently closer to a burned code.
            'otp_attempts'   => 0,
        ])->save();

        try {
            // The TTL travels with the message so the lifetime the user is told
            // about cannot drift from the one just written above.
            Mail::to($user->recovery_email)->send(new LoginOtpMail($code, $user, self::TTL_MINUTES));
        } catch (\Throwable $e) {
            // Never include $code: this line reaches a log file that far more
            // people can read than can read the user's inbox. report() sends the
            // real exception onward so a broken mailable is distinguishable from
            // a dead SMTP host.
            report($e);
            Log::warning('login_otp_mail_failed', [
                'user_id'   => $user->user_id,
                'exception' => get_class($e),
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
