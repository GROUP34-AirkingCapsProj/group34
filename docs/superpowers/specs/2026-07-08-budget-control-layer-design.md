# Budget Allocation as the Financial Control Layer — Design

**Date:** 2026-07-08
**Branch:** backupV3
**Status:** Approved (brainstorming session)

## Problem

The budget allocation module records numbers but controls nothing:

1. **No enforcement.** The frontend computes an `overBudget` flag but never blocks
   submission; the backend approves PRs even when available budget is zero or
   negative. Overspending is unlimited and silent.
2. **Optional.** `purchase_requests.budget_id` is nullable — a PR without a budget
   bypasses tracking entirely.
3. **Committed funds leak.** Archiving an approved PR never releases its
   `committed` amount; the budget looks permanently consumed.
4. **Estimate/actual drift.** Commit uses the PR's *estimated* total
   (`PurchaseRequestController::approve`), but release uses the PO's *actual*
   total (`ReceivingController`, `PurchaseOrderController::update`). Any
   difference corrupts `committed` forever.
5. **No insight.** No utilization view, no threshold alerts, no connection to the
   PO Recommendation analytics engine.

**Goal:** make budget allocation the financial control layer of the procurement
cycle — enforced, leak-free, auditable, and visible — so the feature is
defensible to a capstone panel.

## Decisions (user-confirmed)

- **Hard block:** PRs that exceed available budget are rejected by the backend
  at submission *and* approval. No override path; Admin raises the budget if
  genuinely needed.
- **Budget required:** every PR must link to an active budget covering today for
  its location. No active budget → cannot submit a PR.
- **Full build:** enforcement + lifecycle fixes + all four visibility features
  in one plan.
- **Architecture:** budget ledger (immutable `budget_transactions` table) +
  `BudgetService` maintaining the existing cached columns.

## Architecture

### New table: `budget_transactions`

Immutable append-only ledger. Rows are never updated or deleted.

| column | type | notes |
|---|---|---|
| `txn_id` | PK auto-increment | |
| `budget_id` | FK → `budget_allocations.budget_id` | |
| `txn_type` | enum/string: `commit`, `release`, `spend`, `adjustment` | |
| `amount` | decimal(12,2), always positive | type defines direction |
| `pr_id` | nullable FK → `purchase_requests.pr_id` | paper trail |
| `po_id` | nullable FK → `purchase_orders.po_id` | paper trail |
| `performed_by` | nullable FK → `users.user_id` | |
| `notes` | nullable string | e.g. "PR archived", "estimate surplus returned" |
| `created_at` | timestamp | no `updated_at` (immutable) |

Migration applied via `php artisan migrate --path=...` (repo convention: schema
is SQL-dump based; blanket `migrate` fails). Also apply to `airkingV5_test`.

### New service: `App\Services\BudgetService`

The **only** code allowed to mutate `budget_allocations.committed` / `spent`.
Those columns remain as cached totals so existing reads keep working.

- `checkAvailable(int $budgetId, float $amount): void` — row-locked
  (`lockForUpdate`) inside the caller's transaction; throws
  `InsufficientBudgetException` (422 → clear message: "Estimated total ₱X
  exceeds available budget ₱Y for {period_label}").
- `commit(BudgetAllocation $budget, PurchaseRequest $pr): void` — availability
  check, then `commit` txn + `committed += pr.estimated_total`.
- `release(PurchaseRequest $pr, string $reason): void` — computes the PR's
  **net outstanding commitment** from the ledger (its `commit` txns minus its
  `release` txns minus commitment released via `spend`) and releases exactly
  that. Never guesses amounts; reverses recorded ones. No-op when net is zero.
- `spend(PurchaseOrder $po): void` — idempotent (skips if a `spend` txn for
  this `po_id` already exists). Writes `spend` txn for `po.total_amount`
  (`spent += total`), and reduces the PR's outstanding commitment by
  `min(outstanding, total)` (`committed -=` same). If actual > estimate the
  extra spend is still recorded truthfully — available may dip negative;
  prevention lives at approval time, recording stays honest. When the PR is
  fully consumed (fully-received PO, non-partial conversion), any residual
  commitment is auto-released with note "estimate surplus returned".
- `adjust(BudgetAllocation $budget, float $newTotal, User $user, ?string $note): void`
  — writes `adjustment` txn for the delta; guards `newTotal >= committed + spent`.

Concurrency: `commit()` and `spend()` run inside `DB::transaction` with
`lockForUpdate()` on the budget row, so two simultaneous approvals cannot both
pass the same availability check.

### Enforcement gates

1. **PR submission** (`PurchaseRequestController::store`):
   - `budget_id` becomes `required`.
   - Budget must belong to the PR's `location_id` and be active
     (`period_start <= today <= period_end`).
   - `estimated_total` must be `<= available` → otherwise 422.
2. **PR approval** (`PurchaseRequestController::approve`):
   - Re-check availability inside the transaction with the row lock (the
     budget may have shrunk between submission and approval). Failure returns
     the same 422 message; Admin rejects the PR or raises the budget first.

### Budget CRUD guards (`BudgetAllocationController`)

- `update`: `total_budget` cannot be set below `committed + spent`; changes to
  `total_budget` route through `BudgetService::adjust()`.
- `destroy` (archive): blocked with 422 while `committed > 0`
  ("release or complete its purchase requests first").

## Lifecycle integration

| Event | Current behavior | New behavior |
|---|---|---|
| PR approved | `committed += estimated_total`, no check | `BudgetService::commit()` (checked, locked) |
| PR rejected while pending | nothing (correct) | unchanged |
| PR archived after approval | committed leaks forever | `BudgetService::release($pr, 'PR archived')` in `destroy` |
| PO fully received (`ReceivingController`) | `spent += po.total; committed -= po.total` (drifts) | `BudgetService::spend($po)` |
| PO set to completed via `PurchaseOrderController::update` | same drift | same `spend()` path; idempotency prevents double-count with the receiving path |
| PO cancelled/archived before receiving | leak | commitment intentionally stays with the PR (still approved, re-convertible); released when the PR is archived/closed |
| Budget total edited | silent overwrite | `adjust()` txn + floor guard |

Partial conversion (PR status 16): commitment stays at the PR's estimated total
until the PR is fully consumed or archived — simple and explainable.

### Repair command

`php artisan budget:rebuild-totals` — recomputes `committed`/`spent` for every
budget from the ledger (budgets with no ledger rows are reset from live PR/PO
state: outstanding approved PRs → committed, completed POs → spent, written as
backfill txns). Used once to repair existing drifted demo data; kept as a
consistency proof.

## Visibility features

### 1. Budget Management page — utilization + drill-down

- Segmented utilization bar per budget card: spent / committed / available,
  with % utilized. Uses existing `ak-` design-system primitives and the
  existing health labels (HEALTHY / MODERATE / LOW / EXHAUSTED).
- Clicking a budget opens a **"Where did the money go?"** drill-down: ledger
  rows newest-first — type badge (COMMIT / RELEASE / SPEND / ADJUST), amount,
  linked PR/PO number, actor, timestamp, note.
- New endpoint: `GET /api/budget-allocations/{id}/transactions` (paginated,
  eager-loads PR/PO numbers and performer name). Route registered alongside
  the existing budget routes with the same RBAC middleware.

### 2. Threshold alerts (realtime push v2)

- When a `commit` or `spend` moves utilization
  (`(committed + spent) / total_budget`) across **80%** or **100%**,
  `BudgetService` fires the existing `RealtimeBroadcaster` so Admin/BM bells
  show e.g. "Budget 2026-Q3 — Cubao is 82% utilized."
- Crossing detection compares utilization before vs. after the transaction —
  fires once per crossing, not on every PR. Thresholds are class constants.

### 3. PO Recommendation tie-in

- The PO Recommendation payload gains the location's active budget summary
  (`total`, `available`).
- The page shows a read-only **budget-fit line**: "Recommended orders total
  ₱184,000 — fits within available ₱250,000" or "exceeds available by ₱34,000 —
  consider staggering." No enforcement there (recommendations are not yet
  purchases).

### 4. Period close-out summary

- Expired budgets show a variance strip (card/drill-down): allocated vs. spent
  vs. never-used, and final utilization %. Purely derived from the ledger — no
  new state, no cron.

### 5. Purchase Request page (frontend mirror)

- The existing `overBudget` flag now disables Submit with an explanatory
  message.
- No active budget for the location → Submit blocked with the existing amber
  "ask Admin to create a budget allocation" card.
- Backend remains the source of truth; the frontend explains earlier.

## Error handling

- `InsufficientBudgetException` → 422 with peso-formatted, period-labelled
  message (surfaced by the existing toast helper on the frontend).
- Missing/inactive/wrong-location budget on PR submit → 422 validation error.
- Budget shrink below `committed + spent`, archive with outstanding
  commitments → 422 with explanatory message.
- All ledger writes happen inside the same DB transaction as the state change
  they describe — a failed request leaves no ledger row.

## Testing

Repo convention: feature tests with `DatabaseTransactions` against
`airkingV5_test`.

- **Enforcement:** PR submit blocked over budget / without budget / with
  inactive or wrong-location budget; PR approve blocked when budget no longer
  covers it (funds consumed between submit and approve).
- **Commit:** approval writes `commit` txn and increments `committed`.
- **Release:** archiving an approved PR restores `available` exactly;
  rejecting a pending PR is a no-op; double-archive releases nothing twice.
- **Spend:** full receiving writes `spend` txn, moves committed→spent by the
  recorded commitment, auto-returns estimate surplus; repeated status-11
  update does not double-spend (idempotency).
- **Guards:** budget cannot shrink below `committed + spent`; cannot archive
  a budget with outstanding commitments.
- **Alerts:** crossing 80% fires broadcaster once; staying above 80% on the
  next commit does not re-fire.
- **Repair:** `budget:rebuild-totals` reproduces cached columns from ledger.

## Out of scope (YAGNI)

- Multi-budget splits per PR; approval workflows for budget changes;
  fiscal-year hierarchies; multi-currency (PHP peso only); soft-block /
  admin-override flows (hard block chosen).

## Panel narrative (why this feature deserves to exist)

"Budget allocation is the financial control layer of our procurement cycle.
Every purchase request must draw from an allocated budget; the system refuses
requests the budget cannot cover, including race conditions between
simultaneous approvals. Every peso movement — commitment on approval, spend on
receiving, release on cancellation, admin adjustments — is journaled in an
immutable transaction ledger, like a simplified double-entry system, so any
budget figure can be drilled down to the exact PR or PO that caused it.
Managers get realtime alerts at 80% utilization, the PO Recommendation engine
checks its suggestions against remaining budget, and expired periods produce a
variance summary that informs the next allocation."
