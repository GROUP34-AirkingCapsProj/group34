<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $password = 'Secret123!'): User
    {
        $uid = uniqid();
        return User::create([
            'first_name'    => 'Pw',
            'last_name'     => 'User',
            'username'      => 'pw_' . $uid,
            'email'         => 'pw_' . $uid . '@airking.com',
            'password_hash' => bcrypt($password),
            'role_id'       => 1,
            'status_id'     => 1,
        ]);
    }

    public function test_changes_password_with_correct_current(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password'          => 'Secret123!',
            'new_password'              => 'BrandNew1!',
            'new_password_confirmation' => 'BrandNew1!',
        ]);

        $resp->assertStatus(200);
        $this->assertTrue(Hash::check('BrandNew1!', $user->fresh()->password_hash));
    }

    public function test_rejects_wrong_current_password(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password'          => 'WrongPass1!',
            'new_password'              => 'BrandNew1!',
            'new_password_confirmation' => 'BrandNew1!',
        ]);

        $resp->assertStatus(422);
        $this->assertTrue(Hash::check('Secret123!', $user->fresh()->password_hash));
    }

    public function test_rejects_weak_new_password(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/password', [
            'current_password'          => 'Secret123!',
            'new_password'              => 'weak',
            'new_password_confirmation' => 'weak',
        ]);

        $resp->assertStatus(422);
    }
}
