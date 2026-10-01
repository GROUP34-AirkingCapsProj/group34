<?php

namespace Tests\Feature\Audit;

use App\Models\AuditTrail;
use App\Models\LoginActivity;
use App\Models\Role;
use App\Models\StatusLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Filters on the Audit Trail activity feed (/api/audit-trail).
 *
 * The feed merges real audit_trail rows with unrecognized-account attempts
 * synthesized from login_activities, so every filter has to be checked against
 * both halves. The test DB carries unrelated history, so each case tags its own
 * rows with a unique table name and asserts on those.
 */
class AuditTrailFilterTest extends TestCase
{
    use DatabaseTransactions;

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tag = 'atf_' . uniqid();
    }

    private function activeStatusId(): int
    {
        $existing = StatusLookup::first();
        if ($existing) {
            return (int) $existing->status_id;
        }

        return (int) StatusLookup::forceCreate([
            'status_name'     => 'Active',
            'status_category' => 'user',
        ])->status_id;
    }

    private function makeUser(string $roleName, string $email = null): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::forceCreate([
            'first_name'    => 'Audit',
            'last_name'     => 'Filter',
            'email'         => $email ?: ($roleName . '_' . uniqid() . '@test.local'),
            'username'      => $roleName . '_' . uniqid(),
            'password_hash' => Hash::make('password'),
            'role_id'       => $role->role_id,
            'status_id'     => $this->activeStatusId(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function makeAudit(array $attributes): AuditTrail
    {
        $row = AuditTrail::forceCreate(array_merge([
            'user_id'    => null,
            'action'     => 'update',
            'table_name' => $this->tag,
            'record_id'  => 1,
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'phpunit',
        ], $attributes));

        if (isset($attributes['created_at'])) {
            // forceCreate stamps now(); the date filters need the backdated value.
            AuditTrail::where('audit_id', $row->audit_id)
                ->update(['created_at' => $attributes['created_at']]);
            $row->refresh();
        }

        return $row;
    }

    /** @param array<string, mixed> $query @return array<int, array<string, mixed>> */
    private function fetch(User $admin, array $query): array
    {
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-trail?' . http_build_query($query + ['per_page' => 200]));

        $response->assertStatus(200);

        return $response->json('data');
    }

    public function test_table_name_filter_returns_only_that_table(): void
    {
        $admin = $this->makeUser('admin');
        $mine  = $this->makeAudit([]);
        $other = $this->makeAudit(['table_name' => $this->tag . '_other']);

        $ids = array_column($this->fetch($admin, ['table_name' => $this->tag]), 'audit_id');

        $this->assertContains($mine->audit_id, $ids);
        $this->assertNotContains($other->audit_id, $ids);
    }

    public function test_action_filter_returns_only_that_action(): void
    {
        $admin   = $this->makeUser('admin');
        $created = $this->makeAudit(['action' => 'create']);
        $deleted = $this->makeAudit(['action' => 'delete']);

        $ids = array_column(
            $this->fetch($admin, ['table_name' => $this->tag, 'action' => 'create']),
            'audit_id'
        );

        $this->assertContains($created->audit_id, $ids);
        $this->assertNotContains($deleted->audit_id, $ids);
    }

    public function test_user_filter_returns_only_that_users_rows(): void
    {
        $admin = $this->makeUser('admin');
        $actor = $this->makeUser('branch_manager');
        $theirs = $this->makeAudit(['user_id' => $actor->user_id]);
        $nobodys = $this->makeAudit([]);

        $ids = array_column(
            $this->fetch($admin, ['table_name' => $this->tag, 'user_id' => $actor->user_id]),
            'audit_id'
        );

        $this->assertContains($theirs->audit_id, $ids);
        $this->assertNotContains($nobodys->audit_id, $ids);
    }

    public function test_search_matches_record_id_and_actor_email(): void
    {
        $admin = $this->makeUser('admin');
        $email = 'needle_' . uniqid() . '@test.local';
        $actor = $this->makeUser('branch_manager', $email);

        $byRecord = $this->makeAudit(['record_id' => 987654]);
        $byEmail  = $this->makeAudit(['user_id' => $actor->user_id, 'record_id' => 11]);

        $recordIds = array_column($this->fetch($admin, ['search' => '987654']), 'audit_id');
        $this->assertContains($byRecord->audit_id, $recordIds);

        $emailIds = array_column($this->fetch($admin, ['search' => $email]), 'audit_id');
        $this->assertContains($byEmail->audit_id, $emailIds);
        $this->assertNotContains($byRecord->audit_id, $emailIds);
    }

    public function test_date_range_bounds_the_feed(): void
    {
        $admin = $this->makeUser('admin');
        $old   = $this->makeAudit(['created_at' => now()->subDays(10)]);
        $fresh = $this->makeAudit(['created_at' => now()->subDay()]);

        $ids = array_column($this->fetch($admin, [
            'table_name' => $this->tag,
            'date_from'  => now()->subDays(3)->toDateString(),
            'date_to'    => now()->toDateString(),
        ]), 'audit_id');

        $this->assertContains($fresh->audit_id, $ids);
        $this->assertNotContains($old->audit_id, $ids);
    }

    public function test_action_filter_excludes_synthesized_unauthorized_attempts(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeAudit(['action' => 'create']);

        LoginActivity::forceCreate([
            'user_id'          => null,
            'username_attempt' => 'ghost_' . uniqid() . '@test.local',
            'success'          => false,
            'failure_reason'   => 'unknown_account',
            'ip_address'       => '203.0.113.9',
            'user_agent'       => 'phpunit',
            'created_at'       => now(),
        ]);

        $actions = array_column(
            $this->fetch($admin, ['table_name' => $this->tag, 'action' => 'create']),
            'action'
        );

        $this->assertNotEmpty($actions);
        $this->assertSame(['create'], array_values(array_unique($actions)));
    }

    public function test_unauthorized_action_filter_keeps_synthesized_attempts(): void
    {
        $admin = $this->makeUser('admin');
        $ip    = '203.0.113.' . random_int(10, 250);

        LoginActivity::forceCreate([
            'user_id'          => null,
            'username_attempt' => 'ghost_' . uniqid() . '@test.local',
            'success'          => false,
            'failure_reason'   => 'unknown_account',
            'ip_address'       => $ip,
            'user_agent'       => 'phpunit',
            'created_at'       => now(),
        ]);

        $rows = $this->fetch($admin, ['action' => 'unauthorized_access', 'search' => $ip]);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('unauthorized_access', $row['action']);
        }
        $this->assertContains($ip, array_column($rows, 'ip_address'));
    }
}
