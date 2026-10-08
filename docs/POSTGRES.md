# PostgreSQL port — status and how to work on it

The app targets MySQL/MariaDB in development and PostgreSQL (Aiven) in
production. This document records what is done, what is not, and how to run the
suite against PostgreSQL locally.

## Deploying to a fresh Aiven database

```bash
php artisan migrate --force
```

That is the whole procedure. On a database with no applied migrations Laravel
loads `back-end/database/schema/pgsql-schema.sql` and then reports
`Nothing to migrate`, because the schema file restores the `migrations` table
along with the 89 tables.

**`psql` must be on PATH** — Laravel shells out to it
(`PostgresSchemaState::load`). The production image installs it via
`postgresql-client`; see the root `Dockerfile`.

## Migrating an already-deployed database

A database that was deployed from an earlier baseline is *not* fresh: its
`migrations` table was written by whichever baseline it loaded, so everything
added since actually runs. Read what the target has rather than trusting the
committed baseline — on 2026-10-07 Aiven held 133 rows and 86 tables while the
baseline claimed 137, and the eight-migration gap included two that could not
run on PostgreSQL at all.

```bash
php artisan migrate --database=pgsql_target --force
```

`pgsql_target` is the `PG_*` block in `back-end/.env` (see
[DATA-TRANSFER.md](DATA-TRANSFER.md)), so this runs from a developer machine
against the live database and needs no deploy. Back up first — see below.

**Check every pending migration for MySQL-only SQL before running it.** The 15
historical ones are safe because the baseline marks them applied, but anything
newer executes for real. `ALTER TABLE ... MODIFY`, backticked identifiers,
`database()` and inline `COMMENT` are the usual offenders; Laravel's schema
builder and `$table->comment()` are portable.

## Backing up the live database

`BackupService` — the in-app backup feature — shells out to `mysqldump` and
only ever covered local MySQL. **Production has no automated backup.** Until
that is ported, this is the only one:

```powershell
.\back-end\scripts\backup-aiven.ps1
```

Reads the `PG_*` block from `.env` and writes a `pg_dump` custom-format archive
to `backups/` (git-ignored). The password goes to pg_dump through the child
process's `PGPASSWORD` only — never on a command line, never to the console.

Needs `pg_dump` **at least as new as the server** (Aiven runs PostgreSQL 18.6);
it searches PATH, then `~/Downloads/**/pgsql/bin`, then `C:\pgsql\bin`, or pass
`-PgDump`. The EnterpriseDB *binaries zip* is enough — no installer, no Windows
service, no registry: unzip it and delete the folder to uninstall.

A dump takes a few minutes over a home connection and only appears at the end.
Verify before trusting it — a backup that has never been read is not a backup:

```powershell
& 'C:\path	o\pg_restore.exe' --list backupsiven-....dump
```

Expect 86+ `TABLE DATA` entries and 223 `FK CONSTRAINT` entries. The script
refuses any dump under 1 KB, which is what an aborted run leaves behind.

## Starting the live system fresh

```bash
php artisan data:reset-transactional --connection=pgsql_target --dry-run
php artisan data:reset-transactional --connection=pgsql_target
```

Empties every transactional table and keeps the item master, lookups, users and
access control — the "go live without re-entering the catalogue" operation.
`ResetTransactionalData::KEEP` is the explicit list and **everything else is
cleared**, so a transactional table added later is caught by default rather than
silently surviving. `--dry-run` prints every table and row count first.

It uses one `TRUNCATE ... RESTART IDENTITY` over all cleared tables, without
`CASCADE`. PostgreSQL refuses to truncate a table another references unless that
one is listed too, so PostgreSQL itself proves the list is complete; `CASCADE`
would hide exactly that error while emptying kept tables. `RESTART IDENTITY`
means the first record after a reset is id 1.

Clearing `personal_access_tokens` and `sessions` logs everyone out. Passwords
are unaffected — they live in `users`, which is kept.

There is no default `--connection` on purpose.

## Regenerating the baseline schema

`pgsql-schema.sql` is generated from the live MySQL schema, not hand-written:

```bash
php artisan schema:export-pgsql --with-reference-data
```

Re-run it after any migration that changes the schema, or the baseline goes
stale. `PostgresBaselineSchemaTest` fails when it does — it compares the file
against the connected MySQL schema.

`--with-reference-data` is not optional in practice. The baseline committed
before 2026-10-07 had been generated without it, so a rebuild from the repo
would have produced a database nobody could log in to. It emits the ~1,000 rows
of configuration the app cannot boot without (roles, permissions, status_lookup,
the lookup tables).
Without them login and every authorisation check fail on a fresh database.
Business records and credentials are deliberately excluded; migrating those is
a separate decision, and `pgloader` is the better tool for it.

## Running the test suite against PostgreSQL

XAMPP ships the PostgreSQL PDO drivers but leaves them disabled. Enable them per
command rather than editing `php.ini`:

```bash
php -d extension=php_pdo_pgsql.dll -d extension=php_pgsql.dll ...
```

You need a PostgreSQL server. Any instance works; a throwaway one:

```bash
# from the extracted PostgreSQL binaries
initdb -D pgdata -U airking --pwfile=pw.txt -E UTF8 --locale=C
pg_ctl -D pgdata -l pg.log -o "-p 55432 -c listen_addresses=127.0.0.1" start
createdb -h 127.0.0.1 -p 55432 -U airking airking_pgtest
```

Point artisan at it with a `back-end/.env.pgtest` (git-ignored — do not copy a
production `APP_KEY` into it), then:

```bash
php -d extension=php_pdo_pgsql.dll artisan migrate --env=pgtest --force
php -d extension=php_pdo_pgsql.dll vendor/bin/phpunit -c phpunit.pgsql.xml
```

`phpunit.pgsql.xml` is `phpunit.xml` with the `DB_*` values overridden
(`force="true"` is required — PHPUnit will not otherwise override an existing
environment variable).

## Status

Both engines pass the full suite.

| | MySQL | PostgreSQL |
|---|---|---|
| Full suite | 1756 passed, 2 skipped | 1587 passed, 8 skipped |
| `migrate` on an empty DB | n/a | 89 tables, "Nothing to migrate" |

The 6 extra PostgreSQL skips are deliberate: 5 assert a MySQL-only column
attribute (ON UPDATE CURRENT_TIMESTAMP), and 1 is the baseline parity check,
which needs MySQL as its source. The other 2 skip on both engines and predate
this work.

### Bugs this found

Each of these was a live defect that MySQL's behaviour concealed:

- **`SQLSTATE === '23000'`** in the lookup controllers. That is MySQL's code for
  *every* integrity violation; PostgreSQL splits it (23505 unique, 23503 foreign
  key), so the catch was skipped and a handled 422 became a 500. Now uses
  Laravel's `UniqueConstraintViolationException` and `App\Support\DatabaseErrors`.
- **Aborted transactions.** PostgreSQL aborts the whole transaction on any error
  (25P02) and rejects every later statement, where MySQL leaves it usable. Code
  that caught a constraint violation and carried on — the lookup controllers,
  and `MaterialTagService`'s retry loop — now runs the risky statement inside a
  nested `DB::transaction()`, i.e. a SAVEPOINT.
- **Two models with the wrong primary key.** `WarrantyInventoryMovement` never
  declared one (so Eloquent assumed `id`), and `PurchaseReturnDetails` named
  `pr_detail_id`, a column that does not exist on its table — it belongs to
  `purchase_request_details`. MySQL's `lastInsertId()` ignores the column name,
  so inserts worked; PostgreSQL asks for `returning "<key>"` and fails. The
  `PurchaseReturnDetails` key was also wrong for `find()` on MySQL.
- **Concurrency detection** matched MySQL error numbers 1213/1205; now uses
  Laravel's `causedByConcurrencyError()`, which also knows PostgreSQL's
  40P01 / 55P03.
- **`SELECT SUM(...) FOR UPDATE`** in `ReservationService`. PostgreSQL rejects
  `FOR UPDATE` combined with an aggregate (0A000). The rows are now selected
  under the lock and summed in PHP — same rows locked, same total.
- **Case-insensitive lookup names.** `Rule::unique()` compares with `=`, which is
  case-insensitive on MySQL's collation but not on PostgreSQL, so PostgreSQL
  would have started accepting "AIRCON" alongside "Aircon".
  `App\Rules\UniqueLookupName` compares `LOWER(column)` on both.

### Portability fixes

- **Byte-exact string matching** — `App\Support\ExactStringMatch`, used via the
  `whereExactString()` macro. MySQL needs `BINARY` because the columns are
  `utf8mb4_unicode_ci`; PostgreSQL's `=` is already exact and has no `BINARY`
  operator. `tests/Unit/ExactStringMatchTest.php` asserts the compiled SQL per
  driver without touching a database.
- **`FIELD()`** (MySQL-only ordering) in `RestockRecommendationController` to a
  `CASE` expression, preserving `FIELD()`'s no-match-sorts-first behaviour.
- **`LOCATE(x, y)`** in `MaterialTagService` to `POSITION(x IN y)`, the
  SQL-standard spelling both engines implement.
- **`CHAR(9)` / `CHAR(13)`** in `InventoryScanTransactionController`. `CHAR()` is
  MySQL's code-point function, but in PostgreSQL `CHAR` is a *type*, so that was
  a syntax error. The characters are now bound as parameters, needing no
  function at all.
- **`COALESCE(boolean_col, 0)`** in `InventoryBatchService` to `COALESCE(..., false)`.
  MySQL stores booleans as tinyint and compares happily to 0; PostgreSQL rejects
  the mixed types outright.
- **JSON columns.** The 8 columns models cast to `array` are `longtext` in MySQL
  but are emitted as `jsonb`, so Laravel's `->` and `whereJsonContains()` work
  natively. PostgreSQL's `->` is a jsonb operator and fails on a text column.
- **`insertGetId()`** — 16 test call sites lacked the primary-key name, so
  Laravel emitted `returning "id"` against tables keyed on `product_id` etc.
- **Schema-introspection tests** now use `Schema::getForeignKeys()` or scope by
  `current_schema()` instead of MySQL's `DATABASE()` and `REFERENCED_TABLE_NAME`.

### Verified against production, 2026-10-07

The eight-migration catch-up was rehearsed before it was applied: a `pg_dump` of
Aiven restored into a local PostgreSQL 18.6, migrated there, checked, and only
then run against the live database. Doing it on a restored copy rather than a
synthetic one is what surfaced the `jsonb` bug below — it needed the real schema
to show up at all.

Three defects were found and fixed in the process, none visible on MySQL:

- **`ALTER TABLE ... MODIFY ... COMMENT`** in the two comment-only backup_logs
  migrations. MySQL-only syntax with no driver guard, and both were unapplied on
  Aiven, so they would have aborted the run at migration 3 of 8 — after the first
  two had already committed.
- **`ledger_drift_findings.scope` as `json`.** The model casts it to `array` but
  it was missing from `ExportPostgresSchema::JSON_COLUMNS`, giving three
  different types for one column: `json` locally, `text` from the baseline, and
  `json` on a migrated Aiven. PostgreSQL's `json` has no `=` operator, so
  `DriftChecker`'s `where('scope', json_encode(...))` would have thrown there and
  only there. Now `jsonb` everywhere, which also makes the comparison
  key-order-independent.
- **A missing permission migration.** `audit_evidence` shipped in PermissionSeeder
  only, so the read API had no permission row behind it in production and denied
  everyone. The fix is a migration, not a seeder run: `RolePermissionsSeeder`
  rewrites `is_granted` for every role × permission in the matrix, and
  `permission_audit_logs` showed an admin had deliberately revoked
  `product_set_management.read` from `inventory_analyst` on 2026-09-09 — seeding
  would have silently restored it.

### Known-unported: the historical migrations

15 migrations use MySQL-only SQL — `information_schema.KEY_COLUMN_USAGE`,
`SHOW INDEX`, `ALTER TABLE ... MODIFY`, `UPDATE ... LEFT JOIN`. They are **not**
PostgreSQL-compatible.

This does not break deployment, because the baseline schema marks all 133 of
them as already applied, so they never execute on PostgreSQL. It does mean
`migrate:fresh` and `migrate:rollback` will fail there. Laravel 11+ offers
portable replacements (`Schema::getIndexes()`, `Schema::getForeignKeys()`,
`Schema::hasIndex()`) if these ever need to run.
