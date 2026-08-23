<?php

namespace App\Services;

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
