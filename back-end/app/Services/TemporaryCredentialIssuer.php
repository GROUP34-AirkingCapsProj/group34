<?php

namespace App\Services;

use App\Mail\AccountCredentialsMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Issues the one-time password a newly created account logs in with.
 *
 * The plaintext exists only as a local variable and in the outgoing message.
 * It is never returned to the admin, never stored, and never logged -- the
 * `must_change_password` gate makes it single-use in practice, and nobody but
 * the account holder should ever be able to read it.
 */
class TemporaryCredentialIssuer
{
    private const LENGTH = 16;

    private const LOWER   = 'abcdefghijkmnopqrstuvwxyz';
    private const UPPER   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const DIGITS  = '23456789';
    private const SYMBOLS = '!@#$%^&*?-_=+';

    /**
     * Rotate the user's password to a fresh temporary one and mail it to their
     * recovery address.
     *
     * The rotation is committed before the send, by necessity: the hash must be
     * saved for the emailed password to work. So a failed send leaves a valid
     * but undelivered credential -- which is exactly the state the resend
     * endpoint exists to clear. Returns whether the mail actually went out so
     * the caller can tell the admin.
     *
     * Must NOT be called inside a caller's database transaction: the send is not
     * transactional, so a rollback would revoke a password that has already
     * landed in the user's inbox, turning a recoverable undelivered-credential
     * state into a guaranteed lockout.
     *
     * $actor is required rather than defaulting to the subject. PasswordAuditLogger
     * documents that fallback as existing for the unauthenticated forgot-password
     * routes, where there is genuinely no session; that justification does not
     * transfer here, since every caller of this method sits behind
     * permission:user_account_management and has a request user. Defaulting would
     * let a caller silently record "user X ran password_changed_by_admin on user
     * X" -- indistinguishable, to an operator reading the trail during a dispute,
     * from the employee having done it themselves. A confidently wrong audit
     * record is worse than a missing one.
     *
     * @param  User  $user  the account receiving the credential
     * @param  User  $actor  the admin performing it
     */
    public function issue(User $user, User $actor): bool
    {
        $plain = $this->generate();

        $user->fill([
            'password_hash'        => Hash::make($plain),
            'must_change_password' => true,
        ])->save();

        $delivered = true;

        try {
            Mail::to($user->recovery_email)->send(new AccountCredentialsMail($user, $plain));
        } catch (\Throwable $e) {
            $delivered = false;

            // Catching Throwable keeps a broken mailable from taking down account
            // creation, but it also means a genuine bug (a TypeError in the
            // mailable, a Blade compile error) reaches the admin as "mail failed".
            // They retry, minting another live undeliverable credential each time.
            // report() sends the real exception, with its trace, to the error
            // handler so that case is distinguishable from a dead SMTP host.
            report($e);

            // Deliberately does not include $plain: this line goes to a log file
            // that far more people can read than can read the user's inbox.
            Log::warning('credential_mail_failed', [
                'user_id'   => $user->user_id,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }

        app(PasswordAuditLogger::class)->log(
            PasswordAuditLogger::ADMIN_CHANGE,
            $user,
            ['method' => 'credentials_emailed', 'delivered' => $delivered],
            $actor,
        );

        return $delivered;
    }

    /**
     * Test seam. The generator is private because nothing outside this class
     * should mint a password without also persisting and mailing it, but its
     * output must be verifiable against the app's password policy.
     *
     * The runningUnitTests() guard below is what actually enforces that rule
     * -- without it, this being merely public would let any production caller
     * mint a live credential that is never stored or emailed.
     *
     * @internal
     */
    public function generateForTesting(): string
    {
        if (! app()->runningUnitTests()) {
            throw new \LogicException(
                'generateForTesting() is a test seam. Use issue() to mint a credential in production.'
            );
        }

        return $this->generate();
    }

    /**
     * Guarantees one character from each required class by construction, then
     * shuffles, so the result satisfies
     * Password::min(8)->mixedCase()->numbers()->symbols() every time rather
     * than merely usually.
     *
     * Visually ambiguous characters are excluded, since this password gets
     * read off a screen and retyped by hand: lowercase l, uppercase I and O,
     * and digits 0 and 1 are all dropped from their respective alphabets
     * (lowercase i is kept -- it isn't easily confused with any other
     * character in these alphabets).
     */
    private function generate(): string
    {
        $pool = self::LOWER . self::UPPER . self::DIGITS . self::SYMBOLS;

        $chars = [
            $this->pick(self::LOWER),
            $this->pick(self::UPPER),
            $this->pick(self::DIGITS),
            $this->pick(self::SYMBOLS),
        ];

        for ($i = count($chars); $i < self::LENGTH; $i++) {
            $chars[] = $this->pick($pool);
        }

        // Fisher-Yates with random_int: str_shuffle() uses the non-cryptographic
        // RNG, which would undermine the whole point of random_int() above.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    private function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
}
