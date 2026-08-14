# Incident Status Ownership Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the overloaded `Awaiting Inspection` status so a submitted inspection sits in the admin's queue and a rejected one sits in the inspector's, instead of both sharing a status that means opposite things on the two screens.

**Architecture:** Add one status, `Awaiting Approval`, written by `submitInspection()`. `reject()` writes `Awaiting Inspection`, whose name then finally matches its meaning. A data migration splits existing rows by `rejection_note`, which is an exact marker because `submitInspection()` already clears it. Frontend queue filters and labels follow.

**Tech Stack:** Laravel 11 (PHP), MariaDB via XAMPP, PHPUnit; React 19 frontend on Create React App with Jest + React Testing Library.

**Spec:** `docs/superpowers/specs/2026-08-14-incident-status-ownership-design.md`

---

## Background the engineer needs

An incident report travels through one document in three modes: a reporter files
it, an inspector examines the goods and submits findings, and an admin confirms.
**Confirming is the only moment stock moves.**

`Awaiting Inspection` is currently written in two places that mean opposite
things:

| Where | Meaning | Whose turn |
|-------|---------|------------|
| `IncidentService::submitInspection()` :244 | inspector submitted | the **admin** |
| `IncidentService::reject()` :363 | admin sent it back | the **inspector** |

`IncidentInspectionPage.jsx:32` puts that status in the inspector's queue and
badges it "Sent back"; `IncidentList.jsx:81` shows it to the admin with a
**Decide** button. So every submitted inspection lands back in the inspector's
queue accused of having been rejected — which is why submitting an inspection
looks like it did nothing.

After this change:

| Status | Written by | Whose turn |
|--------|-----------|------------|
| `Open` | filing | inspector — or admin directly, for exempt reasons |
| `Awaiting Inspection` | `reject()` | inspector |
| `Awaiting Approval` **(new)** | `submitInspection()` | admin |
| `Resolved` / `Dismissed` | `confirm()` / `dismiss()` | nobody |

### Facts that will save you time

- **Statuses are resolved by NAME, never by id.** `IncidentReport::statusId($name)`
  (`app/Models/IncidentReport.php:124`) looks the id up and caches it. Ids differ
  between installs and between the dev and test databases. Never hardcode one.
- **`statusId()` throws if the name is missing.** That is why Task 1's migration
  must insert the row itself rather than trusting the seeder to have run.
- **`rejection_note` is already cleared on resubmit** —
  `IncidentService.php:255`. Do not "fix" this; the migration rule depends on it.
  It means a non-null note can only appear on a report that was sent back and
  has not been resubmitted.
- **Two reasons skip inspection entirely.** `INSPECTION_EXEMPT_REASONS` is
  `['zone_mismatch', 'other']`. They create no `incident_items` and are confirmed
  straight from `Open` — `confirm()` :306-312 bypasses the status guard for them.
  Do not break this path.
- **Item status is independent of report status.** `IncidentItem.php:111` records
  that deriving quarantine from the incident's status broke once before. Nothing
  in this plan touches item status.

### Running the tests

**Backend** — from `back-end/`:

```bash
php artisan test --filter=Incident
```

Baseline before any change: **149 passed, 547 assertions**, ~23 seconds.

MySQL must be running or every test fails with
`SQLSTATE[HY000] [2002] No connection could be made`. Check with
`tasklist | grep -i mysqld`; start with
`/c/xampp/mysql/bin/mysqld.exe --defaults-file=/c/xampp/mysql/bin/my.ini --standalone`
in the background.

Tests run against `airkingV5_test` per `phpunit.xml` — **not** the live
`airkingV5`. The backend tests use `DatabaseTransactions`, so they roll back.

**Frontend** — from `frontend/`:

```bash
./node_modules/.bin/react-scripts test --watchAll=false --testPathPattern "incident"
```

Baseline: **112 passed, 8 suites**. Use the local binary, **not `npx`** — npx
silently fetches a remote copy and fails on a missing `@testing-library/jest-dom`.

The wider frontend suite has **5 pre-existing failures** in `navConfigureMap`,
`App.test` and `InventoryManagement`. They are unrelated; do not chase them.

### Shell

Windows. Use the **Bash** tool (Git Bash) for chained commands — `&&` is a parse
error in PowerShell.

---

## File Structure

**Created:**

| File | Responsibility |
|------|----------------|
| `back-end/database/migrations/2026_08_14_000001_split_awaiting_inspection_status.php` | Insert `Awaiting Approval`; move already-inspected rows onto it |
| `back-end/tests/Feature/Incidents/IncidentStatusOwnershipTest.php` | The transitions and guards the old model could not express |

**Modified:**

| File | Change |
|------|--------|
| `back-end/database/seeders/IncidentStatusSeeder.php` | Seed `Awaiting Approval` |
| `back-end/app/Services/IncidentService.php` | `submitInspection` writes the new status; `confirm` and `reject` guard on it; `reject` writes `Awaiting Inspection` |
| `back-end/app/Http/Controllers/Api/IncidentInspectionController.php:275` | Comment names the new transition |
| `back-end/app/Http/Controllers/Api/IncidentReportController.php:101` | Comment names the new status |
| `back-end/app/Models/IncidentItem.php:111` | Comment names the new status |
| `back-end/tests/Feature/Incidents/IncidentInspectionEndpointTest.php` | Assert the new status |
| `back-end/tests/Feature/Incidents/IncidentDecisionOrderTest.php` | Assert the new status |
| `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php` | Assert the new status |
| `frontend/src/admin/components/incident/IncidentList.jsx` | Label and Decide-button keyed on `Awaiting Approval` |
| `frontend/src/admin/pages/IncidentInspectionPage.jsx` | Clear the selection when the submitted report leaves the queue |
| `frontend/src/admin/pages/IncidentInspectionPage.test.jsx` | New — queue behaviour |

**Deliberately unchanged:** `INSPECTABLE_STATUSES` in `IncidentInspectionPage.jsx:32`
stays `['Open', 'Awaiting Inspection']`. The literal is already right; only its
meaning narrows. The "Sent back" badge at :127 becomes truthful with no edit.

---

### Task 1: The migration

Runs first so the status exists before any code references it.

**Files:**
- Create: `back-end/database/migrations/2026_08_14_000001_split_awaiting_inspection_status.php`
- Test: `back-end/tests/Feature/Incidents/IncidentStatusOwnershipTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Incidents/IncidentStatusOwnershipTest.php`:

```php
<?php

namespace Tests\Feature\Incidents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One status, one owner.
 *
 * `Awaiting Inspection` used to be written both when the inspector submitted
 * and when the admin rejected, so it meant "waiting on the admin" in the
 * admin's list and "the admin sent this back" in the inspector's queue. Every
 * submitted inspection landed in the inspector's queue badged "Sent back".
 */
class IncidentStatusOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    private function incidentStatusId(string $name): int
    {
        return (int) DB::table('status_lookup')
            ->where('status_category', 'incident')
            ->where('status_name', $name)
            ->value('status_id');
    }

    public function test_awaiting_approval_status_exists(): void
    {
        $this->assertNotSame(
            0,
            $this->incidentStatusId('Awaiting Approval'),
            'The migration must insert Awaiting Approval — IncidentReport::statusId() throws on a missing name.'
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd back-end
php artisan test --filter=IncidentStatusOwnershipTest
```

Expected: FAIL — the assertion reports `0`, because the status does not exist yet.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_08_14_000001_split_awaiting_inspection_status.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Split the two meanings that shared `Awaiting Inspection`.
 *
 * It was written both by submitInspection() — the inspector is done, the ADMIN
 * must decide — and by reject() — the admin sent it back, the INSPECTOR must
 * act. The inspector's queue and the admin's list both read that one status and
 * reached opposite conclusions, so every submitted inspection reappeared in the
 * inspector's queue wearing a "Sent back" badge.
 *
 * `Awaiting Approval` now carries the first meaning. `Awaiting Inspection`
 * keeps its name and finally means what it says.
 *
 * The status row is inserted HERE rather than left to IncidentStatusSeeder: a
 * live deploy is not guaranteed to re-seed, and IncidentReport::statusId()
 * throws on a missing name — so the first submitted inspection after deploy
 * would 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('status_lookup')->updateOrInsert(
            ['status_category' => 'incident', 'status_name' => 'Awaiting Approval'],
            ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
        );

        $awaitingInspection = $this->statusId('Awaiting Inspection');
        $awaitingApproval   = $this->statusId('Awaiting Approval');

        if ($awaitingInspection === null || $awaitingApproval === null) {
            return;
        }

        // Already inspected and NOT sent back: the admin owes a decision.
        //
        // `rejection_note IS NULL` is an exact marker rather than a guess,
        // because submitInspection() nulls the note (IncidentService.php:255) —
        // so a note can only survive on a report the admin sent back that the
        // inspector has not yet resubmitted.
        $moved = DB::table('incident_reports')
            ->where('status_id', $awaitingInspection)
            ->whereNull('rejection_note')
            ->whereNotNull('inspected_at')
            ->update(['status_id' => $awaitingApproval]);

        // Everything else stays put, which leaves it with the INSPECTOR. That
        // is the recoverable direction: a report wrongly given to the inspector
        // costs one resubmit, while one wrongly given to the admin can be
        // confirmed — and confirming is the single moment stock moves.
        $left = DB::table('incident_reports')
            ->where('status_id', $awaitingInspection)
            ->count();

        echo "  split_awaiting_inspection_status: moved {$moved} to Awaiting Approval, "
            . "left {$left} with the inspector" . PHP_EOL;
    }

    /**
     * Lossy, deliberately and unavoidably.
     *
     * Moving every Awaiting Approval row back re-merges the two populations
     * that up() separated. Rolling forward again re-splits them by the same
     * rule, so a report rejected during the rollback window would be misrouted.
     * The status row itself is left in place — dropping it would break any row
     * still pointing at it.
     */
    public function down(): void
    {
        $awaitingInspection = $this->statusId('Awaiting Inspection');
        $awaitingApproval   = $this->statusId('Awaiting Approval');

        if ($awaitingInspection === null || $awaitingApproval === null) {
            return;
        }

        DB::table('incident_reports')
            ->where('status_id', $awaitingApproval)
            ->update(['status_id' => $awaitingInspection]);
    }

    private function statusId(string $name): ?int
    {
        $id = DB::table('status_lookup')
            ->where('status_category', 'incident')
            ->where('status_name', $name)
            ->value('status_id');

        return $id === null ? null : (int) $id;
    }
};
```

- [ ] **Step 4: Run the migration and the test**

```bash
cd back-end
php artisan migrate
php artisan test --filter=IncidentStatusOwnershipTest
```

Expected: the migration prints its moved/left counts, and the test PASSES.

**On the live database this prints `moved 2, left 0`** — reports id 16
(`INC-20260811-7F249`) and id 18 (`INC-20260812-E115E`), both inspected by
Marichu on 14 Aug with no rejection note. If you see anything else, stop and
report it rather than continuing.

- [ ] **Step 5: Test the routing rule against all three row shapes**

The migration has already run, so its `up()` cannot be re-executed under
`DatabaseTransactions`. What CAN be pinned is the rule itself — that the three
row shapes route where the spec says. Add to `IncidentStatusOwnershipTest.php`:

```php
    /**
     * The rule the migration applies, exercised against all three row shapes.
     *
     * This asserts the CRITERIA rather than re-running the migration file,
     * which has already run by the time any test boots. The real check against
     * production data is the row count in Task 6 Step 3 — two rows, both known
     * by number.
     */
    public function test_the_migration_rule_routes_each_row_shape_correctly(): void
    {
        $awaitingInspection = $this->incidentStatusId('Awaiting Inspection');

        $matches = fn (array $row) => $row['status_id'] === $awaitingInspection
            && $row['rejection_note'] === null
            && $row['inspected_at'] !== null;

        // Inspected, never sent back → the admin owes a decision.
        $this->assertTrue($matches([
            'status_id' => $awaitingInspection,
            'rejection_note' => null,
            'inspected_at' => '2026-08-14 05:14:24',
        ]));

        // Sent back → stays with the inspector. submitInspection() nulls the
        // note, so a surviving note can only mean "not yet resubmitted".
        $this->assertFalse($matches([
            'status_id' => $awaitingInspection,
            'rejection_note' => 'the sticker photo is unreadable',
            'inspected_at' => '2026-08-14 05:14:24',
        ]));

        // Never inspected → stays with the inspector. Should not exist at this
        // status, and the rule declines to guess if it does.
        $this->assertFalse($matches([
            'status_id' => $awaitingInspection,
            'rejection_note' => null,
            'inspected_at' => null,
        ]));
    }

    /**
     * Idempotent: nothing is left at Awaiting Inspection that the rule would
     * still move, so a second run is a no-op.
     */
    public function test_the_migration_left_nothing_it_would_move_again(): void
    {
        $stillMovable = DB::table('incident_reports')
            ->where('status_id', $this->incidentStatusId('Awaiting Inspection'))
            ->whereNull('rejection_note')
            ->whereNotNull('inspected_at')
            ->count();

        $this->assertSame(0, $stillMovable);
    }
```

- [ ] **Step 6: Seed the status for fresh installs**

In `back-end/database/seeders/IncidentStatusSeeder.php`, replace the single
`updateOrInsert` for `Awaiting Inspection` with a loop over both names:

```php
        // An incident whose stock is quarantined pending an inspection is NOT
        // resolved — nobody has decided anything yet. Reading "Resolved" on the
        // triage list while the units sit un-inspected in a corner overstates
        // what filing actually did.
        //
        // The two are separate owners, not two words for one state: Awaiting
        // Inspection is the INSPECTOR's turn (the admin sent it back) and
        // Awaiting Approval is the ADMIN's (the inspection is submitted).
        // Sharing one status made every submitted inspection reappear in the
        // inspector's queue badged "Sent back".
        foreach (['Awaiting Inspection', 'Awaiting Approval'] as $name) {
            DB::table('status_lookup')->updateOrInsert(
                ['status_category' => 'incident', 'status_name' => $name],
                ['is_active' => 1, 'updated_at' => now(), 'created_at' => now()],
            );
        }
```

- [ ] **Step 7: Confirm the whole suite still passes**

```bash
cd back-end
php artisan test --filter=Incident
```

Expected: 150 passed (the 149 baseline plus the new test).

- [ ] **Step 8: Commit**

```bash
git add back-end/database/migrations/2026_08_14_000001_split_awaiting_inspection_status.php back-end/database/seeders/IncidentStatusSeeder.php back-end/tests/Feature/Incidents/IncidentStatusOwnershipTest.php
git commit -m "feat(incidents): add Awaiting Approval and split the existing rows

Awaiting Inspection was written both when the inspector submitted and when
the admin rejected, so the inspector's queue and the admin's list read one
status and reached opposite conclusions.

The status row is inserted by the migration rather than the seeder because
a live deploy may not re-seed and statusId() throws on a missing name."
```

---

### Task 2: The service transitions

**Files:**
- Modify: `back-end/app/Services/IncidentService.php`
- Test: `back-end/tests/Feature/Incidents/IncidentStatusOwnershipTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `IncidentStatusOwnershipTest.php`. Add these imports at the top of the
file, after the existing `use` lines:

```php
use App\Models\IncidentReport;
use App\Models\Product;
use App\Models\User;
use App\Services\IncidentService;
```

Then add this helper and these tests inside the class:

```php
    /**
     * A filed report with one quarantined item, made the way production makes
     * it — filing is what quarantines, so a hand-built row has nothing to
     * inspect.
     */
    private function fileReport(string $reason = 'defective'): IncidentReport
    {
        $user    = User::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        return app(IncidentService::class)->file([
            'source_type' => 'standalone',
            'location_id' => (int) $user->location_id ?: 1,
            'product_id'  => (int) $product->getKey(),
            'quantity'    => 1,
            'reason'      => $reason,
            'narrative'   => 'status ownership test',
        ], (int) $user->getKey());
    }

    private function submit(IncidentReport $report): IncidentReport
    {
        return app(IncidentService::class)->submitInspection(
            $report,
            ['inspection_findings' => 'checked'],
            (int) User::query()->firstOrFail()->getKey(),
        );
    }

    public function test_submitting_an_inspection_hands_the_report_to_the_admin(): void
    {
        $submitted = $this->submit($this->fileReport());

        $this->assertSame(
            $this->incidentStatusId('Awaiting Approval'),
            (int) $submitted->status_id,
            'A submitted inspection is the ADMIN\'s turn, not the inspector\'s.'
        );
    }

    public function test_rejecting_hands_the_report_back_to_the_inspector(): void
    {
        $submitted = $this->submit($this->fileReport());

        $rejected = app(IncidentService::class)->decide(
            $submitted,
            'reject',
            ['rejection_note' => 'the serial does not match the sticker'],
            (int) User::query()->firstOrFail()->getKey(),
        );

        $this->assertSame(
            $this->incidentStatusId('Awaiting Inspection'),
            (int) $rejected->status_id,
            'A rejected report is the INSPECTOR\'s turn.'
        );
        $this->assertSame('the serial does not match the sticker', $rejected->rejection_note);
    }

    public function test_resubmitting_a_rejected_report_returns_it_to_the_admin(): void
    {
        $actor    = (int) User::query()->firstOrFail()->getKey();
        $rejected = app(IncidentService::class)->decide(
            $this->submit($this->fileReport()),
            'reject',
            ['rejection_note' => 'redo the box photo'],
            $actor,
        );

        $resubmitted = $this->submit($rejected);

        $this->assertSame(
            $this->incidentStatusId('Awaiting Approval'),
            (int) $resubmitted->status_id
        );
        // The note answered its round; leaving it would have the admin's own
        // screen accuse a fixed report of being rejected.
        $this->assertNull($resubmitted->rejection_note);
    }

    /**
     * The guard the old model could not express.
     *
     * A sent-back report and a submitted one shared a status, so "confirm what
     * I just rejected" was indistinguishable from "confirm a real inspection"
     * — and confirming is the single moment stock moves.
     */
    public function test_an_admin_cannot_confirm_a_report_they_just_sent_back(): void
    {
        $actor    = (int) User::query()->firstOrFail()->getKey();
        $rejected = app(IncidentService::class)->decide(
            $this->submit($this->fileReport()),
            'reject',
            ['rejection_note' => 'not good enough'],
            $actor,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has not been inspected yet');

        app(IncidentService::class)->decide($rejected, 'confirm', [], $actor);
    }

    public function test_rejecting_a_report_with_no_submitted_inspection_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no submitted inspection to reject');

        app(IncidentService::class)->decide(
            $this->fileReport(),
            'reject',
            ['rejection_note' => 'nope'],
            (int) User::query()->firstOrFail()->getKey(),
        );
    }

    /**
     * A zone mismatch creates no items and is never inspected, so it is
     * confirmed straight from Open. Splitting the inspected statuses must not
     * catch it in the new guard.
     */
    public function test_an_exempt_reason_still_confirms_straight_from_open(): void
    {
        $actor  = (int) User::query()->firstOrFail()->getKey();
        $report = $this->fileReport('zone_mismatch');

        $confirmed = app(IncidentService::class)->decide($report, 'confirm', [], $actor);

        $this->assertSame($this->incidentStatusId('Resolved'), (int) $confirmed->status_id);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd back-end
php artisan test --filter=IncidentStatusOwnershipTest
```

Expected: FAIL. `test_submitting_an_inspection_hands_the_report_to_the_admin`
fails because the service still writes `Awaiting Inspection`;
`test_an_admin_cannot_confirm_a_report_they_just_sent_back` fails because no
exception is thrown — the old guard accepts a sent-back report.

If `fileReport()` errors instead, the service's `file()` signature differs from
what is assumed here — read `IncidentService::file()` and adjust the payload
before continuing. Do not proceed with a helper that throws.

- [ ] **Step 3: Change what `submitInspection` writes**

In `back-end/app/Services/IncidentService.php`, change the docblock at :213-220
from `Open -> Awaiting Inspection` to:

```php
    /**
     * The inspector submits their findings: the report becomes the ADMIN's.
     *
     * Open or Awaiting Inspection -> Awaiting Approval. The two accepted
     * starting points are "never inspected" and "the admin sent it back"; both
     * are the inspector's turn, and submitting ends it.
     *
     * Touches NO stock. Everything recorded here — the findings, the ASC tick,
     * the names on the hardcopy that gets signed by hand — is evidence for the
     * admin's decision, and the per-item outcomes it points at are applied only
     * when that decision is `confirm`.
     */
```

Then change line 244 from
`'status_id' => IncidentReport::statusId('Awaiting Inspection'),`
to:

```php
                'status_id'                => IncidentReport::statusId('Awaiting Approval'),
```

Leave the `$allowed` array at :234-237 alone — `Open` and `Awaiting Inspection`
are still exactly the two states an inspector may submit from.

- [ ] **Step 4: Move the `confirm` guard**

At `back-end/app/Services/IncidentService.php:308`, change
`IncidentReport::statusId('Awaiting Inspection')` to
`IncidentReport::statusId('Awaiting Approval')`:

```php
        if (! $exempt && (int) $locked->status_id !== IncidentReport::statusId('Awaiting Approval')) {
```

The message below it stays as it is — "This incident has not been inspected yet"
is still accurate, and is now accurate for a sent-back report too.

- [ ] **Step 5: Move the `reject` guard and change what it writes**

Replace the docblock and body of `reject()` at
`back-end/app/Services/IncidentService.php:348-368` with:

```php
    /**
     * Send it back to the inspector.
     *
     * Awaiting Approval -> Awaiting Inspection. The status MOVES, where it used
     * to stay put: both states shared one status, so a report the admin had
     * just sent back was indistinguishable from one waiting on them, and it sat
     * in the inspector's queue alongside every inspection they had already
     * submitted.
     *
     * Only the status and the note change. The evidence and the per-item
     * outcomes are left alone so the inspector edits rather than re-enters
     * them, and submitInspection() clears the note when they resubmit.
     */
    private function reject(IncidentReport $locked, array $options): IncidentReport
    {
        if ((int) $locked->status_id !== IncidentReport::statusId('Awaiting Approval')) {
            throw new \RuntimeException('There is no submitted inspection to reject.');
        }

        $locked->update([
            'status_id'      => IncidentReport::statusId('Awaiting Inspection'),
            'rejection_note' => $options['rejection_note'] ?? $options['resolution_note'] ?? null,
        ]);

        return $locked->fresh();
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
cd back-end
php artisan test --filter=IncidentStatusOwnershipTest
```

Expected: PASS, 7 tests.

- [ ] **Step 7: Commit**

```bash
git add back-end/app/Services/IncidentService.php back-end/tests/Feature/Incidents/IncidentStatusOwnershipTest.php
git commit -m "fix(incidents): a submitted inspection is the admin's turn, not the inspector's

submitInspection now writes Awaiting Approval and reject writes Awaiting
Inspection, so the two states stop sharing a status. An admin can no longer
confirm a report they just sent back — under the old model that was
indistinguishable from a real submission, and confirming moves stock."
```

---

### Task 3: The existing backend tests and comments

Three test files assert the old transition; three comments name it.

**Files:**
- Modify: `back-end/tests/Feature/Incidents/IncidentInspectionEndpointTest.php`
- Modify: `back-end/tests/Feature/Incidents/IncidentDecisionOrderTest.php`
- Modify: `back-end/tests/Feature/Sets/IncidentStandaloneFilingTest.php`
- Modify: `back-end/app/Http/Controllers/Api/IncidentInspectionController.php:275`
- Modify: `back-end/app/Http/Controllers/Api/IncidentReportController.php:101`
- Modify: `back-end/app/Models/IncidentItem.php:111`

- [ ] **Step 1: See what is failing**

```bash
cd back-end
php artisan test --filter=Incident
```

Expected: failures in the three test files, each asserting `Awaiting Inspection`
where the service now writes `Awaiting Approval`. Read each failure before
editing — the point is to update assertions that describe the NEW correct
behaviour, not to make red go green.

- [ ] **Step 2: Update each assertion**

There are eight occurrences and **only three of them change**. A blanket
find-and-replace would break the suite and, worse, would make the rejection
tests assert the wrong thing while still passing. Here is every one:

| File:line | What precedes it | Action |
|-----------|------------------|--------|
| `IncidentInspectionEndpointTest.php:196` | a submit | **→ `Awaiting Approval`** |
| `IncidentInspectionEndpointTest.php:241` | a reject, with `rejection_note` asserted on the next line | **leave** — now exactly right |
| `IncidentInspectionEndpointTest.php:278` | a submit, then a 403 self-approval attempt | **→ `Awaiting Approval`** |
| `IncidentInspectionEndpointTest.php:394` | a rejected report fetched by id | **leave** |
| `IncidentInspectionEndpointTest.php:411` | a rejected report in the list payload | **leave** |
| `IncidentDecisionOrderTest.php:234` | `submitInspection()` on the line above | **→ `Awaiting Approval`** |
| `IncidentDecisionOrderTest.php:214` | prose in a docblock | **reword** (below) |
| `IncidentStandaloneFilingTest.php:180` | prose in a comment | **reword** (below) |

`IncidentDecisionOrderTest.php:213-215` — the sentence describes which action
reaches the status, so it has to name the new one:

```php
     * Inverted: approval no longer comes first, so there is nothing to park.
     * Awaiting Approval is now reached by the inspector SUBMITTING, and a
     * confirmation attempted before that is refused outright.
```

`IncidentStandaloneFilingTest.php:178-180` — the point is that an exempt reason
skips the inspection round trip entirely, which is now two statuses:

```php
        // Moved from /resolve to /decide, and it still lands directly on
        // Resolved: `other` is inspection-exempt, so there is no intervening
        // Awaiting Inspection or Awaiting Approval to walk through.
```

- [ ] **Step 3: Correct the three comments**

`back-end/app/Http/Controllers/Api/IncidentInspectionController.php:275`:

```php
    /** Submit the findings: Open or Awaiting Inspection -> Awaiting Approval. Moves no stock. */
```

`back-end/app/Http/Controllers/Api/IncidentReportController.php:101` — replace
`(Awaiting Inspection)` in that comment with `(Awaiting Inspection)` retained,
since the sentence is specifically about a REJECTED report and is now exactly
right. Add one clause so the reader knows the other half exists:

```php
                // By NAME, because the ids are install-specific. `is_open` alone
                // cannot tell a report an admin REJECTED back to the inspector
                // (Awaiting Inspection) from one that is finished, so an
                // inspector's worklist built on it silently loses every
                // rejection. A submitted inspection is Awaiting Approval and
                // belongs to the admin.
```

`back-end/app/Models/IncidentItem.php:111` — the sentence describes a past bug
where adding a status returned stock to the picking pool. Keep it and add the
new name so the warning still covers the current vocabulary:

```php
     * Read off ITEM status, not the incident's. Deriving it from the incident
     * broke once already: adding "Awaiting Inspection" silently returned stock
     * under investigation to the picking pool, because the query filtered on
     * "Open". "Awaiting Approval" is the same trap. An item is quarantined
     * until somebody has actually decided what happened to it, whatever the
     * paperwork says.
```

- [ ] **Step 4: Run the whole backend incident suite**

```bash
cd back-end
php artisan test --filter=Incident
```

Expected: PASS. 156 total (149 baseline + 7 new).

- [ ] **Step 5: Commit**

```bash
git add back-end/tests back-end/app/Http/Controllers/Api/IncidentInspectionController.php back-end/app/Http/Controllers/Api/IncidentReportController.php back-end/app/Models/IncidentItem.php
git commit -m "test(incidents): assert the status a submitted inspection now lands on

Updated per occurrence rather than by find-and-replace: assertions about a
REJECTED report keep Awaiting Inspection, which is now exactly right
instead of incidentally right."
```

---

### Task 4: The admin's list

**Files:**
- Modify: `frontend/src/admin/components/incident/IncidentList.jsx:74-84` and `:205-217`

- [ ] **Step 1: Update `statusLabel`**

Replace the `statusLabel` memo at `frontend/src/admin/components/incident/IncidentList.jsx:74-84` with:

```jsx
  const statusLabel = useMemo(() => (incident) => {
    if (incident.is_open) return { text: 'Open', cls: 'ak-badge--amber' };
    const name = statusNameById[incident.status_id];
    if (name === 'Dismissed') return { text: 'Dismissed', cls: 'ak-badge--gray' };
    // The admin owes a decision on this one. Deliberately NOT green — nothing
    // is finished, and its stock is still quarantined.
    if (name === 'Awaiting Approval') return { text: 'Awaiting approval', cls: 'ak-badge--blue' };
    // Sent back: it is the INSPECTOR's turn, not the admin's. Grey rather than
    // blue so an admin scanning this list can see at a glance that they are not
    // the one holding it up.
    if (name === 'Awaiting Inspection') return { text: 'Sent back to inspector', cls: 'ak-badge--gray' };
    // Default to Resolved once reviewed — covers the brief window before the
    // status lookup has loaded, and any status other than Open/Dismissed.
    return { text: name || 'Resolved', cls: 'ak-badge--green' };
  }, [statusNameById]);
```

- [ ] **Step 2: Update the action button**

Replace the button block at `frontend/src/admin/components/incident/IncidentList.jsx:205-217` with:

```jsx
                    <td>
                      {/* A report at Awaiting Approval is waiting on the ADMIN
                          — its inspection is already submitted. Labelling it
                          "View" like a closed report gave the person who has to
                          act on it no signal that it needs them. A report sent
                          back to the inspector is NOT theirs to act on, and
                          must not offer Decide. */}
                      <button
                        type="button"
                        className={`ak-btn ak-btn--sm${
                          canResolve && st.text === 'Awaiting approval' ? ' ak-btn--primary' : ''
                        }`}
                        onClick={() => setSelected(i)}
                      >
                        {!canResolve
                          ? 'View'
                          : st.text === 'Awaiting approval'
                            ? 'Decide'
                            : i.is_open ? 'Review' : 'View'}
                      </button>
                    </td>
```

- [ ] **Step 3: Verify the frontend suite still passes**

```bash
cd frontend
./node_modules/.bin/react-scripts test --watchAll=false --testPathPattern "incident"
```

Expected: PASS, 112 tests. Nothing here is covered by a test yet — Task 5 adds
the queue test, and this list has no test file. Confirm no regression.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/components/incident/IncidentList.jsx
git commit -m "feat(incidents): the admin's list distinguishes theirs from the inspector's

Awaiting approval is blue with a Decide button; a report sent back to the
inspector is grey with View, because it is not the admin's to act on."
```

---

### Task 5: The inspector's queue clears the submitted report

The bug as the user experienced it: submitting appeared to do nothing, because
the report stayed selected and on screen.

**Files:**
- Modify: `frontend/src/admin/pages/IncidentInspectionPage.jsx`
- Test: `frontend/src/admin/pages/IncidentInspectionPage.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/pages/IncidentInspectionPage.test.jsx`:

```jsx
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import IncidentInspectionPage from './IncidentInspectionPage';
import { incidentReportsAPI } from '../services/api';

/**
 * The inspector's queue.
 *
 * Submitting an inspection used to look like it did nothing: the report's new
 * status was still in INSPECTABLE_STATUSES, so it stayed in the list, stayed
 * selected, and re-rendered with a live Submit button — wearing a "Sent back"
 * badge that meant the opposite of what had just happened.
 */

jest.mock('../services/api', () => ({
  incidentReportsAPI: { list: jest.fn(), get: jest.fn() },
  locationsAPI: { getAll: jest.fn() },
  productsAPI: { getAll: jest.fn() },
  inventoryBatchesAPI: { getAll: jest.fn() },
}));

jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ can: () => true }),
}));

const queued = {
  incident_id: 5,
  incident_number: 'INC-0005',
  reason: 'defective',
  status_name: 'Open',
  location_name: 'Carmen',
};

beforeEach(() => {
  incidentReportsAPI.list.mockResolvedValue({ data: [queued] });
  incidentReportsAPI.get.mockResolvedValue({ data: { ...queued, items: [], evidence: [] } });
});

test('a report waiting to be inspected is offered in the queue', async () => {
  render(<IncidentInspectionPage />);

  expect(await screen.findByText('INC-0005')).toBeInTheDocument();
});

// The heart of it: once submitted, the report belongs to the admin. Leaving it
// selected is what made submitting feel like a no-op.
test('a report that has left the queue is deselected, not left on screen', async () => {
  const { rerender } = render(<IncidentInspectionPage />);
  await screen.findByText('INC-0005');

  // The report is now Awaiting Approval, so the queue no longer returns it.
  incidentReportsAPI.list.mockResolvedValue({ data: [] });
  rerender(<IncidentInspectionPage key="reloaded" />);

  await waitFor(() => {
    expect(screen.getByText(/Nothing is waiting to be inspected/i)).toBeInTheDocument();
  });
  expect(screen.queryByRole('button', { name: /submit inspection/i })).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run the test to verify the first one passes and the second is meaningful**

```bash
cd frontend
./node_modules/.bin/react-scripts test --watchAll=false --testPathPattern "IncidentInspectionPage"
```

If the mock surface is wrong the render will throw — read the error and add the
missing mock rather than guessing. `IncidentDocument` pulls `locationsAPI`,
`productsAPI` and `inventoryBatchesAPI` on mount, which is why they are stubbed.

- [ ] **Step 3: Clear the selection when the report leaves the queue**

In `frontend/src/admin/pages/IncidentInspectionPage.jsx`, replace `loadQueue`
(:47-62) with a version that drops a selection which is no longer inspectable:

```jsx
  const loadQueue = useCallback(async () => {
    setLoadingQueue(true);
    try {
      const res = await incidentReportsAPI.list();
      const rows = Array.isArray(res?.data) ? res.data : [];
      const inspectable = rows.filter((r) =>
        INSPECTABLE_STATUSES.includes(r.status_name)
        && !INSPECTION_EXEMPT_REASONS.includes(r.reason));
      setQueue(inspectable);
      // A submitted inspection belongs to the ADMIN now, so it is gone from
      // this list. Leaving it selected left the document on screen in `inspect`
      // mode with a live Submit button, which is what made submitting look like
      // it had done nothing at all.
      setSelectedId((current) => (
        current !== null && !inspectable.some((r) => String(r.incident_id) === String(current))
          ? null
          : current
      ));
      setQueueError(null);
    } catch (err) {
      setQueueError(err.message || 'Failed to load reports awaiting inspection.');
      setQueue([]);
    } finally {
      setLoadingQueue(false);
    }
  }, []);
```

Then simplify the submit handler at :160, which no longer needs to refetch a
report that has left:

```jsx
                onSubmitted={() => { loadQueue(); }}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd frontend
./node_modules/.bin/react-scripts test --watchAll=false --testPathPattern "IncidentInspectionPage"
```

Expected: PASS, 2 tests.

- [ ] **Step 5: Prove the test would catch a regression**

Revert the `setSelectedId` call you added in Step 3, re-run, and confirm the
second test fails. Restore it. A test that passes with and without the fix is
worth nothing, and three review rounds on the preceding branch found exactly
that.

- [ ] **Step 6: Run the whole frontend incident suite**

```bash
cd frontend
./node_modules/.bin/react-scripts test --watchAll=false --testPathPattern "incident"
```

Expected: PASS, 114 total.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/pages/IncidentInspectionPage.jsx frontend/src/admin/pages/IncidentInspectionPage.test.jsx
git commit -m "fix(incidents): a submitted report leaves the inspector's screen

It stayed selected after submitting, so the document sat there in inspect
mode with a live Submit button and the only visible change was one word in
the subtitle. That is what the bug report described as nothing happening."
```

---

### Task 6: Full verification

**Files:** none, unless something is found.

- [ ] **Step 1: Backend**

```bash
cd back-end
php artisan test --filter=Incident
```

Expected: PASS, 156 tests (149 baseline + 7 new).

If MySQL is not running every test fails with
`SQLSTATE[HY000] [2002]`. Start it before concluding anything.

- [ ] **Step 2: Frontend**

```bash
cd frontend
./node_modules/.bin/react-scripts test --watchAll=false
```

Expected: 216 passed, **5 failed** — `navConfigureMap`, `App.test`,
`InventoryManagement`. Those 5 are pre-existing and unrelated. Any OTHER failure
is yours.

- [ ] **Step 3: Verify the live data landed correctly**

```bash
cd back-end
php artisan tinker --execute="
\$rows = DB::table('incident_reports as ir')
  ->join('status_lookup as s','s.status_id','=','ir.status_id')
  ->where('s.status_category','incident')
  ->selectRaw('s.status_name, count(*) as n')->groupBy('s.status_name')->get();
foreach(\$rows as \$r){ echo sprintf('%-24s %d', \$r->status_name, \$r->n).PHP_EOL; }
"
```

Expected: `Awaiting Approval 2`, `Resolved 6`, `Dismissed 2`, and no
`Awaiting Inspection` rows. Those two are `INC-20260811-7F249` and
`INC-20260812-E115E`.

- [ ] **Step 4: Check the real screens**

```bash
cd frontend
npm run dev
```

As a user holding `incident_reports.inspect`, open Inspections: the two migrated
reports should NOT be there (they belong to the admin now), and nothing should
wear a "Sent back" badge.

As a user holding `incident_reports.resolve`, open Incident Reports: both should
read **Awaiting approval** in blue with a **Decide** button.

Then run one report end to end — inspect a fresh one, submit, confirm it leaves
the inspector's queue and appears in the admin's; reject it, confirm it returns
to the inspector badged "Sent back" and that the admin's list shows "Sent back to
inspector" with no Decide button.

- [ ] **Step 5: Commit anything the manual check turned up**

If steps 1-4 were clean there is nothing to commit.

---

## Deployment note

The migration edits live rows. **Back up `airkingV5` before running
`php artisan migrate` in production.** `down()` is lossy: it re-merges the two
populations, so a rollback followed by a roll-forward would misroute any report
rejected in between.
