<?php

namespace Tests\Feature\Permissions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Explorer is read-only by construction: the only thing any holder can write is
 * a resolution note on a drift finding, and only the auditor can do that. An admin
 * deliberately does not hold it — the person who resolves an exception should not be
 * the person who approves the spending it might concern.
 *
 * The branch manager reads it as the admin's deputy, alongside the audit trail and
 * the exception list it already held, but does not resolve.
 */
class AuditEvidencePermissionTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array<int,string> role names granted $action on audit_evidence */
    private function rolesWith(string $action): array
    {
        return DB::table('permissions as p')
            ->join('role_permissions as rp', 'rp.permission_id', '=', 'p.permission_id')
            ->join('roles as r', 'r.role_id', '=', 'rp.role_id')
            ->where('p.feature', 'audit_evidence')
            ->where('p.action', $action)
            ->where('rp.is_granted', 1)
            ->pluck('r.role_name')
            ->sort()
            ->values()
            ->all();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Two seeders, in this order: PermissionSeeder creates the permission rows
        // from the matrix, and RolePermissionsSeeder is what actually turns the
        // matrix's role lists into role_permissions grants. Running only the first
        // leaves the feature defined but granted to nobody.
        $this->artisan('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
        $this->artisan('db:seed', ['--class' => 'RolePermissionsSeeder', '--force' => true]);
    }

    public function test_the_feature_exists_after_seeding(): void
    {
        $this->assertSame(
            2,
            DB::table('permissions')->where('feature', 'audit_evidence')->count(),
            'audit_evidence should declare exactly read and resolve_exception.'
        );
    }

    public function test_read_is_granted_to_admin_branch_manager_and_auditor(): void
    {
        $this->assertSame(['admin', 'auditor', 'branch_manager'], $this->rolesWith('read'));
    }

    /**
     * The branch manager reads the evidence but does not resolve a finding: holding
     * both would make the role that approves a branch's spending the same one that
     * signs off the discrepancy it produced.
     */
    public function test_the_branch_manager_reads_but_does_not_resolve(): void
    {
        $this->assertContains('branch_manager', $this->rolesWith('read'));
        $this->assertNotContains('branch_manager', $this->rolesWith('resolve_exception'));
    }

    public function test_only_the_auditor_may_resolve_an_exception(): void
    {
        $this->assertSame(['auditor'], $this->rolesWith('resolve_exception'));
    }
}
