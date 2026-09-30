# Remove auditor verification from budgets

**Date:** 2026-09-30
**Status:** Approved for planning

## Problem

Creating a budget takes two people. An admin allocates it, then an auditor must
verify it before any purchase request can draw from it. The business no longer
wants that second step: an admin's budget should fund requests the moment it
exists.

Purchase order verification stays exactly as it is. This spec removes the budget
half of the auditor's verification workflow and nothing else.

## Behaviour after the change

- An admin creates a budget and it immediately funds PRs and POs, provided its
  period covers today and it has room.
- Raising or lowering a budget's total no longer knocks it back to pending.
- The auditor keeps PO verification. They lose the budget verify/reject buttons,
  the "Budgets awaiting verification" KPI and the budget verification alert.
- Budgets currently sitting at `pending` or `rejected` become spendable. This is
  accepted: nothing is archived or deleted silently, and an admin can archive
  any budget they no longer want.

## Scope decisions

| Decision | Choice |
| --- | --- |
| Removal depth | Full removal — columns, endpoints, permission, UI and exception all go |
| Purchase order verification | Untouched |
| Already-rejected budgets | Left active; admin archives them by hand if desired |

## Backend

### Migration

`drop_verification_from_budget_allocations`:

- Drop the `verified_by` foreign key, then the `verification_status`,
  `verified_by`, `verified_at` and `verification_note` columns from
  `budget_allocations`. `purchase_orders` is not touched.
- Delete the `budget_verification.verify` permission row and every role and
  per-user grant pointing at it. Without this the grant survives in live
  databases and keeps appearing in the permission matrix.
- `down()` restores the four columns with `verification_status` defaulting to
  `'verified'`, not `'pending'`. A rollback that defaulted to pending would
  freeze every existing budget.

CI imports `database/ci-schema.sql` and then runs `migrate`
(`.github/workflows/ci.yml:146,163`), so this migration runs after the dump and
the dump needs no regeneration. `SchemaModelContractTest` only asserts that a
model's fillables exist as columns, so shrinking the model is safe.

### The error-message gap

`BudgetService::resolveActiveBudget` filters on `verification_status = 'verified'`,
which collapses two different failures into one exception: "there is no budget
for this warehouse" and "the budget is not verified" both raise
`UnverifiedBudgetException`. Once verification is gone, that message — "has not
been verified by an auditor yet" — is simply wrong.

Replace the class with `NoActiveBudgetException`, message:

> No active budget for <warehouse> covering today. Create one before approving.

Raised at `BudgetService.php:74` and `PurchaseRequestController.php:572`. The
direct-`budget_id` branch at `PurchaseRequestController.php:582` becomes a plain
budget-not-found check. Both catch sites
(`PurchaseRequestController.php:596`, `PurchaseOrderController.php:395`) switch to
the new class, and `UnverifiedBudgetException` is deleted.

### Strip list

- `BudgetService`: the `verified` filter (`:41`), the re-check inside
  `commitPoGroup` (`:86`), and the reset-to-pending plus `verification_pending`
  broadcast in `adjustTotal` (`:321-330`).
- `BudgetAllocation`: the four fillables, the `verified_at` cast, `isVerified()`
  and the `verifier()` relation.
- `BudgetAllocationController`: `verify()`, `reject()`, the `verification_status`
  query filter (`:34`), the broadcast in `store()` (`:112`), the two verification
  fields in `available()` (`:145`), and every `verifier` eager load.
- `routes/api.php:798-801`: both PATCH routes and the
  `permission:budget_verification,verify` group.
- `PermissionResolver.php:18` and `PermissionSeeder.php`: drop
  `budget_verification.verify`. The feature's other four actions (read, create,
  update, archive) stay — they still gate budget CRUD.
- `AlertSettingsController.php:100,113,140`: the pending-budget count, its
  contribution to the badge total, and its alert entry.
- `AuditorReadOnly.php:49`: narrow the verification-write exemption regex to
  `purchase-orders` only.

## Frontend

- `BudgetManagementPage.jsx`: remove `canVerifyBudget`, `handleVerifyBudget` and
  `handleRejectBudget` (`:330-352`), the three status badges (`:584-595`), the
  Verify/Reject buttons (`:706-710`), and reword the access notice at `:811`
  which currently names budget verifiers. `canView` becomes admin-or-read.
- `services/api.js:2141,2144`: delete `budgetAllocationsAPI.verify` and
  `.reject`.
- `AuditorDashboard.jsx`: remove the budget KPI and its fetch (`:22,65`). The PO
  KPI stays.

## Tests

`VerificationGateTest` is entirely about purchase orders and is left alone.

**Delete:** `BudgetVerificationTest` (all six tests); the two budget-verify tests
in `VerificationPermissionTest`; the three verification-alert tests in
`BudgetAlertTest`.

`VerificationPermissionTest::test_auditor_cannot_write_non_verification_endpoint`
stays — it deletes a budget, which an auditor still may not do.

**Update fixtures:** `BudgetTestCase::makeBudget` stops setting
`verification_status`, then `BudgetServiceTest:242`, `PoCommitTest:111`,
`VerificationExportTest:25`, `SeederTest:69`, `BudgetAllocationAccessTest`, and
the enforcement, lifecycle and warehouse-E2E fixtures.

**Add:**

1. A freshly created budget funds a PR approval with no verification step.
2. Raising a budget's total does not block the next approval.
3. `PATCH /api/budget-allocations/{id}/verify` returns 404.

## Verification

Run the backend suite against `airkingV5_test`, migrating that database first.
The frontend touch is presentational; a manual pass over Budget Management as
both admin and auditor confirms the buttons and KPI are gone and that PO
verification still works.
