# One status, one owner: separating "inspected" from "sent back"

**Date:** 2026-08-14
**Status:** Approved, ready for planning

## The problem

`Awaiting Inspection` carries two opposite meanings, and the name describes
neither of them.

It is written in two places:

| Where | Meaning | Whose turn |
|-------|---------|------------|
| `IncidentService::submitInspection()` :244 | the inspector submitted their findings | the **admin** |
| `IncidentService::reject()` :363 | the admin sent it back | the **inspector** |

Both screens read that one status and reach opposite conclusions:

- `IncidentInspectionPage.jsx:32` puts it in the **inspector's** queue, and
  line 127 badges it **"Sent back"**.
- `IncidentList.jsx:81` shows it to the **admin** as an action item, and
  line 213 offers a **Decide** button. The comment at line 201 states outright
  that "a report at Awaiting Inspection is waiting on the ADMIN, not the
  inspector".

So every submitted inspection appears in the inspector's queue wearing a badge
that tells them the admin rejected it. Nothing did. The inspector cannot tell a
real rejection from their own submission, and the only way to find out is to
open the report and look for a rejection note.

The name is also backwards: a report at `Awaiting Inspection` has, in the common
case, already been inspected.

### Why the user hit this

Submitting an inspection appears to do nothing. `IncidentInspectionPage.jsx:160`
refetches the queue and the report, but the report's new status is still in
`INSPECTABLE_STATUSES`, so it stays in the list, stays selected, and re-renders
in `inspect` mode with an enabled Submit button. The only visible change is the
status word in the subtitle — and the appearance of a "Sent back" badge that
means the opposite of what happened.

## State of the live data

Checked against `airkingV5` on 2026-08-14:

| Status | Rows |
|--------|------|
| Awaiting Inspection | 2 |
| Resolved | 6 |
| Dismissed | 2 |
| Open | 0 |

Both `Awaiting Inspection` rows — `INC-20260811-7F249` (id 16) and
`INC-20260812-E115E` (id 18) — have `inspected_at` set, real findings, and
`rejection_note IS NULL`. Both are genuinely inspected work waiting on an admin,
so both migrate cleanly with no guessing. **There are currently zero ambiguous
rows.**

The migration must still implement the full rule below, because it has to be
correct against whatever is in the table on the day it runs — including reports
that get rejected between now and the deploy.

## Constraints

- **`rejection_note` is already cleared on resubmit** — `IncidentService.php:255`
  sets it to null inside `submitInspection()`, with a comment saying a
  resubmission answers the rejection. This makes the note an exact marker: it is
  non-null only on a report the admin sent back that the inspector has not yet
  resubmitted. The migration rule below relies on that, and nothing needs to
  change here.
- **Statuses are read by NAME, never by id.** Ids are install-specific. Every
  call site already follows this.
- **`Open` cannot be collapsed away.** The two inspection-exempt reasons
  (`zone_mismatch`, `other`) create no `incident_items` and are confirmed
  straight from `Open` by an admin — `confirm()` :306-312 bypasses the status
  guard for them.
- **Item status is independent.** `IncidentItem::quarantinedBatchIds()` reads
  item status, not the incident's, deliberately — `IncidentItem.php:111` records
  that deriving it from the incident broke once before. Nothing in this change
  touches item status.

## Design

### The status model

Five statuses, each with exactly one meaning and one owner:

| Status | Written by | Whose turn |
|--------|-----------|------------|
| `Open` | filing | inspector — or the admin directly, for the exempt reasons |
| `Awaiting Inspection` | `reject()` | inspector — the admin sent it back |
| `Awaiting Approval` **(new)** | `submitInspection()` | admin |
| `Resolved` | `confirm()` | nobody |
| `Dismissed` | `dismiss()` | nobody |

One status is added and none is renamed. `Awaiting Inspection` keeps its name
and finally means what it says, which also makes the existing "Sent back" badge
correct instead of wrong.

`Open` and `Awaiting Inspection` are both the inspector's turn, and that is
deliberate rather than redundant: the pair distinguishes "never inspected" from
"inspected once and sent back", which is exactly the fact the inspector needs
and cannot currently see.

### Backend

**`IncidentStatusSeeder`** — add `Awaiting Approval` under
`status_category = 'incident'`, using the same idempotent `updateOrInsert` as
the existing entry.

**`IncidentService::submitInspection()`** (:221)
- accepts from `Open` or `Awaiting Inspection`, unchanged
- writes `Awaiting Approval` instead of `Awaiting Inspection`
- already clears `rejection_note` (:255) — unchanged, and it is what makes the
  migration rule exact

**`IncidentService::confirm()`** (:304) — the guard at :308 requires
`Awaiting Approval`. The exempt-reason bypass is unchanged.

**`IncidentService::reject()`** (:356) — the guard at :358 requires
`Awaiting Approval`; the write at :363 sets `Awaiting Inspection`. Its docblock
currently explains that the status deliberately does not move; it is rewritten
to say why it now does.

**`IncidentService::decide()`** — the already-decided guard (`Resolved`,
`Dismissed`) is unchanged.

Three comments naming the old transition are corrected:
`IncidentInspectionController.php:275`, `IncidentReportController.php:101`,
`IncidentItem.php:111`.

### The migration

A single migration, run before the code change takes effect.

1. **Create `Awaiting Approval` in `status_lookup` inside the migration**, not
   relying on the seeder. A live deploy may not re-seed, and
   `IncidentReport::statusId()` throws on a missing name.
2. Move rows matching **all three** conditions to `Awaiting Approval`:
   - `status_id` = the `Awaiting Inspection` id
   - `rejection_note IS NULL`
   - `inspected_at IS NOT NULL`
3. Leave every other row untouched.

Because `submitInspection()` clears the note (:255), a non-null
`rejection_note` at `Awaiting Inspection` can only mean "sent back and not yet
resubmitted". The rule is therefore exact rather than a guess, and there is no
population of genuinely ambiguous rows.

Rule 3 still exists as a floor: anything that does not match all three
conditions — a row with no `inspected_at`, or any shape not anticipated here —
stays with the **inspector**, which is the recoverable direction. A report
wrongly sent to the inspector costs one resubmit; a report wrongly sent to the
admin can be confirmed, and **confirming is the one action that moves stock**.

The migration reports how many rows it moved and how many `Awaiting Inspection`
rows it left behind, so the result can be checked against expectation.

**`down()` is lossy and must say so.** It moves every `Awaiting Approval` row
back to `Awaiting Inspection`, which re-merges the two populations the `up()`
separated. Rolling forward again would re-split them by the same rule, so a
report rejected between the two runs would be misrouted. This is acceptable
because the window is a rollback, but it is not a clean round trip and the
migration should not pretend otherwise.

### Frontend

**`IncidentInspectionPage.jsx`** — `INSPECTABLE_STATUSES` at :32 stays
`['Open', 'Awaiting Inspection']`. The literal does not change; its meaning
narrows to "the inspector's turn", which is what it always claimed to be. The
"Sent back" badge at :127 becomes truthful with no edit.

A submitted report now leaves the queue. The page should also clear
`selectedId` when the submitted report is no longer in the refetched queue, so
the document does not sit on screen in `inspect` mode with a live Submit button
after it has moved to the admin.

**`IncidentList.jsx`**
- `statusLabel` (:74-84) gains `Awaiting Approval` → "Awaiting approval", blue,
  the admin's action state.
- `Awaiting Inspection` is relabelled to "Sent back to inspector" in a
  non-action colour, so an admin can see at a glance that it is not on them.
- The **Decide** button (:213) keys on `Awaiting Approval`.

## Testing

The transitions:

- `submitInspection` moves `Open` → `Awaiting Approval`
- `submitInspection` moves `Awaiting Inspection` → `Awaiting Approval`
  (the resubmit path)
- `submitInspection` clears a `rejection_note` that was set
- `reject` moves `Awaiting Approval` → `Awaiting Inspection`
- `confirm` succeeds from `Awaiting Approval`

The guards, which are what the old model could not express:

- **`confirm` refuses a report at `Awaiting Inspection`.** An admin must not be
  able to approve something they just sent back. Under the old model this was
  indistinguishable from a submitted inspection and would have been allowed.
- `reject` refuses a report at `Open` — there is no submitted inspection to
  reject.
- exempt reasons (`zone_mismatch`, `other`) still confirm straight from `Open`

The queues:

- a submitted report leaves the inspector's queue
- a rejected report returns to it, badged "Sent back"
- a submitted report appears in the admin's list as "Awaiting approval" with a
  Decide button

The migration:

- a row with `inspected_at` and no note becomes `Awaiting Approval`
- a row with a note stays `Awaiting Inspection`
- a row with no `inspected_at` stays `Awaiting Inspection`
- running it twice is a no-op

Three existing backend test files assert the old transition and need updating:
`IncidentInspectionEndpointTest`, `IncidentDecisionOrderTest`,
`IncidentStandaloneFilingTest`.

## Baseline

Recorded before any change, so a regression is attributable:

- backend `php artisan test --filter=Incident` — **149 passed, 547 assertions**
- frontend incident suite — **112 passed, 8 suites**
- frontend full suite — 214 passed, **5 pre-existing failures** in
  `navConfigureMap`, `App.test`, `InventoryManagement`, present at the base of
  the preceding branch and unrelated

Tests run against `airkingV5_test` per `phpunit.xml`, not the live database.

## Out of scope

- The inspector's editable evidence checklist rendering orphaned attachments as
  flat text without thumbnails, where the read-only admin view now renders them
  as full cards. Pre-existing asymmetry, noted during the preceding branch.
- Any audit trail of rejections. Clearing `rejection_note` on resubmit loses the
  record that a round trip happened. If that history is wanted it belongs in an
  audit log, not in a mutable column on the report.
