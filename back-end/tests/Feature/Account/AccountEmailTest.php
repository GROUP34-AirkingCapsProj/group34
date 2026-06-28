<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $local = null, string $password = 'Secret123!'): User
    {
        $uid = uniqid();
        return User::create([
            'first_name'    => 'Em',
            'last_name'     => 'Ail',
            'username'      => 'em_' . $uid,
            'email'         => ($local ?? 'em_' . $uid) . '@airking.com',
            'password_hash' => bcrypt($password),
            'role_id'       => 1,
            'status_id'     => 1,
        ]);
    }

    public function test_changes_local_part_and_forces_domain(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'new.handle',
            'current_password' => 'Secret123!',
        ]);

        $resp->assertStatus(200);
        $this->assertSame('new.handle@airking.com', $user->fresh()->email);
    }

    public function test_domain_in_submitted_local_part_is_ignored(): void
    {
        $user = $this->makeUser();

        // Even if the user tries to smuggle a domain in, the server forces @airking.com.
        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'evil@gmail.com',
            'current_password' => 'Secret123!',
        ]);

        $resp->assertStatus(422); // '@' is not a valid local-part character here
        $this->assertStringEndsWith('@airking.com', $user->fresh()->email);
    }

    public function test_rejects_wrong_current_password(): void
    {
        $user = $this->makeUser();
        $original = $user->email;

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'whatever',
            'current_password' => 'WrongPass1!',
        ]);

        $resp->assertStatus(422);
        $this->assertSame($original, $user->fresh()->email);
    }

    public function test_rejects_duplicate_email(): void
    {
        $taken = $this->makeUser('taken.handle');
        $user  = $this->makeUser();
        $original = $user->email;

        $resp = $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'taken.handle',
            'current_password' => 'Secret123!',
        ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors('email_local');
        $this->assertSame($original, $user->fresh()->email);
    }

    public function test_local_part_is_lowercased(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')->putJson('/api/me/email', [
            'email_local'      => 'John.Doe',
            'current_password' => 'Secret123!',
        ])->assertStatus(200);

        $this->assertSame('john.doe@airking.com', $user->fresh()->email);
    }
}
