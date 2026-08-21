<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetOtpMail;
use App\Models\AuditTrail;
use App\Models\User;
use App\Services\PasswordAuditLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordAuditTrailTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Aud',
            'last_name'            => 'Trail',
            'username'             => 'aud_' . $uid,
            'email'                => 'aud_' . $uid . '@airking.com',
            'recovery_email'       => 'aud_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    private function auditFor(User $user, string $action): ?AuditTrail
    {
        return AuditTrail::where('action', $action)
            ->where('record_id', $user->user_id)
            ->orderByDesc('audit_id')
            ->first();
    }

    public function test_self_service_password_change_is_recorded(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/me/password', [
                'current_password'          => 'Secret123!',
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])->assertStatus(200);

        $row = $this->auditFor($user, PasswordAuditLogger::SELF_CHANGE);

        $this->assertNotNull($row);
        $this->assertSame($user->user_id, $row->user_id);
        $this->assertSame('users', $row->table_name);
        $this->assertSame('self_service', $row->new_values['method']);
    }

    public function test_a_forced_change_is_recorded_as_such(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/me/password', [
                'current_password'          => 'Secret123!',
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])->assertStatus(200);

        $row = $this->auditFor($user, PasswordAuditLogger::SELF_CHANGE);

        $this->assertNotNull($row);
        $this->assertSame('forced_change_at_login', $row->new_values['method']);
    }

    public function test_forgot_password_request_and_completion_are_recorded(): void
    {
        Mail::fake();
        $user = $this->makeUser();

        $this->postJson('/api/forgot-password', ['email' => $user->recovery_email])
            ->assertStatus(200);

        $requested = $this->auditFor($user, PasswordAuditLogger::RESET_REQUESTED);
        $this->assertNotNull($requested);
        $this->assertSame('recovery_email', $requested->new_values['delivery']);

        $otp = null;
        Mail::assertSent(PasswordResetOtpMail::class, function ($mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $token = $this->postJson('/api/verify-reset-otp', [
            'email' => $user->recovery_email,
            'otp'   => $otp,
        ])->assertStatus(200)->json('reset_token');

        $this->postJson('/api/reset-password', [
            'email'                 => $user->recovery_email,
            'reset_token'           => $token,
            'password'              => 'Brandnew123!',
            'password_confirmation' => 'Brandnew123!',
        ])->assertStatus(200);

        $completed = $this->auditFor($user, PasswordAuditLogger::RESET_COMPLETED);
        $this->assertNotNull($completed);
        $this->assertSame('email_otp', $completed->new_values['method']);
    }

    public function test_admin_issuing_an_authorization_code_is_recorded_without_the_code(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $res = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/password-reset/issue', ['user_id' => $target->user_id])
            ->assertStatus(200);

        $plainCode = $res->json('code');
        $this->assertNotEmpty($plainCode);

        $row = $this->auditFor($target, PasswordAuditLogger::CODE_ISSUED);

        $this->assertNotNull($row);
        // Actor is the admin; the subject is the account acted upon.
        $this->assertSame($admin->user_id, $row->user_id);
        $this->assertSame($target->user_id, (int) $row->record_id);
        // The trail must never carry the live credential.
        $this->assertStringNotContainsString($plainCode, json_encode($row->new_values));
    }

    public function test_reset_via_admin_code_is_recorded_with_its_method(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $code = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/password-reset/issue', ['user_id' => $target->user_id])
            ->json('code');

        $this->postJson('/api/password-reset/complete', [
            'email'                 => $target->email,
            'code'                  => $code,
            'password'              => 'Brandnew123!',
            'password_confirmation' => 'Brandnew123!',
        ])->assertStatus(200);

        $row = $this->auditFor($target, PasswordAuditLogger::RESET_COMPLETED);

        $this->assertNotNull($row);
        $this->assertSame('admin_authorization_code', $row->new_values['method']);
    }

    public function test_admin_setting_another_users_password_is_recorded(): void
    {
        $admin = $this->makeUser();
        $target = $this->makeUser();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/users/' . $target->user_id . '/change-password', [
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])->assertStatus(200);

        $row = $this->auditFor($target, PasswordAuditLogger::ADMIN_CHANGE);

        $this->assertNotNull($row);
        $this->assertSame($admin->user_id, $row->user_id);
        $this->assertSame('admin_set_temporary', $row->new_values['method']);
    }

    public function test_no_audit_row_carries_a_password(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/me/password', [
                'current_password'          => 'Secret123!',
                'new_password'              => 'Brandnew123!',
                'new_password_confirmation' => 'Brandnew123!',
            ])->assertStatus(200);

        $row = $this->auditFor($user, PasswordAuditLogger::SELF_CHANGE);

        $encoded = json_encode([$row->old_values, $row->new_values]);
        $this->assertStringNotContainsString('Brandnew123!', $encoded);
        $this->assertStringNotContainsString('Secret123!', $encoded);
    }
}
