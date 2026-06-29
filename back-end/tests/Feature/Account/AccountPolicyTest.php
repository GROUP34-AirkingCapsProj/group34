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

    private function makeUser(int $roleId): \App\Models\User
    {
        $uid = uniqid();
        return \App\Models\User::create([
            'first_name'    => 'Pol',
            'last_name'     => 'Icy',
            'username'      => 'pol_' . $uid,
            'email'         => 'pol_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => $roleId,
            'status_id'     => 1,
        ]);
    }

    public function test_admin_can_read_policy(): void
    {
        $admin = $this->makeUser(1);

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/system/account-policy');

        $resp->assertStatus(200);
        $resp->assertJson(['avatar' => true, 'email' => true, 'phone' => true, 'address' => true]);
    }

    public function test_admin_can_update_policy(): void
    {
        $admin = $this->makeUser(1);

        $resp = $this->actingAs($admin, 'sanctum')->putJson('/api/system/account-policy', [
            'avatar' => false, 'email' => false, 'phone' => true, 'address' => true,
        ]);

        $resp->assertStatus(200);
        $resp->assertJson(['avatar' => false, 'email' => false, 'phone' => true, 'address' => true]);
        $this->assertFalse(SystemSetting::accountPolicy()['avatar']);
    }

    public function test_non_admin_cannot_read_or_update_policy(): void
    {
        $user = $this->makeUser(2); // inventory_analyst

        $this->actingAs($user, 'sanctum')->getJson('/api/system/account-policy')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->putJson('/api/system/account-policy', [
            'avatar' => false, 'email' => false, 'phone' => false, 'address' => false,
        ])->assertStatus(403);
    }

    public function test_update_requires_all_four_booleans(): void
    {
        $admin = $this->makeUser(1);

        $this->actingAs($admin, 'sanctum')->putJson('/api/system/account-policy', [
            'avatar' => true,
        ])->assertStatus(422);
    }
}
