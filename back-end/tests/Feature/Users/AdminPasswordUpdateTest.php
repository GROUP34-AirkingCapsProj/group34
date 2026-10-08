<?php

namespace Tests\Feature\Users;

use App\Models\AuditTrail;
use App\Models\User;
use App\Services\PasswordAuditLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The admin "New Password (leave blank to keep current)" field on the user
 * edit form posts `password` to PUT /users/{user}.
 *
 * That field used to be dropped on the floor: update() validated a list that
 * did not mention `password`, so the value never reached the model and the
 * response still said "User updated successfully". An admin resetting a
 * locked-out employee's password was told it worked while the old hash stayed
 * exactly where it was -- which reads, from the employee's side, as correct
 * credentials being rejected.
 */
class AdminPasswordUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Edit',
            'last_name'            => 'Target',
            'username'             => 'edit_' . $uid,
            'email'                => 'edit_' . $uid . '@airking.com',
            'recovery_email'       => 'edit_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    public function test_admin_set_password_on_update_actually_replaces_the_hash(): void
    {
        $admin = $this->makeUser();
        $user  = $this->makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $user->user_id, [
                'password'              => 'Brand!New9Pass',
                'password_confirmation' => 'Brand!New9Pass',
            ])
            ->assertStatus(200);

        $fresh = $user->fresh();

        $this->assertTrue(
            Hash::check('Brand!New9Pass', $fresh->password_hash),
            'The password the admin typed does not authenticate.'
        );
        $this->assertFalse(Hash::check('Secret123!', $fresh->password_hash));
    }

    /**
     * An admin-set password is a temporary credential handed to someone else,
     * exactly like the one mailed at account creation -- so it re-arms the gate
     * that forces the holder to choose their own.
     */
    public function test_admin_set_password_arms_the_must_change_password_gate(): void
    {
        $admin = $this->makeUser();
        $user  = $this->makeUser(['must_change_password' => false]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $user->user_id, [
                'password'              => 'Brand!New9Pass',
                'password_confirmation' => 'Brand!New9Pass',
            ])
            ->assertStatus(200);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_admin_set_password_is_recorded_in_the_audit_trail(): void
    {
        $admin = $this->makeUser();
        $user  = $this->makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $user->user_id, [
                'password'              => 'Brand!New9Pass',
                'password_confirmation' => 'Brand!New9Pass',
            ])
            ->assertStatus(200);

        $this->assertTrue(
            AuditTrail::where('action', PasswordAuditLogger::ADMIN_CHANGE)
                ->where('record_id', $user->user_id)
                ->exists(),
            'No audit row for the admin password change.'
        );
    }

    public function test_update_without_a_password_leaves_the_hash_untouched(): void
    {
        $admin = $this->makeUser();
        $user  = $this->makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $user->user_id, ['first_name' => 'Renamed'])
            ->assertStatus(200);

        $fresh = $user->fresh();

        $this->assertSame('Renamed', $fresh->first_name);
        $this->assertTrue(Hash::check('Secret123!', $fresh->password_hash));
        $this->assertFalse($fresh->must_change_password);
    }

    public function test_update_rejects_a_password_that_is_not_confirmed(): void
    {
        $admin = $this->makeUser();
        $user  = $this->makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $user->user_id, [
                'password'              => 'Brand!New9Pass',
                'password_confirmation' => 'Something!Else9',
            ])
            ->assertStatus(422);

        $this->assertTrue(Hash::check('Secret123!', $user->fresh()->password_hash));
    }

    /**
     * The same policy the self-service and forgot-password screens enforce.
     * A weaker rule here would make the admin form the cheapest way to put a
     * guessable password on an account.
     */
    public function test_update_rejects_a_password_below_the_policy(): void
    {
        $admin = $this->makeUser();
        $user  = $this->makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $user->user_id, [
                'password'              => 'weakpass',
                'password_confirmation' => 'weakpass',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', fn ($m) => is_string($m));

        $this->assertTrue(Hash::check('Secret123!', $user->fresh()->password_hash));
    }

    /**
     * The one case that still costs a password: your own. The other two
     * password endpoints both demand it, and this form is reachable by every
     * account that can manage users -- letting it through unchecked would make
     * it the softest of the three doors for a stolen session.
     */
    public function test_changing_your_own_password_requires_the_current_one(): void
    {
        $admin = $this->makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $admin->user_id, [
                'password'              => 'Brand!New9Pass',
                'password_confirmation' => 'Brand!New9Pass',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', fn ($m) => is_string($m));

        $this->assertTrue(Hash::check('Secret123!', $admin->fresh()->password_hash));
    }

    public function test_changing_your_own_password_with_the_current_one_succeeds(): void
    {
        $admin = $this->makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $admin->user_id, [
                'current_password'      => 'Secret123!',
                'password'              => 'Brand!New9Pass',
                'password_confirmation' => 'Brand!New9Pass',
            ])
            ->assertStatus(200);

        $fresh = $admin->fresh();

        $this->assertTrue(Hash::check('Brand!New9Pass', $fresh->password_hash));
        // Self-service: the holder chose it, so no forced change on next login.
        $this->assertFalse($fresh->must_change_password);
    }
}
