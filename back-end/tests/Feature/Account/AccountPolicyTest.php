<?php

namespace Tests\Feature\Account;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountPolicyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_account_policy_defaults_to_all_true(): void
    {
        $policy = SystemSetting::accountPolicy();
        $this->assertSame(
            ['avatar' => true, 'email' => true, 'phone' => true, 'address' => true],
            $policy
        );
    }

    public function test_set_account_policy_round_trips_and_casts_bools(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => false, 'email' => true, 'phone' => 0, 'address' => 1]);

        $this->assertSame(
            ['avatar' => false, 'email' => true, 'phone' => false, 'address' => true],
            SystemSetting::accountPolicy()
        );
    }

    public function test_set_account_policy_fills_missing_keys_with_true(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => false]);

        $this->assertSame(
            ['avatar' => false, 'email' => true, 'phone' => true, 'address' => true],
            SystemSetting::accountPolicy()
        );
    }

    public function test_account_policy_falls_back_to_defaults_on_corrupt_value(): void
    {
        SystemSetting::updateOrCreate(
            ['key' => SystemSetting::KEY_ACCOUNT_POLICY],
            ['value' => 'NOT_VALID_JSON']
        );

        $this->assertSame(
            ['avatar' => true, 'email' => true, 'phone' => true, 'address' => true],
            SystemSetting::accountPolicy()
        );
    }
}
