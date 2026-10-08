# Move budget encumbrance from PR approval to PO approval

**Date:** 2026-07-16
**Branch:** backupV3
**Status:** Approved design

## Problem

Today the budget is *encumbered* (the `committed` column is increased and funds
reserved) at **purchase-request approval** — `PurchaseRequestController::approve()`
calls `BudgetService::commitGroup()`. The entire budget ledger is **`pr_id`-anchored**:
`outstanding` is computed per PR, and spend/release at PO-receiving settle against the
PR's commitment.

But a PR is only a *request*. An order becomes official when the **PO** is submitted
and approved. The reservation therefore belongs to the PO, not the PR. Three facts
make PR-anchoring wrong:

- A single PR can spawn **multiple POs** (partial conversion) — one `pr_id` commitment
  cannot represent several independent orders.
- **Direct POs** (manual warehouse POs) have **no PR** — today they never encumber,
  only spend at receiving.
- Money is reserved before an order is official, so an approved-but-unconverted PR
  ties up budget that no supplier order backs.

## Decisions (from brainstorming)

1. **Encumber on the PO `Pending(7) → Approved(8)` transition.** Drafts/pending POs
   reserve nothing.
2. **PR approval validates but does not reserve.** It still blocks approving a PR whose
   per-warehouse subtotal exceeds *currently available* budget, but writes no ledger
   rows.
3. **All POs reserve** at approval — PR-derived and direct alike (PO-anchored).
4. **Migrate in-flight data**: a one-time command releases existing PR-anchored holds
   and re-reserves against POs already at/after Approved.
5. **Accepted consequence:** approved PRs are non-binding. Two approved PRs can jointly
   exceed the budget; the real block happens at PO approval (first PO to be approved
   commits; a later breaching PO is rejected 422).

## Current state (verified in code)

PO status_lookup (category `purchase_order`): `7 Pending, 8 Approved, 9 Rejected,
10 Authorized, 11 Completed, 12 Partially Received`.

- `PurchaseRequestController::approve()` (~line 442): sets status 14 and calls
  `commitGroup()` per warehouse group (or legacy `commit()`), throwing
  `UnverifiedBudgetException` / `InsufficientBudgetException`.
- `PurchaseOrderController::update()` (~line 202): status transitions into
  `[8,10,11,12]` are gated on `verification_status === 'verified'` (stays). When
  status transitions into `11` (Completed) and the PO has a `pr_id`, it calls
  `BudgetService::spend()`. There is no dedicated PO `approve()` — approval is a
  status update to 8.
- `BudgetService` (`back-end/app/Services/BudgetService.php`):
  - `commit(pr)` / `commitGroup(pr, budget, amount)` — row-locked, idempotent per
    `(budget_id, pr_id)`, requires verified budget + enough available, writes a
    `COMMIT` txn with `pr_id` set / `po_id` null, bumps `committed`.
  - `spend(po)` — returns early if `!$po->purchaseRequest`; groups PO details by
    location; settles each group against the budget resolved from the **PR**, writes
    `SPEND` + `RELEASE`(consume) txns keyed by `pr_id`+`po_id`, releases estimate
    surplus, decrements `committed`, bumps `spent`. Idempotent per `(po_id[,budget_id])`.
  - `releaseOutstanding(pr, note)` — releases Σcommit − Σrelease for a PR (used on PR
    archive).
  - `outstanding(budgetId, prId)` — `pr_id`-keyed.
  - `resolveActiveBudget(locationId)` — returns the verified, in-period budget for a
    location.
- Other `spend()` call sites (must keep working, unchanged callers):
  `ReceivingController`, `InventoryScanTransactionController::syncPoReceivingStatus`.
- `BudgetTransaction` already has both `pr_id` and `po_id` columns.

## Design

Re-anchor the ledger from PR to PO. Commitment/spend/release become **`po_id`-anchored**;
`pr_id` is still recorded on rows for reporting/traceability but is no longer the
idempotency or `outstanding` key.

### BudgetService changes

- **New `commitPo(PurchaseOrder $po, ?int $userId)`** — the reservation entry point,
  called at PO approval:
  - Load `$po->details`; group by `location_id ?? $po->location_id`.
  - For each group: `resolveActiveBudget($locationId)`; if none →
    `UnverifiedBudgetException($warehouseName, 'pending')`.
  - Row-lock the budget. Idempotency: if a `COMMIT` txn already exists for
    `(budget_id, po_id)`, skip that group (retry / re-approval safe).
  - Guard `verification_status === 'verified'` (defensive; the controller gate already
    requires a verified PO, but budgets are gated here).
  - `available = total_budget − committed − spent`; if `groupAmount > available` →
    `InsufficientBudgetException`.
  - Write `COMMIT` txn (`pr_id` = `$po->pr_id` if any, `po_id` = `$po->po_id`), bump
    `committed`, `maybeAlert`.
  - `groupAmount` = Σ `subtotal` of the group's PO lines.
- **`spend(po)`** — drop the `return` on missing PR; settle against the PO's own
  commitment. Where it currently resolves the budget/`outstanding` via `pr_id`, use
  `resolveActiveBudget($locationId)` and `outstanding(budgetId, po_id)`. Surplus =
  reserved-for-this-PO − actual spent, released when the group/PO is fully received.
  Keep idempotency per `(po_id, budget_id)`.
- **`releaseCommitment(PurchaseOrder $po, string $note, ?int $userId)`** — releases the
  PO's outstanding commitment across its committed budgets (mirror of the old
  `releaseOutstanding`, keyed on `po_id`). Called on PO reject/cancel.
- **`outstanding(int $budgetId, int $poId)`** — re-keyed to `po_id`.
- **`releaseOutstanding(pr, …)` and `commit`/`commitGroup(pr, …)`** — removed (no
  remaining callers after the controller changes). If any legacy caller remains, make
  it a no-op with a `Log::warning`.

### Controller changes

- **`PurchaseRequestController::approve()`** — remove the `commit`/`commitGroup` calls.
  Replace with a **non-reserving validation**: for each warehouse group, resolve the
  verified active budget; if none → 422 (same message as today); if the group subtotal
  exceeds `available` at that instant → 422 `InsufficientBudgetException` message.
  Still set status 14 + approver + date. No ledger rows written. Keep the existing
  "no budget attached (created before budget enforcement)" guard.
- **`PurchaseOrderController::update()`** — inside the header-update block:
  - Detect a transition **into a committed state** — from a non-committed status (7
    Pending, or none) into any of `8` Approved / `10` Authorized — and call
    `BudgetService::commitPo($po->fresh()->load('details'), userId)` inside the existing
    DB transaction. On `Insufficient`/`Unverified` exception → roll back → 422 (PO stays
    Pending). Because `commitPo` is idempotent per `(budget_id, po_id)`, this is safe to
    call on any advancing transition.
  - **Close the direct-jump gap:** a PO can also transition straight into `11` Completed
    or `12` Partially Received (the existing gate permits it) without passing 8/10. Since
    those transitions trigger `spend()`, call `commitPo()` **immediately before** `spend()`
    when the PO has no `COMMIT` txn yet — spend can only settle against an existing
    commitment. `commitPo` being idempotent means the normal `→8` path is unaffected.
  - Detect a transition **into status 9 (Rejected)** (or the cancel path) on a PO that
    had been committed and call `BudgetService::releaseCommitment($po, 'PO rejected', userId)`.
  - Keep the existing verification gate for `[8,10,11,12]`.
  - This path is PR-agnostic, so **direct POs reserve too**.
  - The PO `reject()` method (~line 342) that sets status to rejected must likewise
    release any commitment.

### Migration command

`php artisan budget:reanchor-commitments` (one-time, idempotent):
1. For every budget, release all outstanding **`pr_id`-anchored** `COMMIT` holds that
   have no corresponding PO commitment (write `RELEASE` rows, decrement `committed`).
2. For every PO already at status ≥ Approved (8, 10, 11, 12) that lacks a `po_id`
   `COMMIT` txn, run `commitPo()` (skipping groups already spent).
3. Advise running `php artisan budget:rebuild-totals` afterwards to reconcile cached
   `committed`/`spent`.

For dev/demo the simpler path is: run the code change, then `budget:reanchor-commitments`
+ `budget:rebuild-totals` on the dev DB.

## Error handling

- Over-budget at PO approval → `InsufficientBudgetException` → 422; PO transaction rolls
  back, PO stays Pending.
- Unverified budget at PO approval → `UnverifiedBudgetException` → 422.
- All cached-column writes remain **inside `BudgetService`** (sole-mutator invariant),
  row-locked and idempotent.

## Testing

Backend feature tests (extend the Budget suite):
- PR approval writes **no** commit rows; over-budget PR approval still 422 (validate).
- PO approval (`7→8`) commits: single-warehouse and multi-warehouse groups.
- Insufficient budget at PO approval → 422 and **no** partial ledger writes (rollback).
- Direct PO (no PR) reserves at approval and spends at receiving.
- Two POs converted from one PR each reserve independently (no `pr_id` collision).
- Reject/cancel after approval releases the PO's commitment back to available.
- Receiving settles the PO's own commitment; surplus returned when actual < reserved.
- Idempotency: re-approving / re-saving an already-Approved PO does not double-commit.
- Direct-jump gap: a PO taken straight from Pending into 11 (Completed) commits then
  spends (no double-commit, budget correctly reserved and settled).
- `budget:reanchor-commitments` on seeded data moves holds from PR to PO with
  reconciled totals.

Update `BudgetTestCase` helpers as needed (PO approval now the commit trigger).

## Scope guardrails (YAGNI)

- No change to how PR *submission* checks budget (advisory block stays as-is).
- No new PO "approve" endpoint — approval remains a status update to 8.
- No UI changes required for correctness; Budget Management utilization bars/ledger keep
  working because they read the same cached columns and ledger. (A later polish could
  relabel ledger rows "PO … reserved" vs "PR …", but that is out of scope.)
- Keep `pr_id` on ledger rows for traceability; only the *keying* moves to `po_id`.

## Files touched

- `back-end/app/Services/BudgetService.php` — `commitPo`, re-keyed `spend`/`outstanding`,
  `releaseCommitment`; remove PR-anchored `commit`/`commitGroup`/`releaseOutstanding`.
- `back-end/app/Http/Controllers/Api/PurchaseRequestController.php` — `approve()` becomes
  validate-not-reserve; PR-archive release becomes a no-op.
- `back-end/app/Http/Controllers/Api/PurchaseOrderController.php` — commit on `→8`,
  release on `→9`/cancel, in `update()` and `reject()`.
- `back-end/app/Console/Commands/ReanchorBudgetCommitments.php` — new migration command.
- Budget feature tests under `back-end/tests/Feature/Budget`.
