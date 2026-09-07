<?php

namespace Tests\Unit;

use App\Support\ExactStringMatch;
use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the SQL that ExactStringMatch compiles for each driver.
 *
 * These tests never open a database connection: a Connection is constructed
 * with a PDO resolver that is never invoked, and only ->toSql() is called. That
 * is what makes it possible to verify the PostgreSQL output on a machine with
 * no PostgreSQL server and no pdo_pgsql extension — which is exactly the
 * situation this refactor has to be trusted from.
 *
 * The behaviour under test is narrow but load-bearing: emitting MySQL's BINARY
 * against PostgreSQL is a hard syntax error, and omitting it against MySQL
 * silently matches case-insensitively, which lets a lookup rename rewrite rows
 * it was never meant to touch.
 */
class ExactStringMatchTest extends TestCase
{
    private function builderFor(string $driver): Builder
    {
        // The closure stands in for a PDO instance. It is only called when a
        // query is actually executed, and these tests never execute one.
        $pdoResolver = fn () => null;

        // Connection::getDriverName() reads config['driver'], so the driver has
        // to be supplied the same way config/database.php supplies it.
        $config = ['driver' => $driver];

        $connection = match ($driver) {
            'mysql' => new MySqlConnection($pdoResolver, 'airking', '', $config),
            'mariadb' => new MariaDbConnection($pdoResolver, 'airking', '', $config),
            'pgsql' => new PostgresConnection($pdoResolver, 'airking', '', $config),
            'sqlite' => new SQLiteConnection($pdoResolver, 'airking', '', $config),
            'sqlsrv' => new SqlServerConnection($pdoResolver, 'airking', '', $config),
        };

        // Guard against a Laravel upgrade quietly renaming a driver string;
        // every match arm in ExactStringMatch keys off exactly this value.
        $this->assertSame($driver, $connection->getDriverName());

        return $connection->query()->from('capacity_lookup');
    }

    public function test_postgres_uses_a_plain_equality_and_never_emits_binary(): void
    {
        $query = ExactStringMatch::apply($this->builderFor('pgsql'), 'capacity_value', '1.5HP');

        $this->assertSame(
            'select * from "capacity_lookup" where "capacity_value" = ?',
            $query->toSql()
        );

        // The whole point: BINARY is a MySQL cast with no PostgreSQL equivalent,
        // and leaking it here would be a syntax error at runtime, not a subtly
        // wrong result.
        $this->assertStringNotContainsStringIgnoringCase('binary', $query->toSql());

        $this->assertSame(['1.5HP'], $query->getBindings());
    }

    public function test_mysql_pairs_a_sargable_equality_with_a_binary_comparison(): void
    {
        $query = ExactStringMatch::apply($this->builderFor('mysql'), 'capacity_value', '1.5HP');

        $this->assertSame(
            'select * from `capacity_lookup` where `capacity_value` = ? and BINARY `capacity_value` = ?',
            $query->toSql()
        );

        // Both predicates bind the same value, in order.
        $this->assertSame(['1.5HP', '1.5HP'], $query->getBindings());
    }

    public function test_mariadb_is_treated_as_mysql(): void
    {
        // The production MySQL box and the developer XAMPP box report different
        // driver names for what is, here, the same dialect.
        $query = ExactStringMatch::apply($this->builderFor('mariadb'), 'capacity_value', '1.5HP');

        $this->assertStringContainsString('BINARY `capacity_value` = ?', $query->toSql());
    }

    public function test_sqlite_uses_a_plain_equality(): void
    {
        // SQLite TEXT defaults to BINARY collation, so `=` is already exact.
        $query = ExactStringMatch::apply($this->builderFor('sqlite'), 'capacity_value', '1.5HP');

        $this->assertSame(
            'select * from "capacity_lookup" where "capacity_value" = ?',
            $query->toSql()
        );
    }

    public function test_sqlserver_forces_a_binary_collation(): void
    {
        // SQL Server's default collation is case-insensitive, so unlike
        // Postgres and SQLite a plain `=` there would NOT be exact.
        $query = ExactStringMatch::apply($this->builderFor('sqlsrv'), 'capacity_value', '1.5HP');

        $this->assertStringContainsString('COLLATE Latin1_General_BIN2 = ?', $query->toSql());
    }

    public function test_unknown_driver_fails_loudly_rather_than_matching_loosely(): void
    {
        $connection = new Connection(fn () => null, 'airking', '', ['driver' => 'firebird']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('firebird');

        ExactStringMatch::apply($connection->query()->from('capacity_lookup'), 'capacity_value', 'x');
    }

    public function test_column_names_are_wrapped_by_the_grammar_not_interpolated(): void
    {
        // The column is interpolated into a raw fragment on MySQL, so it must go
        // through the grammar. Quoting also means a column that collides with a
        // reserved word still compiles.
        $query = ExactStringMatch::apply($this->builderFor('mysql'), 'order', 'x');

        $this->assertStringContainsString('BINARY `order` = ?', $query->toSql());
    }

    public function test_macro_is_registered_and_delegates_to_the_same_rules(): void
    {
        // Call sites use ->whereExactString(); this is the wiring in
        // AppServiceProvider that makes that resolve.
        $viaMacro = $this->builderFor('pgsql')->whereExactString('capacity_value', '1.5HP');
        $viaClass = ExactStringMatch::apply($this->builderFor('pgsql'), 'capacity_value', '1.5HP');

        $this->assertSame($viaClass->toSql(), $viaMacro->toSql());
        $this->assertSame($viaClass->getBindings(), $viaMacro->getBindings());
    }
}
