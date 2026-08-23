<?php

namespace Tests\Feature\Users;

use App\Services\TemporaryCredentialIssuer;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class CredentialIssuanceTest extends TestCase
{
    /**
     * The generated password must satisfy the same rule the app enforces on
     * user-chosen passwords. Asserting against the real validator rather than a
     * hand-written regex means the generator cannot silently drift away from the
     * policy if the policy is later tightened.
     */
    public function test_generated_password_satisfies_the_application_password_rule(): void
    {
        $issuer = app(TemporaryCredentialIssuer::class);

        // 50 draws: a generator that only occasionally omits a character class
        // would pass a single-draw test by luck.
        for ($i = 0; $i < 50; $i++) {
            $password = $issuer->generateForTesting();

            $validator = Validator::make(
                ['password' => $password],
                ['password' => [Password::min(8)->mixedCase()->numbers()->symbols()]]
            );

            $this->assertFalse(
                $validator->fails(),
                'Generated password failed the policy: ' . $password
            );
        }
    }

    public function test_generated_passwords_are_not_repeated(): void
    {
        $issuer = app(TemporaryCredentialIssuer::class);

        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[] = $issuer->generateForTesting();
        }

        $this->assertCount(50, array_unique($seen));
    }
}
