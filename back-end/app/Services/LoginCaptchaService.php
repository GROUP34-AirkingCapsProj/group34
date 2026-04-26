<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

/**
 * Stateless math CAPTCHA for login (encrypted token; mitigates basic bots).
 */
class LoginCaptchaService
{
    private const TTL_SECONDS = 600;

    public function createChallenge(): array
    {
        $a = random_int(1, 12);
        $b = random_int(1, 12);
        $expected = $a + $b;
        $payload = [
            'exp' => time() + self::TTL_SECONDS,
            'expected' => $expected,
        ];
        $token = Crypt::encryptString(json_encode($payload));

        return [
            'captcha_token' => $token,
            'question' => "What is {$a} + {$b}?",
            'operand_a' => $a,
            'operand_b' => $b,
        ];
    }

    public function verify(?string $token, ?string $answer): bool
    {
        if ($token === null || $token === '' || $answer === null || $answer === '') {
            return false;
        }

        try {
            $raw = Crypt::decryptString($token);
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                return false;
            }
            if (($data['exp'] ?? 0) < time()) {
                return false;
            }

            return (int) ($data['expected'] ?? -1) === (int) $answer;
        } catch (\Throwable) {
            return false;
        }
    }
}
