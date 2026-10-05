<?php

namespace Tests\Feature\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * This project's schema baseline is database/ci-schema.sql — a dump, not a
 * migration chain (see .github/workflows/ci.yml). Nothing mechanically checks
 * that the tables the code expects are tables the database actually has, so a
 * model can be written, reviewed and merged against a table nobody created.
 *
 * That has already cost real bugs. App\Models\ActivityLog read and wrote
 * `activity_log`, which did not exist; the controllers swallowed the resulting
 * "Base table or view not found" so the gap stayed invisible until the barcode
 * scanner's Audit mode — which writes outside any try/catch — started 500ing.
 * A sweep afterwards found five more models in the same state, one of them
 * (AuditLog) behind a live registered route at GET /api/audit-log.
 *
 * These two tests close that hole permanently.
 */
class SchemaModelContractTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Models deliberately kept despite having no backing table.
     *
     * Anything listed here is a known, accepted debt — not an oversight. Adding
     * an entry should be a decision someone makes on purpose, which is the
     * whole point of making the list explicit rather than letting the test
     * quietly skip things.
     */
    private const QUARANTINED = [
        // Empty on purpose: every model now points at a table that exists.
        // App\Models\Transaction used to sit here, held back for a transactions
        // feature that was never built — no table, no routes, and never any
        // routes in the project's history. It and its controller were removed
        // rather than fixed, so the debt is settled instead of deferred.
    ];

    /** @return list<class-string<Model>> */
    private function eloquentModels(): array
    {
        $models = [];

        foreach (glob(base_path('app/Models/*.php')) as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        return $models;
    }

    public function test_every_model_points_at_a_table_that_exists(): void
    {
        $models = $this->eloquentModels();
        $this->assertNotEmpty($models, 'Found no models to check — the glob is wrong.');

        $orphans = [];

        foreach ($models as $class) {
            if (array_key_exists($class, self::QUARANTINED)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (! Schema::hasTable($table)) {
                $orphans[] = sprintf('%s -> `%s`', $class, $table);
            }
        }

        $this->assertSame(
            [],
            $orphans,
            "These models read and write tables that do not exist. Every query "
            . "they make fails at runtime, and controllers that catch broadly "
            . "will hide it:\n  " . implode("\n  ", $orphans)
        );
    }

    public function test_quarantined_models_are_still_genuinely_missing(): void
    {
        // An empty list is the goal state, not a gap in coverage: it means
        // test_every_model_points_at_a_table_that_exists covers every model
        // with no exemptions. Asserted explicitly so this stays a real test
        // rather than a loop over nothing.
        $this->assertIsArray(self::QUARANTINED);

        foreach (self::QUARANTINED as $class => $why) {
            $table = (new $class)->getTable();

            $this->assertFalse(
                Schema::hasTable($table),
                "{$class} is quarantined as having no table ({$why}), but `{$table}` "
                . "now exists. Remove it from QUARANTINED so the contract covers it."
            );
        }
    }
}
