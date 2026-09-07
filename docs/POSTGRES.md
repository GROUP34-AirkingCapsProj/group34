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
along with the 86 tables.

**`psql` must be on PATH** — Laravel shells out to it
(`PostgresSchemaState::load`). The production image installs it via
`postgresql-client`; see the root `Dockerfile`.

## Regenerating the baseline schema

`pgsql-schema.sql` is generated from the live MySQL schema, not hand-written:

```bash
php artisan schema:export-pgsql --with-reference-data
```

Re-run it after any migration that changes the schema, or the baseline goes
stale. `PostgresBaselineSchemaTest` fails when it does — it compares the file
against the connected MySQL schema.

`--with-reference-data` also emits the ~1,000 rows of configuration the app
cannot boot without (roles, permissions, status_lookup, the lookup tables).
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
| Full suite | 1593 passed, 2 skipped | 1587 passed, 8 skipped |
| `migrate` on an empty DB | n/a | 86 tables, "Nothing to migrate" |

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

### Known-unported: the historical migrations

15 migrations use MySQL-only SQL — `information_schema.KEY_COLUMN_USAGE`,
`SHOW INDEX`, `ALTER TABLE ... MODIFY`, `UPDATE ... LEFT JOIN`. They are **not**
PostgreSQL-compatible.

This does not break deployment, because the baseline schema marks all 133 of
them as already applied, so they never execute on PostgreSQL. It does mean
`migrate:fresh` and `migrate:rollback` will fail there. Laravel 11+ offers
portable replacements (`Schema::getIndexes()`, `Schema::getForeignKeys()`,
`Schema::hasIndex()`) if these ever need to run.
