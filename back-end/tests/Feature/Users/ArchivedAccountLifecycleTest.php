<?php

namespace Tests\Feature\Users;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Archiving a user is a soft delete, so the row keeps its email, username and
 * recovery_email -- and keeps the unique indexes on all three. Every symptom
 * below comes from that one fact being invisible to the operator:
 *
 *  - the archived account's holder is told their correct password is "invalid",
 *    because the login lookup skips trashed rows and cannot tell the two apart;
 *  - recreating the account is refused as "already in use" with nothing to say
 *    which account is using it or how to free it.
 */
class ArchivedAccountLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Arch',
            'last_name'            => 'Ived',
            'username'             => 'arch_' . $uid,
            'email'                => 'arch_' . $uid . '@airking.com',
            'recovery_email'       => 'arch_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    private function captcha(): array
    {
        $c = $this->getJson('/api/login/challenge')->assertStatus(200)->json('data');

        return [
            'captcha_token'  => $c['captcha_token'],
            'captcha_answer' => (string) ($c['operand_a'] + $c['operand_b']),
        ];
    }

    private function login(string $email, string $password)
    {
        return $this->postJson('/api/login', array_merge([
            'email'    => $email,
            'password' => $password,
        ], $this->captcha()));
    }

    /**
     * The holder of an archived account must be told what happened. "Invalid
     * email or password" sends them, and the admin helping them, chasing a
     * password that was never wrong.
     */
    public function test_archived_account_login_says_the_account_is_archived(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $response = $this->login($user->email, 'Secret123!')->assertStatus(422);

        $this->assertStringContainsStringIgnoringCase(
            'archived',
            json_encode($response->json()),
            'The archived account got the generic invalid-credentials message.'
        );
        $this->assertNull($response->json('token'));
    }

    /** A wrong password on an archived account still reveals nothing. */
    public function test_archived_account_with_a_wrong_password_gets_the_generic_message(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $response = $this->login($user->email, 'NotThePassword9!')->assertStatus(422);

        $this->assertStringNotContainsStringIgnoringCase('archived', json_encode($response->json()));
    }

    public function test_archived_account_cannot_be_issued_a_token(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $this->assertNull($this->login($user->email, 'Secret123!')->json('token'));
    }

    /**
     * The error an admin actually hits after "deleting" an account and trying
     * to recreate it. It has to name the archive, or the address looks
     * permanently burned.
     */
    public function test_recreating_an_archived_accounts_recovery_email_points_at_the_archive(): void
    {
        Mail::fake();
        $admin    = $this->makeUser();
        $archived = $this->makeUser();
        $archived->delete();

        $uid = uniqid();
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'first_name'     => 'New',
                'last_name'      => 'Hire',
                'username'       => 'hire_' . $uid,
                'email'          => 'hire_' . $uid . '@airking.com',
                'recovery_email' => $archived->recovery_email,
                'role_id'        => 1,
                'status_id'      => 1,
            ])
            ->assertStatus(422);

        $this->assertStringContainsStringIgnoringCase(
            'archived',
            json_encode($response->json('errors.recovery_email')),
            'The admin was told the address is taken, with no way to find out by what.'
        );
    }

    public function test_recreating_an_archived_accounts_login_email_points_at_the_archive(): void
    {
        Mail::fake();
        $admin    = $this->makeUser();
        $archived = $this->makeUser();
        $archived->delete();

        $uid = uniqid();
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'first_name'     => 'New',
                'last_name'      => 'Hire',
                'username'       => 'hire_' . $uid,
                'email'          => $archived->email,
                'recovery_email' => 'hire_' . $uid . '@gmail.com',
                'role_id'        => 1,
                'status_id'      => 1,
            ])
            ->assertStatus(422);

        $this->assertStringContainsStringIgnoringCase(
            'archived',
            json_encode($response->json('errors.email'))
        );
    }

    public function test_recreating_an_archived_accounts_username_points_at_the_archive(): void
    {
        Mail::fake();
        $admin    = $this->makeUser();
        $archived = $this->makeUser();
        $archived->delete();

        $uid = uniqid();
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'first_name'     => 'New',
                'last_name'      => 'Hire',
                'username'       => $archived->username,
                'email'          => 'hire_' . $uid . '@airking.com',
                'recovery_email' => 'hire_' . $uid . '@gmail.com',
                'role_id'        => 1,
                'status_id'      => 1,
            ])
            ->assertStatus(422);

        $this->assertStringContainsStringIgnoringCase(
            'archived',
            json_encode($response->json('errors.username'))
        );
    }

    /**
     * An address held by a LIVE account keeps its plain message -- naming the
     * archive there would be wrong and would hint at accounts an admin cannot
     * see in the list.
     */
    public function test_a_live_accounts_address_keeps_the_plain_already_taken_message(): void
    {
        Mail::fake();
        $admin    = $this->makeUser();
        $existing = $this->makeUser();

        $uid = uniqid();
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'first_name'     => 'New',
                'last_name'      => 'Hire',
                'username'       => 'hire_' . $uid,
                'email'          => 'hire_' . $uid . '@airking.com',
                'recovery_email' => $existing->recovery_email,
                'role_id'        => 1,
                'status_id'      => 1,
            ])
            ->assertStatus(422);

        $this->assertStringNotContainsStringIgnoringCase(
            'archived',
            json_encode($response->json('errors.recovery_email'))
        );
    }

    /**
     * users.report_runs.generated_by is ON DELETE RESTRICT, so permanently
     * deleting an account that ever generated a report throws at the database.
     * Unhandled, that surfaced as a generic 500 while the audit trail had
     * already recorded a permanent deletion that never happened.
     */
    public function test_blocked_permanent_delete_explains_itself_and_leaves_no_audit_row(): void
    {
        $admin    = $this->makeUser();
        $archived = $this->makeUser();

        DB::table('report_runs')->insert([
            'report_key'      => 'inventory_stock',
            'title_snapshot'  => 'Stock',
            'format'          => 'pdf',
            'parameters'      => '{}',
            'file_name'       => 'stock.pdf',
            'file_size_bytes' => 0,
            'row_count'       => 0,
            'generated_by'    => $archived->user_id,
            'created_at'      => now(),
        ]);

        $archived->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/archive/users/' . $archived->user_id . '/force');

        $this->assertSame(409, $response->status(), 'Expected a reported conflict, not a crash.');
        $this->assertStringContainsStringIgnoringCase(
            'record',
            (string) $response->json('message')
        );

        // Still archived, and no "permanently_deleted" claim in the trail.
        $this->assertNotNull(User::withTrashed()->find($archived->user_id));
        $this->assertFalse(
            AuditTrail::where('action', 'force_delete')
                ->where('table_name', 'users')
                ->where('record_id', $archived->user_id)
                ->exists(),
            'The audit trail recorded a permanent deletion that was refused.'
        );
    }

    public function test_permanent_delete_frees_the_addresses_for_reuse(): void
    {
        Mail::fake();
        $admin    = $this->makeUser();
        $archived = $this->makeUser();

        $email    = $archived->email;
        $username = $archived->username;
        $recovery = $archived->recovery_email;

        $archived->delete();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/archive/users/' . $archived->user_id . '/force')
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/users', [
                'first_name'     => 'New',
                'last_name'      => 'Hire',
                'username'       => $username,
                'email'          => $email,
                'recovery_email' => $recovery,
                'role_id'        => 1,
                'status_id'      => 1,
            ])
            ->assertStatus(201);
    }
}
