<?php

namespace Tests\Feature\Users;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The real need behind "delete this account permanently" is almost never
 * "destroy the record" -- it is "give me that email address back", because a
 * person left and their replacement, or their re-hire, needs it.
 *
 * Releasing the identifiers answers exactly that: the login email, username and
 * recovery address are retired on the archived row, which frees them for a new
 * account, while the row itself and every approval, adjustment and audit entry
 * attributed to it stay where they are.
 */
class ReleaseIdentifiersTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Left',
            'last_name'            => 'Company',
            'username'             => 'left_' . $uid,
            'email'                => 'left_' . $uid . '@airking.com',
            'recovery_email'       => 'left_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    private function release(User $admin, User $target)
    {
        return $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/' . $target->user_id . '/release-identifiers');
    }

    public function test_release_frees_all_three_identifiers_for_a_new_account(): void
    {
        Mail::fake();
        $admin = $this->makeUser();
        $left  = $this->makeUser();

        $email    = $left->email;
        $username = $left->username;
        $recovery = $left->recovery_email;

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'first_name'     => 'Re',
                'last_name'      => 'Placement',
                'username'       => $username,
                'email'          => $email,
                'recovery_email' => $recovery,
                'role_id'        => 1,
                'status_id'      => 1,
            ])
            ->assertStatus(201);
    }

    /**
     * The whole point: the row survives, so nothing that was attributed to this
     * person becomes anonymous.
     */
    public function test_release_keeps_the_row_and_its_attributions(): void
    {
        $admin = $this->makeUser();
        $left  = $this->makeUser();

        DB::table('adjustments')->insert([
            'adjustment_number' => 'ADJ-' . uniqid(),
            'adjusted_by'       => $left->user_id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $still = User::withTrashed()->find($left->user_id);

        $this->assertNotNull($still, 'The archived row was destroyed.');
        $this->assertSame('Left', $still->first_name);
        $this->assertSame('Company', $still->last_name);
        $this->assertSame(
            1,
            DB::table('adjustments')->where('adjusted_by', $left->user_id)->count()
        );
    }

    /**
     * The addresses are retired, not erased: the archive list still shows a
     * recognisable value, and the originals are in the audit trail, which is
     * the only place they can be recovered from afterwards.
     */
    public function test_release_records_the_original_addresses_in_the_audit_trail(): void
    {
        $admin = $this->makeUser();
        $left  = $this->makeUser();
        $email = $left->email;

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $row = AuditTrail::where('action', 'identifiers_released')
            ->where('record_id', $left->user_id)
            ->first();

        $this->assertNotNull($row, 'The release was not audited.');
        $this->assertSame($email, data_get($row->old_values, 'email'));
        $this->assertSame($admin->user_id, (int) $row->user_id);
    }

    public function test_the_released_account_can_no_longer_be_signed_in_to(): void
    {
        $admin = $this->makeUser();
        $left  = $this->makeUser();
        $email = $left->email;

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $challenge = $this->getJson('/api/login/challenge')->json('data');

        $this->postJson('/api/login', [
            'email'          => $email,
            'password'       => 'Secret123!',
            'captcha_token'  => $challenge['captcha_token'],
            'captcha_answer' => (string) ($challenge['operand_a'] + $challenge['operand_b']),
        ])->assertStatus(422);
    }

    /** A live account's login must never be pulled out from under it. */
    public function test_release_is_refused_for_an_account_that_is_not_archived(): void
    {
        $admin = $this->makeUser();
        $live  = $this->makeUser();

        $this->release($admin, $live)->assertStatus(404);

        $this->assertSame($live->email, $live->fresh()->email);
    }

    public function test_release_is_refused_twice(): void
    {
        $admin = $this->makeUser();
        $left  = $this->makeUser();

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $released = User::withTrashed()->find($left->user_id)->email;

        $this->release($admin, $left)->assertStatus(422);

        $this->assertSame($released, User::withTrashed()->find($left->user_id)->email);
    }

    public function test_release_marks_the_row_so_the_ui_can_tell(): void
    {
        $admin = $this->makeUser();
        $left  = $this->makeUser();

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $this->assertNotNull(User::withTrashed()->find($left->user_id)->identifiers_released_at);
    }

    /**
     * recovery_email is varchar(100) and email is varchar(255). A retired value
     * has to fit the column it lives in or the save throws -- which, on a
     * near-limit address, would have made this endpoint fail for exactly the
     * accounts it was most needed on.
     */
    public function test_release_fits_addresses_that_are_already_at_the_column_limit(): void
    {
        $admin = $this->makeUser();
        $left  = $this->makeUser([
            'email'          => str_repeat('a', 80) . '@airking.com',
            'recovery_email' => str_repeat('b', 84) . '@gmail.com',
            'username'       => str_repeat('c', 50),
        ]);

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $fresh = User::withTrashed()->find($left->user_id);

        $this->assertLessThanOrEqual(100, strlen($fresh->recovery_email));
        $this->assertLessThanOrEqual(255, strlen($fresh->email));
        $this->assertNotSame($left->email, $fresh->email);
        $this->assertNotSame($left->recovery_email, $fresh->recovery_email);
    }

    /** Accounts with no recovery address on file predate the column. */
    public function test_release_handles_an_account_with_no_recovery_email(): void
    {
        $admin = $this->makeUser();
        $left  = $this->makeUser(['recovery_email' => null]);

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $this->assertNull(User::withTrashed()->find($left->user_id)->recovery_email);
    }

    /**
     * Released and then restored is a recoverable mistake, not a locked door:
     * the account comes back with a retired login the admin then replaces. The
     * forced-change gate is what stops it coming back usable with an unknown
     * credential.
     */
    public function test_a_released_account_that_is_restored_must_be_given_a_new_login(): void
    {
        $admin = $this->makeUser();
        $left  = $this->makeUser();

        $left->delete();
        $this->release($admin, $left)->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users/' . $left->user_id . '/restore')
            ->assertStatus(200);

        $restored = User::find($left->user_id);

        $this->assertNotNull($restored);
        $this->assertTrue($restored->must_change_password);
    }
}
