<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();
        return User::create(array_merge([
            'first_name'    => 'Acc',
            'last_name'     => 'User',
            'username'      => 'acc_' . $uid,
            'email'         => 'acc_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => 1,
            'status_id'     => 1,
        ], $overrides));
    }

    public function test_avatar_url_is_null_when_no_avatar_set(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->fresh()->avatar_url);
    }

    public function test_address_column_persists(): void
    {
        $user = $this->makeUser(['address' => '123 Cooling St']);
        $this->assertSame('123 Cooling St', $user->fresh()->address);
    }

    public function test_avatar_url_is_built_from_avatar_path(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = $this->makeUser(['avatar_path' => 'avatars/test.png']);
        $this->assertSame(
            \Illuminate\Support\Facades\Storage::disk('public')->url('avatars/test.png'),
            $user->fresh()->avatar_url
        );
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/me/profile')->assertStatus(401);
    }

    public function test_show_returns_own_profile_with_email_local_split(): void
    {
        $user = $this->makeUser(['email' => 'jdela.cruz@airking.com']);

        $resp = $this->actingAs($user, 'sanctum')->getJson('/api/me/profile');

        $resp->assertStatus(200);
        $resp->assertJsonPath('data.email', 'jdela.cruz@airking.com');
        $resp->assertJsonPath('data.email_local', 'jdela.cruz');
        $resp->assertJsonPath('data.email_domain', '@airking.com');
    }

    public function test_update_persists_name_phone_address(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'New',
            'last_name'  => 'Name',
            'phone'      => '09171234567',
            'address'    => '5th Ave, Makati',
        ]);

        $resp->assertStatus(200);
        $fresh = $user->fresh();
        $this->assertSame('New', $fresh->first_name);
        $this->assertSame('Name', $fresh->last_name);
        $this->assertSame('09171234567', $fresh->phone);
        $this->assertSame('5th Ave, Makati', $fresh->address);
    }

    public function test_update_does_not_change_email_or_role(): void
    {
        $user = $this->makeUser(['role_id' => 1]);
        $originalEmail = $user->email;

        $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'X',
            'last_name'  => 'Y',
            'email'      => 'hacker@evil.com',
            'role_id'    => 99,
        ])->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertSame($originalEmail, $fresh->email);
        $this->assertSame(1, (int) $fresh->role_id);
    }

    public function test_auditor_can_update_own_profile(): void
    {
        $user = $this->makeUser(['role_id' => 5]);

        $this->actingAs($user, 'sanctum')->putJson('/api/me/profile', [
            'first_name' => 'Audit',
            'last_name'  => 'Self',
        ])->assertStatus(200);

        $this->assertSame('Audit', $user->fresh()->first_name);
    }
}
