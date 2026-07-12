# Warehouse-Only Procurement: Supplier-Scoped PRs with Per-Warehouse Lines — Design

**Date:** 2026-07-10
**Branch:** backupV3
**Status:** Approved (brainstorming session)
**Builds on:** budget control layer (2026-07-08) + auditor verification layer (2026-07-09), both implemented.

## Problem

Panelist/client requirements:
1. **POs are received at warehouses only.** Suppliers deliver to warehouses; showrooms
   and branch offices are replenished by stock transfer from a warehouse
   (Supplier → Warehouse → Transfer → Showroom). Today a PR/PO can target any
   location type.
2. **One PR per supplier.** A PR must belong to exactly one supplier; products
   from different suppliers require separate PRs. Today PRs have no supplier.
3. **One PR, many warehouses.** Within a single PR, each product line can target
   a different destination warehouse (Laptop → WH-A, Printer → WH-B) while the
   supplier stays the same.
4. **PO Recommendations must say WHERE.** The reco engine should recommend not
   just what/how much to order but which warehouse needs it. Today it computes
   org-wide numbers pinned to the first warehouse.

## Decisions (user-confirmed)

- **Split per warehouse:** one PR document (one supplier); at approval each
  warehouse's line-subtotals commit to THAT warehouse's budget; conversion
  produces one PO per warehouse.
- **Auto-resolve budgets:** no manual budget pick. Approval resolves each
  destination warehouse's active VERIFIED budget automatically. If ANY
  warehouse lacks a verified budget with sufficient funds, the whole approval
  is blocked (all-or-nothing), with the failing warehouse named.
- **Auto-split conversion:** converting an approved PR creates one PO per
  destination warehouse in a single action.
- **Supplier-filtered products:** picking the supplier on the PR form filters
  the product list to that supplier's mapped products (existing
  products-by-supplier endpoint).
- **Per-warehouse analytics:** the reco engine runs per warehouse (own stock,
  own outflows including transfers-out, own on-order).
- **Architecture:** line-level warehouse (Approach A) — `supplier_id` on
  `purchase_requests`, `location_id` on `purchase_request_details`. No sibling
  PRs, no middle table.

## Schema

One migration (apply via `--path=` to dev + `airkingV5_test`):

- `purchase_requests`: add `supplier_id` unsignedBigInteger nullable FK →
  suppliers (nullOnDelete). Required by validation for NEW PRs; nullable so
  legacy rows survive.
- `purchase_request_details`: add `location_id` unsignedBigInteger nullable FK
  → locations (nullOnDelete) — the line's destination warehouse. Required by
  validation for NEW PRs.
- Legacy columns `purchase_requests.location_id` and `budget_id` remain
  (nullable, no longer written by the new flow). No data migration.

Model updates: `PurchaseRequest::supplier()` BelongsTo;
`PurchaseRequestDetail::location()` BelongsTo; `location_id` in detail
fillable; `supplier_id` in PR fillable.

## PR creation

### Backend (`PurchaseRequestController::store`)

Validation:
- `supplier_id` required|exists.
- `details.*.location_id` required|exists, and the location's
  `location_type` must be `'warehouse'` → else 422 "Purchase orders are
  received at warehouses only — showrooms are supplied by stock transfer."
- Product–supplier integrity: each line's product must be mapped to the chosen
  supplier via the existing supplier mappings. Escape hatch: a product with NO
  supplier mappings at all is allowed (keeps unmapped data usable); a product
  mapped only to OTHER suppliers is rejected (422 naming the product).
- `location_id`/`budget_id` on the PR header are no longer accepted as input.

Per-warehouse advisory budget check (submission-time, unlocked):
- Group lines by `location_id`; for each warehouse group, an active
  (period covers today) VERIFIED budget must exist with
  `available >= group subtotal` → else 422 naming the warehouse
  ("Warehouse B has no verified active budget" / "Estimated total ₱X for
  Warehouse B exceeds its available budget ₱Y").
- The PR row stores `supplier_id`, `estimated_total`; header `location_id`
  and `budget_id` stay NULL for new PRs.

### Frontend (`PurchaseRequestPage`)

- Supplier select comes first; the product picker loads that supplier's
  products (`products-by-supplier` endpoint). Changing supplier clears lines.
- Each line has a Warehouse dropdown listing warehouse-type locations only.
- The single budget card is replaced by per-warehouse budget cards — one per
  distinct warehouse used in the lines — each showing verification badge and
  available vs. that warehouse's running subtotal. Submit disabled with
  per-warehouse messages when any group is over budget / unverified / has no
  budget.
- PR detail/list and CSV export show Supplier and a per-line "Requested For"
  warehouse column (replacing the header-level Budget Period/Budget
  Verification columns with per-line warehouse info + supplier column).

## Approval — per-warehouse commits

`PurchaseRequestController::approve` (new-shape PRs):
- Group lines by warehouse. In ONE DB transaction: for each group,
  auto-resolve the warehouse's active VERIFIED budget
  (`BudgetService::resolveActiveBudget`, which already filters verified) and
  commit the group subtotal against it (row-locked). Any failure
  (no budget / unverified / insufficient) throws → whole transaction rolls
  back, PR stays pending, 422 names the warehouse.
- Ledger: one `commit` row per (budget, PR) — each warehouse's ledger shows
  exactly its share.

### BudgetService changes

- `commit()` signature becomes group-aware: `commit(PurchaseRequest $pr,
  BudgetAllocation $budget, float $amount, ?int $userId)`. Idempotency guard
  keyed per `(budget_id, pr_id)` (was per `pr_id`). Availability + verified
  checks under the row lock as today (UnverifiedBudgetException /
  InsufficientBudgetException unchanged).
- `releaseOutstanding(PurchaseRequest $pr, ...)` iterates every DISTINCT
  `budget_id` in the PR's ledger rows, releasing each budget's outstanding
  (Σcommit − Σrelease per budget+PR). Archiving a multi-warehouse PR returns
  each warehouse's share to its own budget.
- `spend(PurchaseOrder $po, ...)` resolves the budget as: the budget holding
  this PR's commit whose `location_id = po->location_id` (from the ledger).
  Consumes actual against that budget's outstanding for the PR; estimate
  surplus for the group is auto-returned when the completed PO's ordered
  quantities cover the group's full PR quantities (computed live from PO
  details vs. that warehouse's PR lines). Idempotency per po_id unchanged.
- Legacy path preserved: a PR whose details have no `location_id` uses the old
  single-budget behavior (`pr->budget_id`), so pre-existing PRs approve,
  archive, and settle exactly as before.

## Conversion — PO auto-split

- `convert` (GET prefill): returns the PR's lines grouped by warehouse, with
  the supplier fixed from the PR (no supplier picker on conversion anymore for
  new-shape PRs; legacy PRs keep the old prefill).
- `convertStore` (POST): accepts per-line quantities/prices (editable), then
  in one transaction creates ONE PurchaseOrder per warehouse group — each PO
  carrying `location_id` = that warehouse, `supplier_id` from the PR, its own
  PO number, and only that group's lines. PO status Pending (7), verification
  pending — the auditor verification and approval flows then run per PO,
  unchanged.
- PR marked converted (existing `converted_to_po_id` set to the first created
  PO for backward compatibility; the real relation is POs-by-`pr_id`). Status
  → Partially Approved (16) if any group was under-ordered, else stays
  Approved with converted flag, matching today's semantics.
- Re-conversion blocked once converted (as today).

## Warehouse-only POs everywhere

- `PurchaseOrderController::store` and `update`: `location_id` must be a
  warehouse-type location → 422 otherwise. Applies to direct/manual PO
  creation too, so the rule holds outside the PR flow.

## PO Recommendation — per-warehouse analytics

`RestockAnalyticsService` runs per warehouse instead of org-wide:
- **Stock**: the warehouse's own `quantity_on_hand`.
- **Demand** (per product, per warehouse): outflows FROM that warehouse —
  its sales + its issuances + its TRANSFERS OUT. A showroom restocking from
  WH-A appears as WH-A demand, which matches the operating model (showrooms
  pull from warehouses, so warehouse outflow is the purchasing signal).
- **On-order**: open POs destined for that warehouse.
- Output: one recommendation row per (product, warehouse) needing stock, each
  with its own quantity/priority/days-of-cover; `restock_recommendations.location_id`
  (column already exists) stores the warehouse.

Reco page:
- Warehouse column on recommendation rows.
- Budget-fit line becomes per-warehouse for the selected rows ("WH-A: ₱84,000
  — fits ₱120,000; WH-B: exceeds by ₱6,000"), using each warehouse's active
  verified budget.
- Create-PR from selection: rows group by each product's mapped supplier →
  one draft PR per supplier, each line carrying its recommended warehouse
  (the Section "PR creation" shape). Products with no supplier mapping are
  excluded from the draft with a toast naming them.
- Backend `createPr` accepts/produces the new shape (supplier_id + per-line
  location_id) and enforces the same warehouse/budget advisory checks.

## Error handling

- All gate failures 422 with peso-formatted, warehouse-named messages
  (existing envelope). Warehouse-type violations use the "received at
  warehouses only" message. Product–supplier mismatch names the product and
  supplier.
- Approval failures leave the PR pending (transaction rollback), as the
  budget layer already guarantees.
- Realtime: PR events currently broadcast on `pr->location_id`, which is NULL
  for new-shape PRs. Broadcast once per distinct line warehouse instead
  (each destination warehouse's channel + org channel get the signal).

## Testing

Extends the Budget + Restock suites (`DatabaseTransactions` vs
`airkingV5_test`). Test-base note: `makePr` gains supplier + per-line
warehouse variants; existing tests keep passing via the legacy path or
updated fixtures — never by weakening gates.

- **Store:** rejects showroom line; rejects product mapped to a different
  supplier; allows unmapped product; requires supplier; accepts
  multi-warehouse lines; per-warehouse advisory failures name the warehouse.
- **Approve:** two-warehouse PR commits exact amounts to two budgets (ledger
  rows per budget); one warehouse failing rolls back both; idempotent per
  (budget, PR).
- **Archive:** releases each budget's share exactly.
- **Convert:** auto-split creates N POs with correct location/supplier/lines;
  re-convert blocked.
- **Settle:** each PO's completion spends against its own warehouse's budget;
  surplus returns per group; partial group under-order keeps remaining
  commitment.
- **Legacy:** old-shape PR (no line locations) approves/converts/settles via
  the old path unchanged.
- **Engine:** two warehouses with different stock/demand yield distinct
  per-warehouse rows; transfers-out counted as source-warehouse demand;
  on-order scoped per warehouse.
- **Warehouse-only:** direct PO store/update to a showroom rejected.

## Out of scope (YAGNI)

- Supplier price lists per warehouse; split shipments within a line;
  cross-warehouse rebalancing suggestions; multi-supplier PRs; changing the
  transfer-request module (it already covers Warehouse → Showroom).

## Panel narrative

"Procurement is warehouse-centric by design: every purchase order lands at a
warehouse, and satellite locations replenish through controlled stock
transfers. A purchase request belongs to exactly one supplier, but its lines
can stock multiple warehouses — and the system splits the money and the
paperwork accordingly: each warehouse's budget is committed separately under
its own auditor-verified allocation, and conversion produces one purchase
order per warehouse so receiving, verification, and spending settle
independently. The recommendation engine completes the loop by computing each
warehouse's own demand — including what it ships out to showrooms — so it
recommends not just what and how much to buy, but where it belongs."
