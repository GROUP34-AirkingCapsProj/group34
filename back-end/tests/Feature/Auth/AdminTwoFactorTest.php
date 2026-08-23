<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Two',
            'last_name'            => 'Factor',
            'username'             => 'tf_' . $uid,
            'email'                => 'tf_' . $uid . '@airking.com',
            'recovery_email'       => 'tf_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 3, // branch_manager -- a non-admin
            'status_id'            => 1,
        ], $overrides));
    }

    private function makeAdmin(array $overrides = []): User
    {
        return $this->makeUser(array_merge(['role_id' => 1], $overrides));
    }

    public function test_is_admin_distinguishes_the_admin_role(): void
    {
        $this->assertTrue($this->makeAdmin()->isAdmin());
        $this->assertFalse($this->makeUser()->isAdmin());
    }

    /**
     * The central claim of this feature: an admin is not subject to the global
     * switch. If this ever passes for the wrong reason the whole change is
     * pointless, so it is asserted directly against the policy rather than
     * only through HTTP.
     */
    public function test_an_admin_needs_a_code_even_when_the_toggle_is_off(): void
    {
        SystemSetting::setTwoFactorAuthEnabled(false);

        $this->assertTrue(app(TwoFactorPolicy::class)->requiresOtp($this->makeAdmin()));
    }

    public function test_a_non_admin_follows_the_toggle(): void
    {
        $user = $this->makeUser();
        $policy = app(TwoFactorPolicy::class);

        SystemSetting::setTwoFactorAuthEnabled(false);
        $this->assertFalse($policy->requiresOtp($user));

        SystemSetting::setTwoFactorAuthEnabled(true);
        $this->assertTrue($policy->requiresOtp($user));
    }

    public function test_requires_recovery_email_only_when_a_code_is_needed_and_none_is_set(): void
    {
        SystemSetting::setTwoFactorAuthEnabled(false);
        $policy = app(TwoFactorPolicy::class);

        // Admin, no address -> must supply one.
        $this->assertTrue($policy->requiresRecoveryEmail($this->makeAdmin(['recovery_email' => null])));

        // Admin with an address -> nothing to collect.
        $this->assertFalse($policy->requiresRecoveryEmail($this->makeAdmin()));

        // Non-admin with the toggle off needs no code, so no address either --
        // this must not gate users who are not being challenged.
        $this->assertFalse($policy->requiresRecoveryEmail($this->makeUser(['recovery_email' => null])));
    }
}
