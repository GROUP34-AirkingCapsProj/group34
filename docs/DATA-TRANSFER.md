# Moving the business data from local MySQL to Aiven PostgreSQL

Production currently has the right *shape* and none of your *data*: `migrate`
loaded the 86-table baseline plus 16 tables of reference data (roles,
permissions, lookups, locations), and stopped there. Your products, inventory,
users and history are still only in the XAMPP MySQL database on your machine.

This is the plan to move them.

---

## What is actually being moved

Measured against `airkingV5` on 2026-09-08:

| | |
|---|---|
| Tables | 86 (62 hold rows, 24 are empty) |
| Total rows | **~3,000** |
| Largest tables | `role_permissions` (600), `permission_audit_logs` (400), `products` (338) |
| Foreign keys | 223, of which 2 are self-referencing |
| Uploaded files | 1 (an avatar), 1 KB |

Three thousand rows is a very small dataset. That single fact drives every
decision below: it rules out pgloader and streaming tooling, and makes a
verify-everything approach cheap enough to be the default.

---

## What does NOT move

**The 16 reference tables are already populated in production.** Loading them
again would collide on primary keys. They are reloaded from MySQL rather than
skipped — see "Reference-data collision" below — so production ends up matching
local exactly, minus the dev-machine rows the baseline already excludes
(`system_settings.label_printers`, which holds Windows temp paths).

**Uploaded files do not survive.** Render's filesystem is ephemeral: anything
written to `storage/app/public` is destroyed on the next deploy. There is one
avatar to move, so this is not urgent, but the rule matters going forward —
until object storage (S3/R2) is wired up, treat every incident photo and report
uploaded in production as temporary. Rows that reference a file keep their path
and the file 404s.

**`sessions`, `cache`, `cache_locks`, `password_reset_*`, `personal_access_tokens`.**
Transient by nature. Copying live sessions to a host with a different `APP_KEY`
produces cookies that cannot be decrypted. Everyone logs in again; that is
correct, not a regression.

---

## Approach: an Artisan command, not pgloader

pgloader is the usual answer for MySQL to PostgreSQL, and it is the wrong tool
here. It is Linux-first and awkward to run on Windows, it needs its casting
rules re-derived from scratch, and its value is throughput — which is irrelevant
at 3,000 rows.

Instead: a `data:transfer` Artisan command reading from a `mysql_source`
connection and writing to the existing `pgsql` connection. Run from your machine
against Aiven over the internet; Render is not involved.

This wins because the conversion rules already exist in this repo. The
`schema:export-pgsql` command written for the baseline already knows which
columns are JSON, which tables hold reference data, and how to resynchronise
identity sequences. The transfer reuses that knowledge rather than restating it
in a second dialect.

It is also inspectable: `--dry-run` prints a per-table plan and row count
without writing anything, and `--table=products` moves one table so a failure
can be reproduced in isolation.

---

## The five hard parts

### 1. Foreign-key ordering (223 constraints)

Rows must be inserted after the rows they reference. PostgreSQL will not let you
defer this: `SET CONSTRAINTS ALL DEFERRED` only works on constraints declared
`DEFERRABLE` (ours are not), and `ALTER TABLE ... DISABLE TRIGGER ALL` needs
superuser, which Aiven's `avnadmin` is not.

So the command topologically sorts the tables using
`Schema::connection('pgsql')->getForeignKeys($table)` and loads in that order.

**Two tables reference themselves** and would deadlock a naive sort:

| Table | Column |
|---|---|
| `locations` | `parent_location_id` |
| `transfers` | `transfer_request_id` |

Both columns are nullable, so both are handled the same way: insert every row
with that column forced to `NULL`, then a second pass `UPDATE`s the real values
once all rows exist. 171 of the 223 FK columns are nullable, so the same
technique is available as a fallback if the sort finds a cycle this plan has not
anticipated.

### 2. Type conversions

MySQL and PostgreSQL disagree on four things in this schema:

| MySQL | PostgreSQL | Count | Handling |
|---|---|---|---|
| `tinyint(1)` = 0/1 | `boolean` | 21 columns | cast to bool before binding; PDO returns ints and PG rejects `0` for a boolean |
| `enum(...)` | `varchar` + CHECK | 7 columns | pass through as string; the CHECK validates it, and a value outside the enum is a real bug worth failing on |
| `json` / `longtext` | `jsonb` | 8 columns | reuse `JSON_COLUMNS` from `ExportPostgresSchema` |
| `datetime` / `timestamp` | `timestamp` | 218 columns | pass through — `sql_mode` includes `NO_ZERO_DATE`, so the `0000-00-00` problem cannot occur |

### 3. Reference-data collision

16 tables already hold rows in production. The command `DELETE`s them inside the
transaction before reloading from MySQL, in reverse FK order. Local is treated
as the source of truth, so production ends up identical rather than merged —
merging two versions of a lookup table by hand is how you get duplicate brands.

`system_settings` keeps its exclusion list: `label_printers` and
`archive_last_run` are dev-machine state, not configuration.

### 4. Identity sequences

Every `GENERATED BY DEFAULT AS IDENTITY` column has a sequence that does not
advance when you insert an explicit id. Load 338 products with ids 1–338 and the
sequence still sits at 1, so the next product insert collides — and it fails at
runtime, in front of a user, not during the transfer.

After loading, the command runs `setval()` on all 82 identity sequences to
`max(id)`. This is the single most likely thing to be silently skipped, so it is
asserted in the verification step rather than trusted.

### 5. Doing it atomically

The whole load runs in one transaction. A failure at table 60 of 86 rolls back
to an empty database rather than leaving a half-populated one that looks
plausible. At 3,000 rows the transaction is small enough that this costs
nothing.

---

## Procedure

**Before you start:** the API keeps serving throughout, but anyone logged in
will be looking at data that is about to be replaced. Do this when nobody is
using the system.

1. **Back up the Aiven database.** Aiven console → Backups → take a manual
   backup. Cheap insurance, and the only thing standing between you and a
   rebuild if step 4 goes wrong.

2. **Point the command at both databases.** Add the Aiven credentials to
   `back-end/.env` as `PG_*` variables (a separate connection, so your local
   `DB_*` MySQL settings are untouched and local development keeps working).

3. **Dry run.**

   ```bash
   php artisan data:transfer --dry-run
   ```

   Prints the load order, the row count per table, and every conversion it
   intends to apply. Writes nothing. Read the order and confirm nothing is
   missing.

4. **Transfer.**

   ```bash
   php artisan data:transfer
   ```

5. **Verify** (below).

6. **Log in on the deployed site** with your real account — the one from your
   local `users` table, not the seeder's. Check that products, inventory and
   history are visible.

---

## Verification

The command finishes by asserting three things, and refuses to report success
unless all three hold:

1. **Row-count parity.** Every table's count in Aiven equals its count in
   MySQL, excluding the transient tables listed above. A table that silently
   moved 0 rows is the failure mode this catches.
2. **Sequence health.** For every identity column, `last_value >= max(id)`.
   This is the check for part 4.
3. **Referential integrity.** Every FK resolves. PostgreSQL enforces this on
   insert, so a violation here means a constraint is missing from the baseline
   rather than bad data — worth knowing either way.

Independently, in the Aiven console:

```sql
SELECT count(*) FROM products;    -- expect 338
SELECT count(*) FROM users;       -- expect 8
```

---

## Rollback

Restore the manual backup from step 1. Because the load is one transaction, the
realistic bad outcome is "nothing happened", not "half the data moved".

The one irreversible action is the `DELETE` on reference tables — which is why
it sits inside the same transaction as the load.

---

## Known risks

**The admin seeder becomes redundant.** Your real accounts arrive with their
bcrypt hashes intact, so your existing passwords keep working. Do not also run
`AdminUserSeeder` — you would end up with a spare account whose password is
published in the repo. If you already ran it, delete that user afterwards.

**This has never been run.** Same caveat as the Docker image carried: the plan
is derived from the real schema and real row counts, but the command does not
exist yet. Expect the first `--dry-run` to surface at least one thing this
document did not predict.

**Re-running is not idempotent for non-reference tables.** Running it twice
without restoring a backup will collide on primary keys. That is deliberate —
the alternative, silently upserting, would mask a partial first run.
