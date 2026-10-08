<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Byte-exact ("case-sensitive") string equality, expressed per database driver.
 *
 * The lookup tables can legitimately hold values differing only in case —
 * "1.5HP" and "1.5hp" are two distinct rows, because products reference the
 * exact string rather than a foreign key. Matching them case-insensitively
 * conflates the two: renaming "1.5HP" would silently rewrite every product
 * holding "1.5hp" and orphan that second lookup row.
 *
 * What it takes to get an exact match differs by engine, which is the whole
 * reason this class exists:
 *
 *   - MySQL / MariaDB: the columns are utf8mb4_unicode_ci, so plain `=` is
 *     case-insensitive AND ignores trailing spaces. `BINARY col = ?` forces the
 *     byte comparison. It is emitted alongside a plain `=` rather than on its
 *     own: `BINARY col = ?` is non-sargable and would ignore the column's
 *     index outright, so the plain predicate narrows through the index first
 *     and the BINARY one confirms the bytes. AND-ing them is free — a
 *     byte-equal value is always ci-equal too, so the pair selects exactly what
 *     the BINARY predicate alone would.
 *
 *   - PostgreSQL: `=` on text/varchar is already byte-exact. Casting is not
 *     just unnecessary, it is wrong — Postgres has no `BINARY` operator and the
 *     statement is a syntax error.
 *
 *   - SQLite: TEXT columns default to BINARY collation, so `=` is exact.
 *
 *   - SQL Server: the default collation is case-insensitive, so an explicit
 *     binary collation is required.
 *
 * Note for the PostgreSQL migration: this equivalence holds for `text` and
 * `varchar`. It would NOT hold for `char(n)`, which Postgres blank-pads on
 * comparison the way MySQL's ci collation does. The lookup columns are all
 * varchar, so there is nothing to reconcile today.
 */
final class ExactStringMatch
{
    /**
     * Constrain $query to rows whose $column equals $value byte-for-byte.
     *
     * @param  Builder  $query   Query builder to constrain (mutated in place).
     * @param  string   $column  Column name. Wrapped by the connection's
     *                           grammar, so it is quoted correctly per engine.
     */
    public static function apply(Builder $query, string $column, ?string $value): Builder
    {
        $driver = $query->getConnection()->getDriverName();

        // Quote through the grammar rather than interpolating the bare name:
        // it produces `col` on MySQL and "col" on Postgres, and keeps a column
        // name from ever being read as SQL.
        $wrapped = $query->getGrammar()->wrap($column);

        return match ($driver) {
            // See the class docblock for why this is the paired form.
            'mysql', 'mariadb' => $query
                ->where($column, $value)
                ->whereRaw("BINARY {$wrapped} = ?", [$value]),

            // Already byte-exact; a plain predicate is both correct and sargable.
            'pgsql', 'sqlite' => $query->where($column, $value),

            'sqlsrv' => $query
                ->where($column, $value)
                ->whereRaw("{$wrapped} COLLATE Latin1_General_BIN2 = ?", [$value]),

            // Better to fail loudly than to silently apply a case-insensitive
            // match and let a rename quietly corrupt unrelated rows.
            default => throw new RuntimeException(
                "ExactStringMatch has no rule for database driver [{$driver}]; "
                . 'add one before using this driver, or exact lookup matching will be wrong.'
            ),
        };
    }
}
