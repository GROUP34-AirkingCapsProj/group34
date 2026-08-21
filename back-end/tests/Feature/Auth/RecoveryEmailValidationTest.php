<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RecoveryEmailValidationTest extends TestCase
{
    use DatabaseTransactions;

    private function makeAdmin(): User
    {
        $uid = uniqid();

        return User::create([
            'first_name'    => 'Ad',
            'last_name'     => 'Min',
            'username'      => 'admin_' . $uid,
            'email'         => 'admin_' . $uid . '@airking.com',
            'password_hash' => bcrypt('Secret123!'),
            'role_id'       => 1,
            'status_id'     => 1,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        $uid = uniqid();

        return array_merge([
            'first_name'            => 'New',
            'last_name'             => 'Hire',
            'username'              => 'hire_' . $uid,
            'email'                 => 'hire_' . $uid . '@airking.com',
            'recovery_email'        => 'hire_' . $uid . '@gmail.com',
            'password'              => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role_id'               => 1,
            'status_id'             => 1,
        ], $overrides);
    }

    public function test_store_requires_recovery_email(): void
    {
        $payload = $this->payload();
        unset($payload['recovery_email']);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.recovery_email.0', fn ($m) => is_string($m));
    }

    public function test_store_rejects_recovery_email_equal_to_login_email(): void
    {
        $payload = $this->payload();
        $payload['recovery_email'] = $payload['email'];

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.recovery_email.0', fn ($m) => is_string($m));
    }

    public function test_store_persists_recovery_email_and_forces_password_change(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->postJson('/api/users', $payload)
            ->assertStatus(201);

        $created = User::where('email', $payload['email'])->first();

        $this->assertNotNull($created);
        $this->assertSame($payload['recovery_email'], $created->recovery_email);
        $this->assertTrue($created->must_change_password);
    }
}
