# Audit & Evidence Trail — Design

**Date:** 2026-09-30
**Branch:** balorov21
**Status:** Approved (brainstorming session)
**Related:** docs/superpowers/specs/2026-07-09-auditor-verification-design.md,
docs/superpowers/specs/2026-07-21-inventory-stock-in-out-transfers-design.md,
docs/superpowers/specs/2026-07-20-po-location-batched-inventory-fifo-design.md

## Problem

Auditors can see totals but not evidence. Transaction Management is the closest
thing to a transaction ledger, and it is assembled in the browser: it fetches
receivings, issuances, transfers, adjustments, incidents and warranty claims in
full, then stitches and values them client-side. Four consequences, all
confirmed by the user as real today:

1. **Peso valuation is wrong.** Value is computed from whichever cost field
   happens to exist (`unit_cost` -> `unit_price` -> `cost_price` ->
   `product.cost_price` -> `0`), not from the cost the stock was actually
   received at. `inventory_batches` has **no unit-cost column at all**, so the
   true cost is only reachable through `receiving_id -> receiving_details`, and
   is ambiguous when one receiving has mixed line prices.
2. **Wrong rows are counted.** Drafts and voided records can land in totals;
   some movement types are absent entirely.
3. **Statuses are guessed.** Status is normalised by string matching
   (`includes('complete')`), and `'Posted'` is invented when no status exists,
   so a displayed status can disagree with the real `status_id`.
4. **Nothing reconciles.** No view proves `opening + in - out = closing` for a
   period. A discrepancy can exist with nobody able to see it.

**Goal:** an inventory auditor or finance auditor can start from any figure and
walk down to the evidence behind it — the transactions, the line items, the
batches and serials, the source document, and who did what and when.

## Decisions (user-confirmed)

- **Primary capability:** drill from any number to its evidence. Not a flat
  ledger, not a sign-off worklist, not report export — those are secondary.
- **Entry point:** a new purpose-built **Audit & Evidence Explorer** page.
  Transaction Management is left alone as the operational view.
- **Auditor roles:** keep the single existing `auditor` role, with two lenses
  (Inventory / Finance) both visible to any holder. No new roles.
- **History:** go-live forward, plus a one-time backfill of existing records,
  with reconstructed rows marked as such.
- **Coverage (all four groups in scope):** core four (receiving, issuance,
  transfer, adjustment); loss and return events (incident write-off/return,
  warranty out/return, purchase return); consumption and sets (installation
  material consumption, set/BOQ picking); non-stock financial events (budget
  allocation, PO encumbrance).
- **Architecture:** a canonical posted ledger — immutable rows written at
  transaction time through one choke point, with costs frozen at posting.

### Approach considered and rejected

An on-demand read model over the existing tables (no new table) was rejected:
a past audited figure would change whenever someone edited a source row, which
is the one property an auditor cannot accept. A hybrid (compute live, freeze on
period close) was rejected for leaving open periods mutable and requiring two
code paths that must agree forever.

## Architecture

Three layers, each independently testable.

1. **Posting layer** — `App\Services\Ledger\LedgerPoster`, the single writer.
   Called by the existing services inside the DB transaction they already open:
   `ReceivingPostingService`, `InventoryBatchService`, `TransferService`,
   `FifoPickingService`, `IncidentService`, `WarrantyClaimService`,
   `IssuanceOrderService`, `BudgetService`. Nothing else may write the ledger.
2. **Ledger store** — the `inventory_ledger` table. Append-only: no updates,
   no deletes.
3. **Read layer** — `LedgerQueryService` (totals, rows, reconciliation) and
   `EvidenceResolver` (ledger row -> source document + change history). The
   Explorer page talks only to these.

## Data model

### New table `inventory_ledger`

| Column | Notes |
| --- | --- |
| `ledger_id` | PK |
| `occurred_at` | when the movement happened |
| `posted_at` | when it was recorded; a gap from `occurred_at` is the backdating signal |
| `direction` | `in` / `out` / `neutral` (neutral = finance-only) |
| `movement_type` | see the posting table below |
| `lens` | `inventory` / `finance` / `both` — drives the two tabs |
| `product_id` | nullable (finance-only rows) |
| `batch_id` | nullable |
| `serial_number` | nullable |
| `quantity` | integer; `0` for finance-only rows |
| `unit_cost` | decimal, frozen at posting |
| `extended_value` | decimal, frozen at posting; nullable when `cost_basis = 'unavailable'` |
| `cost_basis` | `actual` / `derived_weighted_avg` / `product_default` / `unavailable` |
| `from_location_id`, `to_location_id` | nullable |
| `source_type`, `source_id`, `source_line_id` | the evidence pointer |
| `correlation_id` | groups the legs of one business event |
| `actor_user_id` | nullable only for system-generated rows, which are labelled |
| `reverses_ledger_id` | set on a reversing entry, else null |
| `origin` | `posted` (recorded live) / `reconstructed` (backfilled) |
| `posting_key` | deterministic idempotency key, unique index |
| `notes` | reason text where the source has one |
| `created_at`, `updated_at` | |

Indexes: `(occurred_at)`, `(product_id, occurred_at)`,
`(movement_type, occurred_at)`, `(source_type, source_id)`,
`(correlation_id)`, `(actor_user_id, occurred_at)`, unique `(posting_key)`.

There is **no status column**. A row exists because the movement happened.

For a finance-only row (`direction = 'neutral'`), `quantity` is `0`, `unit_cost`
is null, and the money lives in `extended_value` with
`cost_basis = 'actual'` — the amount is taken from the budget or PO record
itself, so it is never derived.

### New table `ledger_drift_findings`

`finding_id`, `check_type`, `source_type`, `period_start`, `period_end`,
`scope` (json: product/location where applicable), `expected`, `actual`,
`variance`, `detected_at`, `resolved_at`, `resolved_by`, `resolution_note`.

### Change to `inventory_batches`

Add `unit_cost` (decimal, nullable), populated at receiving from the actual
receiving line price. FIFO consumption then reads a real cost per batch instead
of the fallback chain. **This is the change that makes valuation correct rather
than plausible.**

Migrations must be written Postgres-safe (production runs Postgres via
Supabase/Aiven) and applied to `airkingV5_test` as well as dev.

### Why not extend `inventory_batch_movements`

It `cascadeOnDelete` with its batch (an audit record that deletes itself is
worthless), carries no cost, requires a non-null `from_location_id`, has no
reversal concept, and covers only issuance and transfer. It stays exactly as
it is — the operational FIFO consumption record that picking logic depends on.
The ledger is a separate, broader, immutable record, and the drift check
cross-verifies the two. `inventory_ledger` therefore declares no foreign keys of
its own.

## Posting rules

Drafts, pending records and unapproved documents never post. That single rule
eliminates both "wrong rows counted" and "statuses are guessed": there is
nothing left to filter at read time and nothing to infer from a string.

| Source | Posts at | Rows |
| --- | --- | --- |
| Receiving | posting of a receiving session (not draft staging, not submit) | one `in` per received line, batch + cost set |
| Issuance / issue-out | creation / scan commit | one `out` per consumed batch slice, each at its own cost |
| Transfer | dispatch, then receipt | `transfer_out` then `transfer_in`, shared `correlation_id`; in-transit is visible as an out with no matching in |
| Adjustment | creation (`AdjustmentController::store`) — stock moves there; there is no approval step | `in` or `out`, reason in `notes` |
| Incident | write-off decision / return acceptance | `incident_writeoff` out, `incident_return` in |
| Warranty | claim send-out / replacement receipt | `warranty_out`, `warranty_return` |
| Purchase return | posting | `out` |
| Material consumption, set/BOQ pick | FIFO pick commit | `out` per batch slice |
| Budget / encumbrance | allocation verified, PO approved | `neutral`, finance lens, no product |

**Reversals, not edits.** Voiding, cancelling or reverting a posted document
posts a mirror row with `reverses_ledger_id` set. Quantities are always stored
positive: the mirror row carries the **opposite `direction`** (an `out` is
reversed by an `in`), the same quantity, and the original row's frozen
`unit_cost` and `cost_basis` — never a freshly resolved cost, or a reversal
could undo a different amount of value than it reversed. Both rows stay
visible; the Explorer shows the pair struck through with the reason. An auditor
can always see that something was undone, and by whom.

**Idempotency.** `LedgerPoster` computes a deterministic `posting_key`
(`receiving:1234:line:56:in`, `reversal:9981`) under a unique index. A retried
request, a double-clicked button or a re-run backfill cannot duplicate value.
A plain unique index works identically on MySQL and Postgres — no partial
indexes.

**Build order.** The ledger contract is designed once and filled in four
shippable phases: core four -> loss and return -> consumption and sets ->
finance events. The Explorer renders whatever movement types exist and displays
the drift check's unposted-source count, so partial coverage is honest rather
than invisible.

## Backfill

`php artisan ledger:backfill` with `--from`, `--to`, `--source=`, `--chunk=`,
`--dry-run`. Chunked and resumable via `posting_key`, so an interrupted run is
simply re-run. Receivings first (they establish batches and costs), then every
`out` source. All rows get `origin = 'reconstructed'`. `occurred_at` comes from
the source's own event timestamp, falling back to `created_at`.

Historical batches have no `unit_cost`, so the backfill resolves it through
`receiving_id -> receiving_details` and records its confidence in `cost_basis`:

- `actual` — one unambiguous receiving line price
- `derived_weighted_avg` — the receiving had mixed line prices; weighted
  average used
- `product_default` — the product's list cost, used when no PO line price exists
- `unavailable` — no price recoverable; quantity recorded, value null

The Explorer marks non-`actual` rows visibly and shows how much of each total
rests on each basis. An auditor is told which numbers are evidence and which
are reconstruction; quietly averaging would destroy that distinction.

The command prints a per-source report: rows written, rows skipped and why, and
the cost-basis breakdown.

## Drift check

`php artisan ledger:drift-check`, scheduled nightly and runnable on demand.
Three comparisons:

1. **Source vs ledger** — per source table and period, totals derived from the
   source against ledger totals. Catches a write path that forgot to post.
2. **Ledger vs `inventory_batch_movements`** — the operational FIFO record
   cross-verifies ledger `out` rows. Different code, different purpose, so
   agreement is meaningful.
3. **Ledger vs current stock** — net ledger quantity per product/location
   against live `inventory`. A mismatch means stock moved unrecorded.

Findings persist in `ledger_drift_findings` and appear in the Explorer's
Exceptions panel, staying open until resolved with a note. A gap becomes an
audit finding with an owner rather than silent corruption. **A failed drift
check writes its own finding** — silence must never look like health.

## Period reconciliation

For any product, location and date range: `opening + in - out = closing`, each
term clickable into the rows composing it. Because costs are frozen at posting,
the value column reconciles the same way the quantity column does. This does
not exist in the system today.

## Audit & Evidence Explorer

New page at `/admin/audit-evidence`. Five drill levels, breadcrumbed so the
period and filters never reset:

1. **Scope** — period, location, product/category, user; lens tabs
   **Inventory** and **Finance**. Summary tiles: quantity in/out, value in/out,
   net movement, event count, cost-basis breakdown.
2. **Breakdown** — pivot the period by movement type, product, location, user
   or source document. Answers "where did this peso total of stock-out come
   from" in one step.
3. **Movement ledger** — the rows themselves, grouped by `correlation_id` so a
   transfer's two legs and an issuance's batch slices read as one event.
   Reversals struck through and paired with what they reversed; reconstructed
   rows and non-`actual` costs marked.
4. **Evidence bundle** — one event in a drawer: the source document with its
   real lines, batches, serials and frozen costs; the actor chain (requested,
   verified, approved, posted, with timestamps); the change history from
   `audit_trail` via the existing `RecordHistoryService`; `occurred_at` vs
   `posted_at` flagged when they diverge; attachments where the source has them
   (incident photos, receiving receipt, delivery receipt).
5. **Lifecycle** — from any serial or batch, every ledger row it appeared in,
   from receiving to final disposition.

Alongside: the **Reconciliation** panel and the **Exceptions** panel.

Every level exports to CSV and PDF with the active filters printed on the
export, so the document describes its own scope. Filter state lives in the URL,
so an auditor can link to exactly the evidence behind a finding.

### API

Under `/api/audit-evidence/`: `summary`, `breakdown`, `movements`,
`events/{correlationId}`, `serials/{serial}/lifecycle`,
`batches/{id}/lifecycle`, `reconciliation`, `exceptions`,
`exceptions/{id}/resolve`, `export/{view}`.

All filtering, grouping, sorting and pagination happen server-side. No page
pulls a full table into the browser — that pattern is what this replaces.

### Components

```
pages/AuditEvidenceExplorer.jsx        shell: scope, lens, level routing
components/audit/SummaryTiles.jsx
components/audit/BreakdownTable.jsx
components/audit/MovementLedgerTable.jsx
components/audit/EvidenceDrawer.jsx
components/audit/LifecycleTimeline.jsx
components/audit/ReconciliationPanel.jsx
components/audit/ExceptionsPanel.jsx
hooks/useAuditEvidence.js              query state <-> URL sync
```

Kept small and single-purpose rather than one large page; Transaction
Management at 1,289 lines is the cautionary example.

### Permissions

One new feature in `PermissionSeeder`:

```php
'audit_evidence' => [
    'read'              => ['admin', 'auditor'],
    'resolve_exception' => ['auditor'],
],
```

Both lenses are visible to any holder of `read`. The nav entry is added through
the existing bundle/permission mirror so it renders by permission, not role
name. The page is read-only by construction: resolving an exception is its only
write.

## Error handling

- **Posting fails loudly.** `LedgerPoster` validates the fields required for
  the given `movement_type` and throws `LedgerPostingException` with context.
  This is deliberately the opposite of the `Auditable` trait, which catches and
  `report()`s so audit logging can never break a save. An unrecorded movement
  is worse than a failed operation, so the exception propagates and the
  caller's transaction rolls back.
- **One deliberate exception.** A legacy batch still in stock may have no
  `unit_cost`. Blocking a warehouse issuance over a historical data gap is the
  wrong trade, so the row posts with `cost_basis = 'unavailable'` and a null
  value, and the drift check raises it. Quantity accuracy is never
  compromised; the value gap is made visible instead of hidden.
- **Read layer.** Oversized period requests are capped server-side with a
  message naming the cap, never silently truncated. Empty states distinguish
  "no movements in this period" from "this movement type is not posted yet", so
  an unshipped phase never reads as zero activity.
- **Exports** are generated server-side and routed through the existing
  `ReportRun` infrastructure when large, so a wide range queues rather than
  times out.

## Testing

**Unit** — `LedgerPoster` per movement type: `posting_key` determinism,
reversal pairing, transfer two-leg netting to zero, FIFO slices costed
correctly when one line spans several batches at different costs, `cost_basis`
resolution including the mixed-price receiving case.

**Feature, one per write path** — receiving post, issuance complete, transfer
dispatch + receipt, adjustment approve, incident write-off and return, warranty
out and return, purchase return, material consumption, set pick, budget verify,
PO approve. Each asserts exactly one posting. Plus the negative cases:

- drafts and pending records post nothing
- a double-submitted request posts once
- a void posts a reversal and never mutates the original
- a forced posting failure leaves neither a ledger row nor a stock change

**Reconciliation property test** — over randomised operation sequences,
`opening + in - out == closing`, and net ledger quantity equals `inventory`
quantity.

**Backfill** — running twice yields identical row counts (idempotent),
`--dry-run` writes nothing, cost-basis flags land correctly on mixed-price
historical receivings.

**Drift check** — remove a ledger row deliberately and assert the finding
appears with the right variance; resolve it and assert it closes.

**API** — permission gating (a non-holder gets 403), lens filtering,
server-side pagination, export scope headers.

**Frontend** — following the existing `*.test.jsx` patterns:
`MovementLedgerTable` (reversal pairing, reconstructed markers),
`ReconciliationPanel` arithmetic display, `useAuditEvidence` URL round-trip.

### CI constraints

These have bitten this project before and belong in the plan:

- New migrations must be applied to `airkingV5_test` as well as dev.
- CI's database is empty and runs UTC; the local one is populated and on Berlin
  time. No test may rely on seeded rows, and every `occurred_at` / `posted_at`
  assertion uses explicit timestamps rather than "now" — otherwise the
  backdating-detection tests pass locally and fail in CI.
