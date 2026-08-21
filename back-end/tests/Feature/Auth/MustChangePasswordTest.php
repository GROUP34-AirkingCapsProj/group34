<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordResetCode;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MustChangePasswordTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Must',
            'last_name'            => 'Change',
            'username'             => 'must_' . $uid,
            'email'                => 'must_' . $uid . '@airking.com',
            'recovery_email'       => 'must_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => true,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    public function test_self_service_password_update_clears_the_flag(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/me/password', [
                'current_password'          => 'Secret123!',
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])
            ->assertStatus(200);

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_forgot_password_reset_clears_the_flag(): void
    {
        $user = $this->makeUser();

        $resetToken = Str::random(64);
        PasswordResetOtp::create([
            'email'          => $user->email,
            'otp_hash'       => Hash::make('123456'),
            'expires_at'     => now()->addMinutes(10),
            'verified_token' => $resetToken,
        ]);

        $this->postJson('/api/reset-password', [
            'email'                 => $user->email,
            'reset_token'           => $resetToken,
            'password'              => 'Brandnew123!',
            'password_confirmation' => 'Brandnew123!',
        ])->assertStatus(200);

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_admin_setting_another_users_password_re_arms_the_flag(): void
    {
        $admin = $this->makeUser(['must_change_password' => false]);
        $target = $this->makeUser(['must_change_password' => false]);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $target->user_id . '/change-password', [
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])
            ->assertStatus(200);

        $this->assertTrue($target->fresh()->must_change_password);
    }

    public function test_admin_issued_code_reset_clears_the_flag(): void
    {
        $user = $this->makeUser();

        // The legacy admin-issued RST- flow: an admin hands the employee a
        // one-time code and the EMPLOYEE chooses the new password. That is a
        // self-chosen password, so it must release the gate like every other
        // one — otherwise CheckPasswordChange re-gates them immediately.
        $plain = 'RST-ABCD1234';
        PasswordResetCode::create([
            'user_id'    => $user->user_id,
            'code_hash'  => Hash::make($plain),
            'code_plain' => $plain,
            'expires_at' => now()->addMinutes(60),
            'used_at'    => null,
        ]);

        $this->postJson('/api/password-reset/complete', [
            'email'                 => $user->email,
            'code'                  => $plain,
            'password'              => 'Brandnew123!',
            'password_confirmation' => 'Brandnew123!',
        ])->assertStatus(200);

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_flagged_user_is_blocked_from_a_protected_route(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
    }

    public function test_unflagged_user_passes_through(): void
    {
        $user = $this->makeUser(['must_change_password' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/users')
            ->assertStatus(200);
    }

    public function test_flagged_user_can_still_reach_the_escape_hatch_routes(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);

        // Identity endpoint — the SPA needs this to know why it is being blocked.
        $this->actingAs($user, 'sanctum')->getJson('/api/user')->assertStatus(200);

        // Changing the password is the whole point of the gate.
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/me/password', [
                'current_password'          => 'Secret123!',
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])
            ->assertStatus(200);
    }

    public function test_flagged_user_can_log_out(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);

        // AuthController::logout() calls $request->user()->currentAccessToken()->delete(),
        // which is null under a bare actingAs($user, 'sanctum') (no token relation is
        // populated). Issue a real personal access token and authenticate with it as a
        // Bearer credential instead, so currentAccessToken() resolves to a real,
        // deletable model — this exercises the actual logout path.
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout')
            ->assertStatus(200);
    }

    /**
     * Production fidelity. `actingAs()` injects the in-memory model, and a user
     * built with User::create() never loads the DB-supplied default for
     * must_change_password — so the attribute reads null and the gate lets it
     * past. A real bearer token reloads the user from the database the way the
     * Sanctum guard does in production, which is the path that actually matters.
     *
     * Without this test the suite reports green while the gate is inert for
     * every fixture that relies on the column default.
     */
    public function test_gate_fires_for_a_user_authenticated_by_a_real_token(): void
    {
        // No explicit must_change_password: it comes from the column default.
        $user = $this->makeUser();
        $user->forceFill(['must_change_password' => true])->save();

        $token = $user->createToken('gate-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
    }
}
