# Merge a New PR into an Existing Pending PR (same supplier) — Design

**Date:** 2026-07-10
**Branch:** backupV3
**Status:** Approved (brainstorming session)
**Scope:** `PurchaseRequestController::store()` behavior + a one-line frontend toast. No schema change.
**Builds on:** warehouse-only procurement (Plan A) — PRs are supplier-scoped with per-line warehouses.

## Problem

Submitting several PRs for the same supplier while the first is still pending creates multiple PR ids for what is really one open order to that supplier. The user wants a new submission to **add to the existing pending PR** for that supplier instead of minting a new one.

## Decisions (user-confirmed)

- **Merge target:** an existing PR that is `status_id = 13` (Pending), same `requested_by` (the current user), same `supplier_id`, not soft-deleted, `converted_to_po_id` NULL. Only the submitter's own pending same-supplier PR merges — never another user's, never a legacy (null-supplier) PR.
- **Line merge:** for each submitted line, if the target PR already has a line with the **same `product_id` + same `location_id`**, add the new quantity to it (keep that line's existing `estimated_price`; recompute `subtotal = new combined quantity × existing estimated_price`). Otherwise append the submitted line as a new detail.
- **Budget advisory on the combined total:** the per-warehouse advisory check runs on **existing target lines + new lines** grouped by warehouse, so a merged PR cannot grow past what each warehouse's verified budget can later approve.
- **needed_by_date:** unchanged on merge (the PR is already scheduled).
- **Message:** the response message states which happened (merged vs created); the form toast surfaces it.

## Backend flow (`store()`)

After the existing validation and the warehouse-only + product–supplier integrity checks, and BEFORE the per-warehouse budget advisory:

1. Resolve the **merge target**:
   ```
   $target = PurchaseRequest::where('status_id', 13)
       ->where('requested_by', $request->user()->user_id)
       ->where('supplier_id', $supplierId)
       ->whereNull('converted_to_po_id')
       ->with('details')
       ->first();   // soft-deleted excluded by the model's SoftDeletes global scope
   ```
2. Build the **effective per-warehouse subtotals** for the advisory = the target's existing detail subtotals (if a target exists) **plus** the new submission's subtotals, grouped by `location_id`. Run the existing advisory (active verified budget exists + `available >= combined subtotal`) against these combined figures. Same 422 messages, now reflecting the merged size.
3. In one `DB::transaction`:
   - **If `$target`:** for each new line, find a matching existing detail by `(product_id, location_id)`. If found, `quantity += new quantity` and `subtotal = quantity × existing estimated_price`, save. Else create a new `PurchaseRequestDetail` on `$target`. Then set `$target->estimated_total = Σ its detail subtotals` and save. `$pr = $target`.
   - **Else:** create the PR + details exactly as today (the current new-shape create).
4. Broadcast per distinct destination warehouse (existing behavior).
5. Return `$this->success($pr->load(WITH_RELATIONS), $target ? "Added to your pending request {$target->pr_number}" : 'Purchase request submitted', $target ? 200 : 201)`.

Note: the merge and create paths share the same up-front checks (warehouse-only, product–supplier integrity, combined budget advisory). Only the write step differs.

## Frontend

`PurchaseRequestPage.jsx` `handleSubmitPR`: after `purchaseRequestsAPI.create(payload)`, surface the backend `res?.message` in the success toast (falling back to the current text) so the user sees "Added to your pending request PR-…" when a merge happened. Reset the form/blocks as today. No other change.

## Error handling

- Combined budget advisory failures → 422 naming the warehouse (existing envelope), computed on merged totals.
- All other validation unchanged.
- Concurrency: two near-simultaneous submits could both miss the target and create two PRs. Acceptable (rare, benign — the second simply becomes the new pending target). No locking added.

## Testing

Feature tests (`DatabaseTransactions` vs `airkingV5_test`), extending the warehouse-PR suite. `BudgetTestCase` helpers + a supplier/product/warehouse-budget fixture as in `WarehousePrTest`.

- **Merge:** submitting a 2nd PR for the same supplier while the first is pending adds to the first (no new PR row); `estimated_total` updated; response message mentions the existing PR number.
- **Sum lines:** a submitted (product, warehouse) already on the target sums quantities into one line (line count unchanged for that pair); a new (product, warehouse) appends a line.
- **Scope isolation:** a pending PR for a DIFFERENT supplier is not merged into (new PR created); a pending PR by a DIFFERENT requester is not merged into; an APPROVED same-supplier PR is not a target (new PR created).
- **Combined advisory:** first PR uses most of warehouse A's budget; a second submission whose A-lines push the *combined* A total over available is rejected 422 naming warehouse A (even though the second submission alone would fit).

## Out of scope (YAGNI)

- Merging across users; merging into approved/converted PRs; changing `needed_by_date`; a UI "merge or create new?" prompt (merge is automatic); locking for the rare double-submit race.

## Narrative

"One open request per supplier: while a purchase request is still awaiting approval, adding more items for that supplier folds them into the same request — same PR number, combined quantities, one budget check across the whole thing — instead of scattering the supplier's order across several pending PRs."
