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

    /** The mail host could not be reached at all -- DNS, routing, or a refusal. */
    public const FAILURE_NETWORK = 'network';

    /** The host answered and rejected our SMTP credentials or sender. */
    public const FAILURE_AUTH = 'auth';

    /** Delivery failed for some reason worth reading the log over. */
    public const FAILURE_OTHER = 'other';

    /**
     * @param  string|null  $failure  set to one of the FAILURE_* constants when
     *                                this returns false, so the caller can tell
     *                                the user which thing to go and fix
     * @return bool whether the message was actually accepted for delivery
     */
    public function issue(User $user, ?string &$failure = null): bool
    {
        $failure = null;
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
            $failure = $this->classify($e);

            // Never include $code: this line reaches a log file that far more
            // people can read than can read the user's inbox. report() sends the
            // real exception onward so a broken mailable is distinguishable from
            // a dead SMTP host.
            report($e);
            Log::warning('login_otp_mail_failed', [
                'user_id'   => $user->user_id,
                'exception' => get_class($e),
                'failure'   => $failure,
                'error'     => $e->getMessage(),
            ]);

            // Surface the common Brevo misconfig without leaking credentials.
            if ($failure === self::FAILURE_AUTH) {
                Log::warning('login_otp_mail_auth_hint', [
                    'hint' => 'Brevo rejected SMTP login/key. Create a new SMTP key in Brevo and update MAIL_USERNAME / MAIL_PASSWORD. Confirm MAIL_FROM_ADDRESS is a verified sender.',
                ]);
            }

            return false;
        }

        return true;
    }

    /**
     * Symfony raises the same TransportException whether the credentials were
     * rejected or the host never answered, so the exception class cannot
     * separate the two and only the message text can. Getting this wrong is not
     * cosmetic: on 2026-09-21 a failed DNS lookup was reported to the user as a
     * credentials problem, sending them to audit an SMTP key that was valid.
     */
    private function classify(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        // Checked first. A rejection is a definite answer from a reachable
        // host, so it outranks any connection wording the driver may add.
        if (str_contains($message, '535') || str_contains($message, 'authentication failed')) {
            return self::FAILURE_AUTH;
        }

        $unreachable = [
            'getaddrinfo',                        // DNS lookup failed outright
            'no such host',                       // Windows' wording for the same
            'connection could not be established', // Symfony's wrapper text
            'could not connect',
            'connection refused',
            'connection timed out',
            'timed out',
            'network is unreachable',
            'temporary failure in name resolution',
        ];

        foreach ($unreachable as $needle) {
            if (str_contains($message, $needle)) {
                return self::FAILURE_NETWORK;
            }
        }

        return self::FAILURE_OTHER;
    }
}
