# Auditor Verification Layer — Design

**Date:** 2026-07-09
**Branch:** backupV3
**Status:** Approved (brainstorming session)
**Builds on:** docs/superpowers/specs/2026-07-08-budget-control-layer-design.md (implemented)

## Problem

The procurement chain has a maker (Inventory Analyst requests) and an approver
(Admin approves everything), but no independent checker. The admin both
controls budgets and approves the spending against them — a panelist can ask
"who watches the admin?" The auditor role exists but is globally read-only
(`AuditorReadOnly` middleware) and does nothing in the money flow. Meanwhile
`purchase_orders.verified_by` exists as a dormant column no workflow populates.

**Goal:** a blocking maker–checker–approver control:
- IA requests (PR / PO) → **Auditor verifies** → Admin approves.
- Budgets cannot fund PRs until an auditor verifies them.
- POs cannot be admin-approved or received until an auditor verifies them.
- The Admin assigns who may verify through the existing permission system
  (role grants or per-user overrides) — no new admin UI.

## Decisions (user-confirmed)

- **Blocking**, not advisory: unverified budget → PRs cannot draw from it;
  unverified PO → cannot be approved/authorized or received.
- **PO verification sits BEFORE admin approval**: IA creates PO → auditor
  verifies → admin approves → receiving.
- **Re-verify on total change**: editing `total_budget` resets the budget to
  unverified; label/notes/period edits do not.
- **Verify or reject-with-reason**: auditor may reject with a required reason;
  rejected records are unusable until fixed and re-verified.
- **Architecture:** verification columns on the two tables (no polymorphic
  table, no new status_lookup rows).

## Data model

Migration (apply via `--path=`, both dev and `airkingV5_test` DBs):

- `budget_allocations`: add
  - `verification_status` string(20) NOT NULL default `'pending'`
    (`pending` | `verified` | `rejected`)
  - `verified_by` unsignedBigInteger nullable FK → users (nullOnDelete)
  - `verified_at` timestamp nullable
  - `verification_note` string nullable
- `purchase_orders`: add `verification_status` (same default), `verified_at`,
  `verification_note`. (`verified_by` + `verifier()` relation already exist.)
- **Grandfathering backfill** in the same migration: POs already past Pending
  (`status_id` in 8, 10, 11, 12) get `verification_status = 'verified'`,
  `verification_note = 'Grandfathered — predates verification workflow'` so
  in-flight receiving is not retroactively blocked. All budgets start
  `pending` (dev DB currently has zero budget rows).

Model additions: `isVerified()` helpers on both models; `verifier()` relation
on `BudgetAllocation` (PO already has one).

## Permissions

Two new features in `PermissionSeeder` (shape: feature → action → roles):

```php
'budget_verification' => [
    'read'   => ['admin', 'auditor'],
    'verify' => ['auditor'],
],
'purchase_order_verification' => [
    'read'   => ['admin', 'auditor'],
    'verify' => ['auditor'],
],
```

- Default holder is **auditor only**. Admin intentionally does not hold
  `verify` — segregation of duties (approver ≠ checker). Admin can grant it to
  any role via the Role Permissions editor or to an individual via User
  Permission Overrides — this satisfies "admin assigns a user in the
  permission feature" with zero new admin UI.
- Routes use the existing `permission:` middleware.
- Frontend mirrors: nav-bundle/permission mirror gets the two new keys so
  buttons render by permission, not by role name.

## Auditor read-only carve-out

`AuditorReadOnly` middleware gains a narrow allowlist (regex on path +
method): `PATCH /api/budget-allocations/{id}/verify`, `.../reject`,
`PATCH /api/purchase-orders/{id}/verify`, `.../reject`. Everything else stays
blocked for auditors. The permission middleware still gates the routes — the
carve-out only lifts the blanket write block.

## Endpoints

| Endpoint | Middleware | Behavior |
|---|---|---|
| `PATCH /budget-allocations/{id}/verify` | `permission:budget_verification,verify` | → `verified`, sets verified_by/verified_at, optional `note` |
| `PATCH /budget-allocations/{id}/reject` | same | → `rejected`, requires `reason` (stored in verification_note) |
| `PATCH /purchase-orders/{id}/verify` | `permission:purchase_order_verification,verify` | same semantics on the PO |
| `PATCH /purchase-orders/{id}/reject` | same | → `rejected`, required reason; PO remains status Pending, flagged for the IA |

Shared rules:
- **Self-review guard:** verifier cannot verify/reject a record they created
  (`created_by == user_id` → 422 "You cannot verify a record you created").
- Double-verify → friendly no-op success. Verify/reject allowed from either
  `pending` or `rejected` state.
- **Rejecting an already-approved PO is blocked** (422 — the checker's window
  is before approval; post-approval concerns go through the audit trail).
- Verification state changes are recorded by the existing `Auditable` trait
  (audit trail), NOT as budget_transactions rows (the ledger stays a pure
  money journal).
- Each verify/reject fires the existing realtime broadcasts
  (`budgetChanged` / `purchaseOrderChanged`) with type `verification_changed`.

## Blocking gates

| Gate | Rule | Error |
|---|---|---|
| PR submit (`PurchaseRequestController::store`) | budget must be `verified` (added to the existing active/location/available checks) | 422 "This budget has not been verified by an auditor yet" (rejected: "...was rejected by the auditor: {reason}") |
| Restock `create-pr` + reco payload | `BudgetService::resolveActiveBudget()` only resolves **verified** budgets — restock flow and budget-fit line follow automatically | 422 (existing no-active-budget message, updated wording) |
| PR approve | `BudgetService::commit()` throws a new `App\Exceptions\UnverifiedBudgetException` unless the budget is verified — re-checked under the existing row lock (defense in depth); `PurchaseRequestController::approve` catches it → 422, same pattern as `InsufficientBudgetException` | 422 "This budget has not been verified by an auditor yet" |
| PO approve/authorize (`PurchaseOrderController::update` transitioning to status 8 or 10) | blocked unless `verification_status = 'verified'` | 422 "This purchase order must be verified by an auditor before approval" |
| Receiving — `ReceivingController::store` AND the barcode-scan flow (`InventoryScanTransactionController`) | receiving against an unverified PO is blocked (closes today's gap where receiving never checks approval state) | 422 same message |

## Lifecycle

- **Re-verify on total change:** `BudgetService::adjustTotal()` — when the
  total actually changes — resets `verification_status = 'pending'` and clears
  `verified_by`/`verified_at`, inside the same locked transaction that writes
  the adjustment ledger row. Committed/spent funds are untouched (they were
  verified when committed); only NEW commitments are blocked until re-verify.
- Rejected budget: same gates as pending; admin fixes (an adjustTotal already
  resets to pending) and the auditor re-verifies.
- Budget update() of non-total fields and archive/restore do not touch
  verification state.

## UI

All permission-gated via the existing frontend permission mirror; `ak-`
design system; toast/confirm helpers.

1. **Badges** on Budget Management cards and PO list/detail:
   `AWAITING VERIFICATION` (amber) / `VERIFIED ✓ {auditor}, {date}` (green) /
   `REJECTED — {reason}` (red).
2. **Verify / Reject buttons** on Budget Management and the PO page, visible
   only with the matching `*_verification.verify` permission. Reject uses the
   confirm-dialog pattern with a required reason textarea.
3. **Auditor dashboard**: two "Awaiting verification" stat tiles (count of
   pending budgets / pending POs) linking to the pages.
4. **PR page**: budget card shows the verification badge; unverified budget
   blocks submit with "This budget is awaiting auditor verification"
   (mirror of the backend 422; backend remains source of truth).
5. **PO Recommendation page**: banner copy updated to "no verified active
   budget…" (logic comes free from resolveActiveBudget).

### Details, labels, and exports (record-level "Verified by" everywhere)

6. **Kill the fake "Verified By" input.** The PO create form currently has a
   manual VERIFIED BY dropdown (PurchaseOrderPage.jsx ~line 720) where the
   creator picks any user for the printed document — this would let anyone
   fake verification. Remove the dropdown (and its `verified_by` field from
   the create payload); `verified_by` is populated ONLY by the auditor's
   verify action. Backend: drop `verified_by` from the store/update/
   convertStore validation + writes in PurchaseOrderController and
   PurchaseRequestController::convertStore so it cannot be mass-assigned.
7. **PO detail view**: add a "Verified by {first last} on {date}" row
   alongside Created By / Approved By (— em dash when awaiting; the rejection
   reason shown when rejected).
8. **Printed/exported PO document + Excel** (frontend `utils/poExport.js` and
   the in-page document preview): the existing "Verified By:" signature line
   now renders the REAL verifier name + verified date from the record
   (blank line when not yet verified — it is a signature slot, not a claim).
9. **Backend PO CSV export** (`PurchaseOrderController::export`): columns
   become `... 'Created By', 'Verified By', 'Verified At', 'Verification
   Status', 'Approved By', 'Received By' ...` — ordered to read as the actual
   chain (maker → checker → approver → receiver). Values from the
   `verifier` relation (add it to the export eager-load).
10. **PR CSV export** (`PurchaseRequestController::export`): the existing
    'Budget Period' column gains a sibling 'Budget Verification' column
    (Verified / Awaiting verification / Rejected) so exported PRs show the
    state of the budget they drew from.
11. **Budget drill-down / cards**: the verified badge shows auditor name +
    date (already covered in item 1); the ledger modal header shows the
    budget's verification line under the period label.

## Error handling

- All gate failures are 422 with human messages (same envelope as the budget
  layer). Missing records 404. Permission failures 403 (existing middleware).
- Verify/reject endpoints validate: reject requires `reason` string; verify
  accepts optional `note`.

## Testing

Feature tests (`DatabaseTransactions` vs `airkingV5_test`), extending the
Budget suite conventions. `BudgetTestCase::makeBudget` defaults to
`verification_status => 'verified'` so the existing 48 budget tests pass
unchanged; new tests override to `pending`/`rejected` explicitly.

- **Permissions:** auditor verifies/rejects both types (200); IA/WP 403;
  admin without grant 403; admin (or any user) WITH a per-user override
  succeeds — proves the assign-via-permission requirement end to end.
- **Carve-out:** auditor PATCH verify/reject passes `AuditorReadOnly`;
  auditor PATCH on any other endpoint still 403s.
- **Gates:** PR submit blocked on pending + rejected budgets; restock
  create-pr blocked (resolveActiveBudget skips unverified); commit() throws
  on unverified under lock; PO approve (status 8/10) blocked; receiving
  blocked on both paths for unverified POs.
- **Lifecycle:** adjustTotal (total change) resets verification; non-total
  update does not; self-review guard 422; reject requires reason;
  reject-after-approval 422; double-verify no-op; migration grandfathering
  (existing 8/10/11/12 POs verified, pending POs not).
- **Realtime:** verify fires the broadcast once (Event::fake).
- **Labels/exports:** `verified_by` is no longer mass-assignable through PO
  store/update/convertStore (submitting it is ignored); PO CSV export rows
  contain Verified By / Verified At / Verification Status populated after an
  auditor verifies (and empty before); PR CSV export contains the Budget
  Verification column.

## Out of scope (YAGNI)

- Multi-level verification chains; verification SLAs/reminders/escalation;
  verifying PRs (admin approval covers them — the auditor checks the money
  documents: budgets and POs); notification emails.

## Panel narrative

"Procurement is maker–checker–approver: the Inventory Analyst requests, an
independent Auditor verifies, and only then can the Admin approve. Budgets
must be verified before a single peso can be committed against them, and any
change to a budget's total voids its verification. Purchase orders cannot be
approved or received until verified, and no one can verify a document they
created themselves. Verification rights are assigned through the permission
system, so the Admin controls WHO checks — but cannot skip the check."
