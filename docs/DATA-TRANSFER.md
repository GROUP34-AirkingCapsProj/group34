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
| Tables moved | 80 of 86 (59 hold rows; the other 6 are skipped, see below) |
| Total rows | **2,833** |
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

**`sessions`, `cache`, `cache_locks`, `password_reset_tokens`, `personal_access_tokens`.**
Transient by nature. Copying live sessions to a host with a different `APP_KEY`
produces cookies that cannot be decrypted. Everyone logs in again; that is
correct, not a regression.

**`migrations`.** Skipped for a different and more dangerous reason. The
target's copy was written by the baseline load, and it is what tells Laravel the
historical migrations are already applied. Overwriting it with this machine's
copy is harmless today and wrong the moment the two drift.

---

## Approach: an Artisan command, not pgloader

pgloader is the usual answer for MySQL to PostgreSQL, and it is the wrong tool
here. It is Linux-first and awkward to run on Windows, it needs its casting
rules re-derived from scratch, and its value is throughput — which is irrelevant
at 3,000 rows.

Instead: **`php artisan data:transfer`**
(`back-end/app/Console/Commands/TransferDataToPostgres.php`), reading the local
`mysql` connection and writing a second `pgsql_target` connection. `DB_*` stays
on local MySQL so development keeps working; `PG_*` describes Aiven. Run from
your machine against Aiven over the internet; Render is not involved.

**Prerequisite:** PHP needs the PostgreSQL driver. XAMPP ships the DLLs but
leaves them switched off — `extension=pdo_pgsql` and `extension=pgsql` are now
uncommented in `C:\xampp\php\php.ini`, with the original kept alongside as
`php.ini.bak-before-pgsql`. Confirm with `php -m | findstr pgsql`.

This wins because the conversion rules already exist in this repo. The
`schema:export-pgsql` command written for the baseline already knows which
columns are JSON, which tables hold reference data, and how to resynchronise
identity sequences. The transfer reuses that knowledge rather than restating it
in a second dialect.

It is also inspectable: `--dry-run` prints the load order, a row count and the
conversions per table without writing anything — and works even with `PG_*`
unset, since the plan comes entirely from the source. `--table=products` moves
one table so a failure can be reproduced in isolation.

---

## The five hard parts

### 1. Foreign-key ordering (223 constraints)

Rows must be inserted after the rows they reference. PostgreSQL will not let you
defer this: `SET CONSTRAINTS ALL DEFERRED` only works on constraints declared
`DEFERRABLE` (ours are not), and `ALTER TABLE ... DISABLE TRIGGER ALL` needs
superuser, which Aiven's `avnadmin` is not.

So the command topologically sorts the tables from
`information_schema.KEY_COLUMN_USAGE` and loads in that order. The resulting
order is asserted against all 223 constraints by
`tests/Feature/Schema/DataTransferPlanTest.php` — the only part of this that can
be tested without a live PostgreSQL to write to.

**Two tables reference themselves** and would deadlock a naive sort:

| Table | Column |
|---|---|
| `locations` | `parent_location_id` |
| `transfers` | `transfer_request_id` |

Both columns are nullable, so both are handled the same way: insert every row
with that column forced to `NULL`, then a second pass `UPDATE`s the real values
once all rows exist. 171 of the 223 FK columns are nullable, and the sort applies
the same technique automatically if it meets a cycle this plan did not
anticipate — deferring one nullable column inside it rather than guessing. A
cycle with no nullable column to defer aborts and says which tables it involves.

### 2. Type conversions

MySQL and PostgreSQL disagree on four things in this schema:

| MySQL | PostgreSQL | Count | Handling |
|---|---|---|---|
| `tinyint(1)` = 0/1 | `boolean` | 21 columns | cast to bool before binding; PDO returns ints and PG rejects `0` for a boolean |
| `enum(...)` | `varchar` + CHECK | 7 columns | pass through as string; the CHECK validates it, and a value outside the enum is a real bug worth failing on |
| `json` / `longtext` | `jsonb` | 8 columns | reuse `JSON_COLUMNS` from `ExportPostgresSchema` |
| `datetime` / `timestamp` | `timestamp` | 218 columns | pass through — `sql_mode` includes `NO_ZERO_DATE`, so the `0000-00-00` problem cannot occur |

### 3. Reference-data collision

16 tables already hold rows in production. The command `DELETE`s every table it
is about to load, in reverse FK order, inside the transaction. Local is treated
as the source of truth, so production ends up identical rather than merged —
merging two versions of a lookup table by hand is how you get duplicate brands.
Emptying all 80 rather than only those 16 is also what makes a second attempt
safe after a failed first one.

`DELETE`, not `TRUNCATE`: `TRUNCATE` takes an `ACCESS EXCLUSIVE` lock, and with
`CASCADE` would silently empty tables outside the list.

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

1. **Decide whether you need a backup.**

   Aiven has no "back up now" button for PostgreSQL — backups are automatic,
   and on the free plan there are none at all. Check yours under the service's
   **Backups** tab.

   For the *first* run this does not matter: everything currently in Aiven is
   the baseline schema plus reference data, all of which `migrate` regenerates
   exactly from this repo. The rollback is to rebuild, not to restore — see
   "Rollback" below.

   It matters from the *second* run onwards, once people have used the deployed
   site and production holds rows this machine has never seen. At that point a
   re-run would discard them, and only a real dump gets them back:

   ```bash
   pg_dump "postgres://avnadmin:PASSWORD@HOST:PORT/defaultdb?sslmode=require" \
       -Fc -f airking-aiven-backup.dump
   ```

   `pg_dump` is not on this machine and XAMPP does not ship it. It comes with
   the PostgreSQL Windows installer — during setup, deselect everything except
   **Command Line Tools**; a full server install is not needed.

2. **Point the command at both databases.** `back-end/.env` now ends with a
   `PG_*` block. Fill in `PG_HOST`, `PG_PORT` and `PG_PASSWORD` from the Aiven
   console — the same values you pasted into Render, and note the port is not
   5432. Your `DB_*` MySQL settings are untouched, so local development keeps
   working.

3. **Dry run.**

   ```bash
   php artisan data:transfer --dry-run
   ```

   Prints the load order, the row count per table, and every conversion it
   intends to apply. Writes nothing. Read the order and confirm nothing is
   missing.

4. **Transfer.** It asks twice — once about the backup, once about the row
   count — before touching anything.

   ```bash
   php artisan data:transfer
   ```

   Before opening the transaction it checks the target has every table and
   column it is about to write. If the baseline has drifted from the current
   schema, that surfaces here as one sentence naming the column, rather than as
   a failure halfway through the load.

5. **Verify** (below).

6. **Log in on the deployed site** with your real account — the one from your
   local `users` table, not the seeder's. Check that products, inventory and
   history are visible.

---

## When this machine cannot reach the target

**Try a different network first.** This turned out to be per-network, not
per-machine: the same laptop that could not reach Aiven from the campus Wi-Fi
connected without trouble from another network, and the live `data:transfer`
then ran normally. The `--sql` path below is the fallback for when no reachable
network is available — not the expected route.

Two things make this easy to misdiagnose:

- The failure looks like a credentials problem but is not. DNS resolves and the
  TCP connection is *accepted*; only PostgreSQL's handshake goes unanswered,
  timing out with `SQLSTATE[08006] timeout expired`. No password is ever sent.
- A network you *think* you switched to may not be in use. `check-aiven.ps1`
  prints your public IP in step 0 — if it did not change, the switch did not
  take effect, which is the usual explanation for "the hotspot made no
  difference".

`data:transfer` needs a network path from here to Aiven, because the data is
here and the database is there. Sometimes there isn't one: a network that
accepts the TCP connection and then swallows PostgreSQL's handshake cannot be
configured around, and no `PG_*` value fixes it. `back-end/scripts/check-aiven.ps1`
tells you whether that is what you are looking at — it walks DNS, TCP and the
handshake and stops at the first step that fails.

For that case, `--sql` writes the entire load to a file and connects to nothing:

```bash
php artisan data:transfer --sql=airking-data.sql
```

The script does everything the live path does, in the same order, inside one
transaction — empty, load, fill the deferred columns, resynchronise the
sequences, verify. The verification is a `DO` block that `RAISE`s on a mismatch,
so a short load aborts the transaction instead of committing quietly. Foreign
keys it does not need to check itself; PostgreSQL enforces those on insert.

Run it from any machine that *can* reach the database — that machine needs
`psql` and the file, nothing else, and never needs your MySQL data:

```bash
psql "postgres://avnadmin:PASSWORD@HOST:PORT/defaultdb?sslmode=require" \
    -f airking-data.sql
```

**The file contains real data**, including password hashes and customer records.
It is not a repository artefact: `.gitignore` blocks `airking-data*.sql` so it
cannot be committed by accident, and it should be deleted once it has been
loaded. Do not upload it to a file-sharing site to get it onto another machine —
use a USB stick or a direct copy.

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

Because the load is one transaction, the realistic bad outcome is "nothing
happened", not "half the data moved". The `DELETE` that empties the tables is
the one irreversible action, and it sits inside that same transaction.

If the target does need resetting, and production holds nothing that is not also
on this machine, rebuild rather than restore — no backup required:

```sql
DROP SCHEMA public CASCADE;
CREATE SCHEMA public;
```

then redeploy with `RUN_MIGRATIONS=true` to reload the baseline, and run the
transfer again.

Once production holds rows this machine has never seen, that is no longer true
and only the dump from step 1 will bring them back.

---

## Known risks

**The admin seeder becomes redundant.** Your real accounts arrive with their
bcrypt hashes intact, so your existing passwords keep working. Do not also run
`AdminUserSeeder` — you would end up with a spare account whose password is
published in the repo. If you already ran it, delete that user afterwards.

**Nothing here has yet inserted a row into PostgreSQL.** The planning half is
exercised against your real schema — the load order satisfies all 223 foreign
keys and the tests assert it, and the generated script is checked for structure
the same way. But there is no local PostgreSQL to try either path against, so
the first real execution is the one against Aiven, and PostgreSQL's opinion of
the SQL is still unknown. That is what the backup in step 1, the single
transaction and the `RAISE` assertions are for: the expected failure mode is
"it aborted and told you why", not "it half worked".

**Re-running is safe, and destructive.** Each run empties the 80 tables and
reloads them, so a second attempt after a failure works. It also means a run
started by accident discards whatever is in production. Hence the two prompts;
`--force` skips them and should be left alone.
