# Remove Budget Management

**Date:** 2026-10-12
**Status:** Approved

## Why

Airking does not pay purchase orders up front. Suppliers are billed on payment
terms — Net 30 by default — so a period budget with `committed` and `spent`
counters never described how money actually moves. In practice the feature only
blocked purchase requests and purchase orders from being approved, which is
friction with no financial meaning behind it.

`purchase_orders.payment_terms` already exists and already defaults to `Net 30`,
so nothing needs to be built to replace budget management. It is removed, not
swapped out.

## Scope

Full removal: code, routes, UI, permission, nav bundle, and schema. Historical
budget rows are dumped to `backups/` and then dropped. No read-only view is kept.

Purchase requests and purchase orders approve on their own merit afterward, with
no spend ceiling of any kind. This is intended.

## Backend — deleted

- `app/Services/BudgetService.php`
- `app/Http/Controllers/Api/BudgetAllocationController.php`
- `app/Models/BudgetAllocation.php`, `app/Models/BudgetTransaction.php`
- `app/Events/BudgetChanged.php`
- `app/Exceptions/InsufficientBudgetException.php`, `NoActiveBudgetException.php`
- `app/Console/Commands/ReanchorBudgetCommitments.php`, `RebuildBudgetTotals.php`
- The `BUDGET ALLOCATIONS` route block in `routes/api.php` (8 routes + import)
- the budget-specific files in `tests/Feature/Budget/` (see Tests below)

`RealtimeBroadcaster::budgetChanged()` and the `.budget.changed` event go with them.

## Backend — edited

| File | Change |
|---|---|
| `PurchaseRequestController` | Drop `BudgetService` injection. `approve()` collapses to pending-check → status update → broadcast; the "no budget attached" 422 and both budget exceptions disappear. `merge()` loses its advisory per-warehouse check and its `budget_id => null` insert key. |
| `PurchaseOrderController` | Remove `commitPo`/`spend`/`releaseCommitment` from approve/reject and the two budget `catch` blocks. PO approval becomes a pure status transition. |
| `ReceivingController` | Remove the `spend()` call on completion. |
| `PoReceivingStatusService` | Remove the `spend()` call on completion. |
| `RestockRecommendationController` | Remove the `budget` key from the payload (no frontend consumer). |
| `ArchiveController` | Remove the `budget_allocations` archive type. |
| `PermissionSeeder` | Remove `budget_verification`. |
| `NavBundleSeeder` | Remove the Budget Management bundle. |

## Database

One migration, `drop_budget_management`:

1. Drop FK and column `purchase_requests.budget_id`
2. Drop `budget_transactions`, then `budget_allocations`
3. Delete the `budget_verification` permission rows (cascading to
   `role_permissions` and `user_permission_overrides`) and the
   `/admin/budget-management` nav bundle with its `nav_bundle_permissions`

`down()` recreates structure only — the data is not recoverable from the
migration, and it restores the post-2026_09_30 shape, without the verification
columns. Restore rows from `backups/budget-management-drop-20261012.sql` if needed.

`database/ci-schema.sql` and `database/schema/pgsql-schema.sql` are deliberately
left untouched. Both are point-in-time baselines that migrations run on top of —
CI imports the dump and then applies every migration added since, so the drop
migration removes the tables there too. Editing a baseline to match current head
would break that contract. `airkingdb.erd.json` has no migration path, so it is
pruned directly.

## Frontend — deleted

`admin/pages/BudgetManagementPage.jsx`, and every reference in `App.jsx`
(lazy import + route), `Sidebar.jsx` (preload map, nav item, `budget` icon case),
`navBundles.js`, `roles.js`, `permissions.js`, `api.js` (`budgetAllocationsAPI`),
`realtimeChannel.js` and its test, the `ArchiveManagement.jsx` tab, and the
`AlertsNotices.jsx` label.

## Frontend — `PurchaseRequestPage.jsx`

Remove `budgetColor`, the `budgetsByLoc` state and its fetch effect,
`renderBudgetCard`, the over-budget banner, and the two send-blocking guards.
`warehouseGroups` keeps only `{ locId, locName, subtotal }`.

Nothing informational is lost: the form already renders **BLOCK SUBTOTAL**
independently of the budget card. The `.pr-budget-*` CSS rules are removed.

## Tests

16 files in `tests/Feature/Budget/` test budget behaviour itself and are deleted.
The other 10 cover behaviour that survives — PO verification, per-line
warehouses, warehouse PR validation, PR merge — and move to a new
`Tests\Feature\Procurement` suite with a budget-free `ProcurementTestCase`
(`makeUser` / `makePr` / `makePo`, no `makeBudget`). Two individual test methods
inside kept files are budget-specific and go with them:
`PrMergeTest::test_combined_budget_advisory_blocks_merge_over_budget` and
`WarehousePrTest::test_blocks_when_a_warehouse_group_exceeds_its_budget`.

Three files outside that directory also touch budget:

- `tests/Feature/Permissions/BudgetAllocationAccessTest.php` — entirely about the
  `budget_verification` gate; deleted.
- `tests/Feature/Scoping/BudgetRestockScopingTest.php` — its two budget-scoping
  tests go; the restock-scoping test survives as
  `tests/Feature/Scoping/RestockScopingTest.php`.
- `tests/Feature/Permissions/SeederTest.php` — drops `budget_verification` from
  the segregation-of-duties carve-out list.

`VerificationPermissionTest` asserted that an auditor cannot write to a
non-verification endpoint, using `DELETE /api/budget-allocations/{id}` as the
example. The property survives the feature, so it is repointed at
`DELETE /api/suppliers/{id}` — also admin-only via `supplier_management.archive`,
and readable by an auditor, which is what makes it a real test of the boundary.

## Production rollout (Aiven PostgreSQL)

Production is Render + Aiven PostgreSQL, not MySQL — `DB_CONNECTION=pgsql`. The
live database was confirmed to be in the same pre-removal state as local: both
tables present, one allocation (₱30,000,000 with ₱252,304 committed against
PO-20261009-F0BW), one transaction, zero PRs carrying a `budget_id`, four
`budget_verification` permissions, one nav bundle.

The migration is Postgres-safe. Verified by direct introspection against the live
schema: the foreign key is named `purchase_requests_budget_id_foreign`, which is
the convention `dropForeign(['budget_id'])` resolves, the only foreign keys
touching the budget tables are that one and
`budget_transactions_budget_id_foreign`, and no view references either table.

**The migration must not run before the new image is live.** The previous release
still calls `BudgetService`, so dropping the tables under it returns 500 from PR
creation, PR approval, PO approval, and receiving. Deploy first, migrate second.
Written up in DEPLOYMENT.md under *Removing budget management*.

`migrate --pretend` is not a sufficient check here: Laravel returns `[]` for every
`SELECT` in pretend mode, so the `Schema::hasColumn` guard reads false and neither
the column drop nor the permission cleanup is reported. Only the two `DROP TABLE`s
appear.

Live rows exported to `backups/aiven-budget-data-20261012.sql` as replayable
INSERTs, with the permission and nav-bundle rows recorded as comments.

Unrelated drift found while checking: the live database is two migrations behind
the repo — `2026_10_08_000001_add_remote_sync_to_backup_logs` and
`2026_10_09_000000_add_identifiers_released_at_to_users`. Both are additive and
will apply with this release.

## Deliberately left alone

`LedgerPoster::FINANCE_TYPES` lists `budget_allocation` as an `inventory_ledger`
movement type, and `LedgerPosterTest` posts one. Despite the name this is not
budget-management code — `BudgetService` wrote `budget_transactions` and never
posted to `inventory_ledger`, and nothing in the codebase writes either finance
movement type. It is the ledger's own classification vocabulary, so it is out of
scope here.

## Verification

- `php artisan test` green on both `airkingvv6` and `airkingV5_test`
- `npm test` and `npm run build` green
- `grep -ri budget` over `back-end/app`, `back-end/routes`, `back-end/database`,
  and `frontend/src` returns only unrelated hits: the rate-limit "budget" wording
  in `AuthController` / `LoginOtpIssuer`, the receiving "package budget" wording
  in `BarcodeScan.jsx` / `api.js` / `ReceivingPostingService.php` /
  `ReceiveTopUpController`, `LedgerPoster::FINANCE_TYPES`, and the
  `.budget-quick-*` CSS class names the `PurchaseOrderPage` period picker uses
