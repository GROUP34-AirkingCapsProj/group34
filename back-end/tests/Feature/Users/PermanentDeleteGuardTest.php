<?php

namespace Tests\Feature\Users;

use App\Models\AuditTrail;
use App\Models\LoginActivity;
use App\Models\User;
use App\Services\UserAttributionAudit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Permanently deleting a user account is not the same operation as permanently
 * deleting a product or a purchase order.
 *
 * Of the foreign keys pointing at `users`, all but a handful are ON DELETE SET
 * NULL -- audit_trail.user_id, purchase_orders.approved_by, adjustments
 * .adjusted_by, sales.sold_by and forty more. A force delete therefore does not
 * merely remove the account; it silently rewrites history, turning every
 * approval and adjustment that person ever made into an unattributed row with
 * no record of who it used to be. Only two of those keys are RESTRICT, so the
 * database stops almost none of it.
 *
 * So the escape hatch stays -- an admin who just mistyped an email while
 * creating an account needs it -- but it is now limited to accounts that have
 * no history to destroy. Freeing a used account's email address is what
 * release-identifiers is for.
 */
class PermanentDeleteGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(array $overrides = []): User
    {
        $uid = uniqid();

        return User::create(array_merge([
            'first_name'           => 'Force',
            'last_name'            => 'Target',
            'username'             => 'force_' . $uid,
            'email'                => 'force_' . $uid . '@airking.com',
            'recovery_email'       => 'force_' . $uid . '@gmail.com',
            'password_hash'        => bcrypt('Secret123!'),
            'must_change_password' => false,
            'role_id'              => 1,
            'status_id'            => 1,
        ], $overrides));
    }

    private function attributeAnAdjustmentTo(User $user): void
    {
        DB::table('adjustments')->insert([
            'adjustment_number' => 'ADJ-' . uniqid(),
            'adjusted_by'       => $user->user_id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function forceDelete(User $admin, User $target)
    {
        return $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/archive/users/' . $target->user_id . '/force');
    }

    /**
     * The case the hatch exists for: an account created by mistake minutes ago
     * that nobody has used. Nothing references it, so nothing is lost.
     */
    public function test_an_unused_account_can_still_be_permanently_deleted(): void
    {
        $admin  = $this->makeUser();
        $unused = $this->makeUser();
        $unused->delete();

        $this->forceDelete($admin, $unused)->assertStatus(200);

        $this->assertNull(User::withTrashed()->find($unused->user_id));
    }

    public function test_an_account_with_attributed_work_is_refused(): void
    {
        $admin  = $this->makeUser();
        $worker = $this->makeUser();
        $this->attributeAnAdjustmentTo($worker);
        $worker->delete();

        $response = $this->forceDelete($admin, $worker)->assertStatus(409);

        $this->assertStringContainsStringIgnoringCase(
            'adjustment',
            (string) $response->json('message'),
            'The refusal does not say what history would have been destroyed.'
        );

        // Still archived, and the attribution is untouched.
        $this->assertNotNull(User::withTrashed()->find($worker->user_id));
        $this->assertSame(
            1,
            DB::table('adjustments')->where('adjusted_by', $worker->user_id)->count()
        );
    }

    /** The refusal has to point at the way out, or it is just a dead end. */
    public function test_the_refusal_names_release_identifiers_as_the_way_to_free_the_email(): void
    {
        $admin  = $this->makeUser();
        $worker = $this->makeUser();
        $this->attributeAnAdjustmentTo($worker);
        $worker->delete();

        $this->assertStringContainsStringIgnoringCase(
            'release',
            (string) $this->forceDelete($admin, $worker)->json('message')
        );
    }

    /**
     * A stranger typing someone's address into the login form writes rows
     * against that account. Treating those as history would make a brand new
     * mistyped account undeletable by anyone but the person who guessed at it.
     */
    public function test_failed_logins_against_the_account_do_not_block_deletion(): void
    {
        $admin  = $this->makeUser();
        $unused = $this->makeUser();

        LoginActivity::create([
            'user_id'        => $unused->user_id,
            'username_attempt' => $unused->email,
            'success'        => false,
            'failure_reason' => 'Invalid credentials.',
            'ip_address'     => '127.0.0.1',
            'created_at'     => now(),
        ]);
        AuditTrail::create([
            'user_id'    => $unused->user_id,
            'action'     => 'login_failed',
            'table_name' => 'security',
            'record_id'  => $unused->user_id,
        ]);

        $unused->delete();

        $this->forceDelete($admin, $unused)->assertStatus(200);
    }

    /**
     * A successful login is the dividing line: the account was in someone's
     * hands and may have done anything the audit trail has since aged out of.
     */
    public function test_a_successful_login_blocks_deletion(): void
    {
        $admin = $this->makeUser();
        $used  = $this->makeUser();

        LoginActivity::create([
            'user_id'          => $used->user_id,
            'username_attempt' => $used->email,
            'success'          => true,
            'ip_address'       => '127.0.0.1',
            'created_at'       => now(),
        ]);

        $used->delete();

        $this->assertSame(409, $this->forceDelete($admin, $used)->status());
    }

    public function test_a_non_security_audit_row_blocks_deletion(): void
    {
        $admin = $this->makeUser();
        $used  = $this->makeUser();

        AuditTrail::create([
            'user_id'    => $used->user_id,
            'action'     => 'update',
            'table_name' => 'company_settings',
            'record_id'  => 1,
        ]);

        $used->delete();

        $this->assertSame(409, $this->forceDelete($admin, $used)->status());
    }

    /** The /users/{id}/force route is the same operation and gets the same guard. */
    public function test_the_user_module_force_route_is_guarded_too(): void
    {
        $admin  = $this->makeUser();
        $worker = $this->makeUser();
        $this->attributeAnAdjustmentTo($worker);
        $worker->delete();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/users/' . $worker->user_id . '/force')
            ->assertStatus(409);

        $this->assertNotNull(User::withTrashed()->find($worker->user_id));
    }

    /**
     * The guard is only as good as its list of columns, and that list is
     * hand-written: a new table with a `created_by` would otherwise be silently
     * unprotected, which is precisely the failure the guard exists to prevent.
     * This reads the real schema and fails when a foreign key to `users` is not
     * accounted for in exactly one bucket.
     */
    public function test_every_foreign_key_to_users_is_accounted_for(): void
    {
        $declared = [];
        foreach ([
            UserAttributionAudit::ATTRIBUTION,
            UserAttributionAudit::SECURITY_LOG,
            UserAttributionAudit::PLUMBING,
        ] as $bucket) {
            foreach ($bucket as $table => $columns) {
                foreach ($columns as $column) {
                    $key = $table . '.' . $column;
                    $this->assertArrayNotHasKey($key, $declared, "{$key} is listed in two buckets.");
                    $declared[$key] = true;
                }
            }
        }

        $missing = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            foreach (Schema::getForeignKeys($name) as $fk) {
                if (($fk['foreign_table'] ?? null) !== 'users') {
                    continue;
                }

                foreach ($fk['columns'] as $column) {
                    if (! isset($declared[$name . '.' . $column])) {
                        $missing[] = $name . '.' . $column;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            'These foreign keys to users are not classified in UserAttributionAudit, so a '
            . 'permanent delete would null them out unnoticed.'
        );
    }
}
