<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

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

    /**
     * Sanctum::actingAs() authenticates with a Mockery double standing in for
     * the PersonalAccessToken. Every route in this app's authenticated group
     * runs ExtendSanctumTokenExpiration, which calls
     * currentAccessToken()->forceFill(...)->save() on every request to slide
     * the session — a real method the double has no expectation for. Give it
     * one so the mock does not blow up on a call that has nothing to do with
     * what these tests are actually checking.
     */
    private function actingAsUser($user): void
    {
        Sanctum::actingAs($user);

        $user->currentAccessToken()->shouldReceive('forceFill')->andReturnSelf();
        $user->currentAccessToken()->shouldReceive('save')->andReturnTrue();
    }

    public function test_catalog_lists_reports_with_declared_parameters(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        $this->actingAsUser($admin);

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

    public function test_generate_returns_the_run_record(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 1);
        $inventory->update(['reorder_level' => 5]);

        $this->actingAsUser($admin);

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

        $this->actingAsUser($admin);

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
        $this->actingAsUser($branchManager);

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

        $this->actingAsUser($branchManager);

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

        $this->actingAsUser($admin);

        $response = $this->getJson("/api/reports/runs/{$run->report_run_id}/download");

        $response->assertStatus(410);
        $response->assertJson(['success' => false]);
    }

    public function test_the_seven_dead_closures_are_gone(): void
    {
        $admin = $this->makeUserAt($this->makeLocation(), 'admin');
        $this->actingAsUser($admin);

        $response = $this->getJson('/api/reports/low-stock-report');

        $response->assertStatus(404);
    }
}
