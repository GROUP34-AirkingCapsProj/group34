<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;
use App\Models\User;
use App\Reports\EmptyReportException;
use App\Reports\ReportDataset;
use App\Reports\ReportDefinition;
use App\Reports\ReportParameter;
use App\Reports\ReportParameters;
use App\Reports\ReportRegistry;
use App\Reports\ReportRunner;
use App\Reports\Renderers\CsvReportRenderer;
use App\Reports\Renderers\PdfReportRenderer;
use App\Reports\RowCapExceededException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportRunnerTest extends ReportTestCase
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

    private function runner(): ReportRunner
    {
        return new ReportRunner(app(ReportRegistry::class), app(PdfReportRenderer::class), app(CsvReportRenderer::class));
    }

    /**
     * A minimal in-test-only ReportDefinition, used solely to exercise the
     * row-cap check without needing to seed thousands of real rows through
     * one of the six real definitions (none of which declares a cap under
     * 500). It is a pure test double — none of the real definitions,
     * registry, or renderers are modified to make this possible.
     */
    private function fakeDefinition(int $cap, int $rowCount): ReportDefinition
    {
        return new class($cap, $rowCount) implements ReportDefinition {
            public function __construct(private int $cap, private int $rowCount)
            {
            }

            public function key(): string
            {
                return 'fake-cap-report';
            }

            public function title(): string
            {
                return 'Fake Cap Report';
            }

            public function description(): string
            {
                return 'Test double for row-cap enforcement.';
            }

            public function parameters(): array
            {
                return [];
            }

            public function formats(): array
            {
                return ['pdf', 'csv'];
            }

            public function pdfRowCap(): int
            {
                return $this->cap;
            }

            public function availableTo(User $user): bool
            {
                return true;
            }

            public function build(ReportParameters $params, User $user): ReportDataset
            {
                $rows = [];
                for ($i = 0; $i < $this->rowCount; $i++) {
                    $rows[] = ['n' => $i];
                }

                return new ReportDataset(columns: ['n' => 'N'], rows: $rows);
            }
        };
    }

    /** A registry whose get() answers a single fake key from the fake definition, delegating everything else. */
    private function registryWithFake(ReportDefinition $fake): ReportRegistry
    {
        return new class($fake) extends ReportRegistry {
            public function __construct(private ReportDefinition $fake)
            {
            }

            public function get(string $key): ?ReportDefinition
            {
                return $key === $this->fake->key() ? $this->fake : parent::get($key);
            }
        };
    }

    public function test_a_successful_run_stores_the_file_and_records_the_run(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 2);
        $inventory->update(['reorder_level' => 5]);

        $before = ReportRun::count();

        // Scoped to this test's own location so pre-seeded rows in the
        // shared test DB cannot inflate row_count.
        $run = $this->runner()->run('low-stock', 'csv', ['location_id' => $location->location_id], $admin);
        $this->writtenPaths[] = $run->file_path;

        $this->assertSame($before + 1, ReportRun::count());

        $fresh = ReportRun::find($run->report_run_id);
        $this->assertNotNull($fresh);
        $this->assertTrue(Storage::disk('local')->exists($fresh->file_path));

        $actualSize = Storage::disk('local')->size($fresh->file_path);
        $this->assertSame($actualSize, $fresh->file_size_bytes);
        $this->assertGreaterThan(0, $fresh->file_size_bytes);
        $this->assertSame(1, $fresh->row_count);
        $this->assertSame('low-stock', $fresh->report_key);
        $this->assertSame((int) $admin->user_id, (int) $fresh->generated_by);
        $this->assertNotNull($fresh->expires_at);
        $this->assertEqualsWithDelta(
            now()->addDays(90)->timestamp,
            $fresh->expires_at->timestamp,
            5,
            'expires_at must be ~90 days out.'
        );
    }

    /**
     * The stored path was {Title}_{Y-m-d_His}.{ext} — second granularity and
     * nothing else — so two runs of the same report and format inside one
     * second produced the same path. Storage::put overwrote, both rows pointed
     * at one file, and downloading the first served the second's data under
     * the first's filters and row count. Worse, pruning the first deleted the
     * shared file and the second 404'd while still reporting is_expired false.
     *
     * Time is frozen so the collision is deterministic rather than dependent
     * on the suite happening to cross a second boundary.
     */
    public function test_two_runs_in_the_same_second_do_not_overwrite_each_other(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 2);
        $inventory->update(['reorder_level' => 5]);

        $this->travelTo(now()->startOfSecond());

        $first  = $this->runner()->run('low-stock', 'csv', ['location_id' => $location->location_id], $admin);
        $second = $this->runner()->run('low-stock', 'csv', ['location_id' => $location->location_id], $admin);
        $this->writtenPaths[] = $first->file_path;
        $this->writtenPaths[] = $second->file_path;

        $this->assertNotSame($first->file_path, $second->file_path,
            'Two runs in the same second must not share a stored file.');

        $this->assertTrue(Storage::disk('local')->exists($first->file_path));
        $this->assertTrue(Storage::disk('local')->exists($second->file_path));

        // Deleting one run's file must leave the other's intact.
        Storage::disk('local')->delete($first->file_path);
        $this->assertTrue(Storage::disk('local')->exists($second->file_path),
            'Pruning one run must not remove another run\'s file.');
    }

    public function test_an_empty_dataset_throws_and_leaves_no_report_run_row(): void
    {
        // A location with no inventory rows at all cannot produce any low-stock line.
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $before = ReportRun::count();

        try {
            $this->runner()->run('low-stock', 'csv', ['location_id' => $location->location_id], $admin);
            $this->fail('Expected EmptyReportException.');
        } catch (EmptyReportException $e) {
            // expected
        }

        $this->assertSame($before, ReportRun::count());
    }

    public function test_an_unsupported_parameter_is_rejected(): void
    {
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        // Low Stock declares only location_id/category_id — no date range at all.
        $this->expectException(ValidationException::class);

        $this->runner()->run('low-stock', 'csv', ['date_from' => '2026-01-01'], $admin);
    }

    public function test_a_format_the_report_does_not_declare_is_rejected(): void
    {
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $this->expectException(\InvalidArgumentException::class);

        $this->runner()->run('low-stock', 'xlsx', [], $admin);
    }

    public function test_the_filename_carries_the_report_title_and_a_timestamp(): void
    {
        $location = $this->makeLocation();
        $product  = $this->makeAppliance();
        $admin    = $this->makeUserAt($location, 'admin');

        $inventory = $this->setStock($location->location_id, $product->product_id, 1);
        $inventory->update(['reorder_level' => 5]);

        $run = $this->runner()->run('low-stock', 'csv', ['location_id' => $location->location_id], $admin);
        $this->writtenPaths[] = $run->file_path;

        $this->assertMatchesRegularExpression(
            '/^Low_Stock_Report_\d{4}-\d{2}-\d{2}_\d{6}\.csv$/',
            $run->file_name
        );

        // The stored path is NOT 'reports/' . file_name: it carries a random
        // suffix so two runs in the same second cannot share a file (see
        // ReportRunner::storedPath). The clean name is what the user gets
        // back on download; the unique one is what is on disk.
        $this->assertMatchesRegularExpression(
            '/^reports\/Low_Stock_Report_\d{4}-\d{2}-\d{2}_\d{6}_[A-Za-z0-9]{8}\.csv$/',
            $run->file_path
        );
    }

    /**
     * The row-cap check is exercised against a test-only fake definition
     * (see fakeDefinition()/registryWithFake() above) rather than against
     * any real report. Every real definition declares a cap of 500-5000
     * rows; seeding that many rows through a real definition just to cross
     * its cap would make this test slow and would not exercise any
     * different logic in ReportRunner than a small, controlled fake does —
     * the cap check only ever looks at $definition->pdfRowCap() and
     * $dataset->rowCount(), both of which the fake controls directly.
     */
    public function test_pdf_above_the_row_cap_throws(): void
    {
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $fake = $this->fakeDefinition(cap: 5, rowCount: 6);
        $runner = new ReportRunner($this->registryWithFake($fake), app(PdfReportRenderer::class), app(CsvReportRenderer::class));

        $before = ReportRun::count();

        $this->expectException(RowCapExceededException::class);

        try {
            $runner->run('fake-cap-report', 'pdf', [], $admin);
        } finally {
            $this->assertSame($before, ReportRun::count());
        }
    }

    /** CSV is unaffected by the same cap — proves the cap is PDF-specific, not a blanket row limit. */
    public function test_csv_is_not_subject_to_the_pdf_row_cap(): void
    {
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $fake = $this->fakeDefinition(cap: 5, rowCount: 6);
        $runner = new ReportRunner($this->registryWithFake($fake), app(PdfReportRenderer::class), app(CsvReportRenderer::class));

        $run = $runner->run('fake-cap-report', 'csv', [], $admin);
        $this->writtenPaths[] = $run->file_path;

        $this->assertSame(6, $run->row_count);
    }

    public function test_declared_parameter_defaults_are_applied_when_omitted(): void
    {
        // makeLocation() already defaults to location_type=warehouse, so a
        // freshly created location satisfies WarehouseUtilizationReport's
        // default filter without an explicit location_type in the request.
        $location = $this->makeLocation();
        $admin    = $this->makeUserAt($location, 'admin');

        $run = $this->runner()->run('warehouse-utilization', 'csv', [
            'date_from' => '2026-01-01',
            'date_to'   => '2026-01-31',
        ], $admin);
        $this->writtenPaths[] = $run->file_path;

        $this->assertSame('warehouse', $run->parameters['location_type']);
    }
}
