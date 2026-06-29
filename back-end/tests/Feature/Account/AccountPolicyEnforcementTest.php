<?php

namespace Tests\Feature\Account;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountPolicyEnforcementTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(int $roleId = 4): User
    {
        $uid = uniqid();
        return User::create([
            'first_name'    => 'Enf',
            'last_name'     => 'Orce',
            'username'      => 'enf_' . $uid,
            'email'         => 'enf_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => $roleId,
            'status_id'     => 1,
            'phone'         => '0917 000 0000',
            'address'       => 'Old Address',
        ]);
    }

    private function lockAll(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => false, 'email' => false, 'phone' => false, 'address' => false]);
    }

    public function test_profile_reports_effective_policy_for_non_admin(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => false, 'email' => true, 'phone' => false, 'address' => true]);
        $user = $this->makeUser(4);

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/me/profile');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.policy.avatar', false);
        $resp->assertJsonPath('data.policy.email', true);
        $resp->assertJsonPath('data.policy.phone', false);
        $resp->assertJsonPath('data.policy.address', true);
    }

    public function test_profile_reports_all_true_for_admin_regardless_of_policy(): void
    {
        $this->lockAll();
        $admin = $this->makeUser(1);

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/me/profile');

        $resp->assertJsonPath('data.policy.avatar', true);
        $resp->assertJsonPath('data.policy.email', true);
        $resp->assertJsonPath('data.policy.phone', true);
        $resp->assertJsonPath('data.policy.address', true);
    }

    public function test_locked_avatar_blocks_upload_for_non_admin(): void
    {
        Storage::fake('public');
        $this->lockAll();
        $user = $this->makeUser(4);

        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg'),
        ]);

        $resp->assertStatus(403);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_locked_avatar_blocks_delete_for_non_admin(): void
    {
        $this->lockAll();
        $user = $this->makeUser(4);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/me/avatar')
            ->assertStatus(403);
    }

    public function test_locked_email_blocks_change_for_non_admin(): void
    {
        $this->lockAll();
        $user = $this->makeUser(4);
        $original = $user->email;

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local' => 'newhandle', 'current_password' => 'Secret123!',
        ]);

        $resp->assertStatus(403);
        $this->assertSame($original, $user->fresh()->email);
    }

    public function test_locked_phone_and_address_not_applied_but_name_saves(): void
    {
        SystemSetting::setAccountPolicy(['avatar' => true, 'email' => true, 'phone' => false, 'address' => false]);
        $user = $this->makeUser(4);

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'Renamed',
            'last_name'  => 'User',
            'phone'      => '0999 999 9999',
            'address'    => 'New Address',
        ]);

        $resp->assertStatus(200);
        $fresh = $user->fresh();
        $this->assertSame('Renamed', $fresh->first_name);
        $this->assertSame('0917 000 0000', $fresh->phone);
        $this->assertSame('Old Address', $fresh->address);
    }

    public function test_admin_bypasses_all_locks(): void
    {
        Storage::fake('public');
        $this->lockAll();
        $admin = $this->makeUser(1);

        $this->actingAs($admin, 'sanctum')->postJson('/api/me/avatar', [
            'avatar' => UploadedFile::fake()->image('a.jpg'),
        ])->assertStatus(200);
        $this->actingAs($admin, 'sanctum')->putJson('/api/me/email', [
            'email_local' => 'adminnew', 'current_password' => 'Secret123!',
        ])->assertStatus(200);
        $this->actingAs($admin, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'Admin', 'last_name' => 'Boss',
            'phone' => '0918 111 1111', 'address' => 'HQ',
        ])->assertStatus(200);

        $fresh = $admin->fresh();
        $this->assertNotNull($fresh->avatar_path);
        $this->assertSame('adminnew@airking.com', $fresh->email);
        $this->assertSame('0918 111 1111', $fresh->phone);
        $this->assertSame('HQ', $fresh->address);
    }
}
