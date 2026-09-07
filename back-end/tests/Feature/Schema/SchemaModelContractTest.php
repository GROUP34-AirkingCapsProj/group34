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
        // TransactionController still queries this model on every method. The
        // controller has no routes registered, so nothing can reach it today.
        // Kept on purpose: the transactions feature is to be fixed rather than
        // dropped, and deleting the model now would erase the shape it needs.
        'App\Models\Transaction' => 'transactions feature pending a fix; controller currently unrouted',
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
