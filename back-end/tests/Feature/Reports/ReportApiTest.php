<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;
use Illuminate\Support\Facades\Storage;

class ReportApiTest extends ReportTestCase
{
    /** Every file this test writes to real storage, deleted in tearDown regardless of pass/fail. */
    private array $writtenPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->writtenPaths as $path) {
            Storage::disk('local')->delete($path);
        }

        parent::tearDown();
    }

    public function test_catalog_lists_reports_with_declared_parameters(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/reports/catalog');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $catalog = $response->json('data');
        $lowStock = collect($catalog)->firstWhere('key', 'low-stock');

        $this->assertNotNull($lowStock, 'Low Stock report must be present in the catalog.');
        $this->assertArrayHasKey('title', $lowStock);
        $this->assertArrayHasKey('description', $lowStock);
        $this->assertArrayHasKey('formats', $lowStock);
        $this->assertArrayHasKey('parameters', $lowStock);

        $types = collect($lowStock['parameters'])->pluck('type');
        $this->assertNotContains(
            'date_range',
            $types,
            'Low Stock does not honour a date filter, so the dialog must not offer one.'
        );
    }

    /**
     * User Activity declares a `user` filter and is availableTo every
     * centralized user — admin, inventory_analyst and auditor. The dropdown
     * behind that filter cannot come from GET /users, which is gated on
     * user_account_management.read and seeded to admin alone: the other two
     * would 403 and land on an empty "All users" list, i.e. a filter the
     * dialog offers and the user cannot actually use.
     */
    public function test_an_auditor_can_load_the_user_filter_options(): void
    {
        $auditor = $this->makeUserAt($this->makeLocation(), 'auditor');
        $this->actingAs($auditor, 'sanctum');

        $response = $this->getJson('/api/reports/filter-users');

        $response->assertOk();

        $users = $response->json('data');
        $this->assertNotEmpty($users, 'An auditor must get a populated user list.');

        $mine = collect($users)->firstWhere('user_id', $auditor->user_id);
        $this->assertNotNull($mine);
        $this->assertArrayHasKey('first_name', $mine);
        $this->assertArrayHasKey('username', $mine);
    }

    /**
     * The dropdown needs a name and an id and nothing else. Serving the whole
     * user record here would hand every report viewer the contact details that
     * GET /users deliberately keeps behind user_account_management.
     */
    public function test_the_user_filter_options_carry_no_contact_details(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        $this->actingAs($admin, 'sanctum');

        $row = $this->getJson('/api/reports/filter-users')->assertOk()->json('data.0');

        foreach (['email', 'phone', 'address', 'avatar_path', 'password'] as $field) {
            $this->assertArrayNotHasKey($field, $row);
        }
    }

    public function test_the_user_filter_options_require_report_permission(): void
    {
        $noReports = $this->makeUserAt($this->makeLocation(), 'warehouse_personnel');
        $this->actingAs($noReports, 'sanctum');

        $this->getJson('/api/reports/filter-users')->assertForbidden();
    }

    public function test_generate_returns_the_run_record(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 1);
        $inventory->update(['reorder_level' => 5]);

        $this->actingAs($admin, 'sanctum');

        $response = $this->postJson('/api/reports/generate', [
            'report_key' => 'low-stock',
            'format'     => 'csv',
            'parameters' => ['location_id' => $location->location_id],
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $this->assertSame('low-stock', $response->json('data.report_key'));

        $filePath = $response->json('data.file_path');
        if ($filePath) {
            $this->writtenPaths[] = $filePath;
        }
    }

    public function test_an_empty_result_is_a_422_and_writes_no_row(): void
    {
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $this->actingAs($admin, 'sanctum');

        $before = ReportRun::count();

        $response = $this->postJson('/api/reports/generate', [
            'report_key' => 'low-stock',
            'format'     => 'csv',
            'parameters' => ['location_id' => $location->location_id],
        ]);

        $response->assertStatus(422);
        $this->assertSame($before, ReportRun::count());
    }

    public function test_generate_requires_the_create_permission(): void
    {
        // branch_manager holds neither report_viewing.read nor .create.
        $branchManager = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $this->actingAs($branchManager, 'sanctum');

        $response = $this->postJson('/api/reports/generate', [
            'report_key' => 'low-stock',
            'format'     => 'csv',
            'parameters' => [],
        ]);

        $response->assertStatus(403);
    }

    public function test_a_location_scoped_user_with_an_override_sees_a_scoped_catalog(): void
    {
        $branchManager = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $this->grantPermission($branchManager, 'report_viewing', 'read');

        $this->actingAs($branchManager, 'sanctum');

        $response = $this->getJson('/api/reports/catalog');
        $response->assertOk();

        $keys = collect($response->json('data'))->pluck('key');

        $this->assertNotContains(
            'user-activity',
            $keys,
            'user-activity is centralized-only and must not appear for a scoped user.'
        );
        $this->assertContains(
            'inventory-summary',
            $keys,
            'inventory-summary is available to any user and must appear.'
        );
    }

    public function test_downloading_an_expired_run_returns_410_not_404(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');

        $run = ReportRun::create([
            'report_key'      => 'inventory-summary',
            'title_snapshot'  => 'Inventory Summary Report',
            'format'          => 'pdf',
            'parameters'      => [],
            'file_path'       => null,
            'file_name'       => 'expired-test.pdf',
            'file_size_bytes' => 100,
            'row_count'       => 1,
            'generated_by'    => $admin->user_id,
            'expires_at'      => now()->subDay(),
            'file_pruned_at'  => now(),
        ]);

        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson("/api/reports/runs/{$run->report_run_id}/download");

        $response->assertStatus(410);
        $response->assertJson(['success' => false]);
    }

    /** A stored run belonging to someone else, with a real file behind it. */
    private function runBelongingTo(int $userId, string $reportKey, string $title): ReportRun
    {
        $path = 'reports/auth-check-' . uniqid() . '.csv';
        Storage::disk('local')->put($path, "col\nvalue\n");
        $this->writtenPaths[] = $path;

        return ReportRun::create([
            'report_key'      => $reportKey,
            'title_snapshot'  => $title,
            'format'          => 'csv',
            'parameters'      => [],
            'file_path'       => $path,
            'file_name'       => basename($path),
            'file_size_bytes' => Storage::disk('local')->size($path),
            'row_count'       => 1,
            'generated_by'    => $userId,
            'expires_at'      => now()->addDays(90),
        ]);
    }

    /**
     * report_viewing.read is granted through an editable DB table, so a
     * location-scoped role can hold it. Without a per-run check, the route
     * permission alone let that user download a User Activity run — a report
     * UserActivityReport::availableTo() excludes them from by design, since it
     * returns isCentralized(). Generation is scoped with forUser(); reading
     * has to honour the same boundary or the scoping is decorative.
     */
    public function test_a_run_of_a_report_the_user_may_not_access_is_not_downloadable(): void
    {
        $admin  = $this->makeUserAt($this->makeLocation(), 'admin');
        $scoped = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $this->grantPermission($scoped, 'report_viewing', 'read');

        $run = $this->runBelongingTo($admin->user_id, 'user-activity', 'User Activity Report');

        $this->actingAs($scoped, 'sanctum');

        $this->getJson("/api/reports/runs/{$run->report_run_id}/download")->assertNotFound();
    }

    /**
     * A run generated by a centralized user covers every location. Handing it
     * to a location-scoped user undoes the forUser() scoping its own reports
     * are built with.
     */
    public function test_a_scoped_user_cannot_download_another_users_run(): void
    {
        $admin  = $this->makeUserAt($this->makeLocation(), 'admin');
        $scoped = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $this->grantPermission($scoped, 'report_viewing', 'read');

        $run = $this->runBelongingTo($admin->user_id, 'inventory-summary', 'Inventory Summary Report');

        $this->actingAs($scoped, 'sanctum');

        $this->getJson("/api/reports/runs/{$run->report_run_id}/download")->assertNotFound();
    }

    public function test_a_scoped_user_can_still_download_their_own_run(): void
    {
        $scoped = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $this->grantPermission($scoped, 'report_viewing', 'read');

        $run = $this->runBelongingTo($scoped->user_id, 'inventory-summary', 'Inventory Summary Report');

        $this->actingAs($scoped, 'sanctum');

        $this->get("/api/reports/runs/{$run->report_run_id}/download")->assertOk();
    }

    public function test_the_runs_list_hides_runs_the_user_may_not_open(): void
    {
        $admin  = $this->makeUserAt($this->makeLocation(), 'admin');
        $scoped = $this->makeUserAt($this->makeLocation(), 'branch_manager');
        $this->grantPermission($scoped, 'report_viewing', 'read');

        $theirs = $this->runBelongingTo($admin->user_id, 'inventory-summary', 'Inventory Summary Report');
        $mine   = $this->runBelongingTo($scoped->user_id, 'inventory-summary', 'Inventory Summary Report');

        $this->actingAs($scoped, 'sanctum');

        $ids = collect($this->getJson('/api/reports/runs')->assertOk()->json('data'))
            ->pluck('report_run_id')
            ->all();

        $this->assertContains($mine->report_run_id, $ids);
        $this->assertNotContains($theirs->report_run_id, $ids,
            'A run the user cannot download must not be listed as if they could.');
    }

    /** The centralized roles that actually hold report_viewing lose nothing. */
    public function test_an_admin_still_sees_and_downloads_every_run(): void
    {
        $admin   = $this->makeUserAt($this->makeLocation(), 'admin');
        $auditor = $this->makeUserAt($this->makeLocation(), 'auditor');

        $run = $this->runBelongingTo($auditor->user_id, 'inventory-summary', 'Inventory Summary Report');

        $this->actingAs($admin, 'sanctum');

        $ids = collect($this->getJson('/api/reports/runs')->assertOk()->json('data'))
            ->pluck('report_run_id')
            ->all();

        $this->assertContains($run->report_run_id, $ids);
        $this->get("/api/reports/runs/{$run->report_run_id}/download")->assertOk();
    }

    public function test_the_seven_dead_closures_are_gone(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        $this->actingAs($admin, 'sanctum');

        $response = $this->getJson('/api/reports/low-stock-report');

        $response->assertStatus(404);
    }
}
