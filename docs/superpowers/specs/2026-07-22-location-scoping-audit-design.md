# Location-Scoping Audit — Enforce "See Only Your Location" Everywhere

**Date:** 2026-07-22
**Branch:** backupV3
**Status:** Approved design, pending implementation plan

## Problem

Location-scoped users (branch_manager, warehouse_personnel) must only see data recorded at
their assigned location. Centralized users (admin, inventory_analyst = "IA", auditor = "AU")
see everything. The scoping infrastructure exists but is applied inconsistently: several
read endpoints return all locations' records regardless of who is asking (e.g. the Activity
Log returns every location's scan events). This audit closes those gaps everywhere.

## Policy (already the source of truth)

`User::isCentralized()` (`app/Models/User.php:198`): centralized = role_id ∈ {1 admin,
2 inventory_analyst, 5 auditor}. Scoped = {3 branch_manager, 4 warehouse_personnel},
restricted to `users.location_id` via `scopedLocationId()`. This matches the requirement —
**no policy change needed**, only consistent enforcement.

- **Scoped user, single-location record:** visible iff `record.location_id = user's location`.
- **Scoped user, dual-location record** (transfers, transfer-type scan events): visible iff
  the user's location is **either** `from_location_id`/`location_id` **or**
  `to_location_id` (source OR destination).
- **Scoped user with no assigned location:** sees nothing (`scopeForUser` already returns an
  impossible predicate — keep this).
- **Centralized user:** unfiltered.

## Enforcement pattern (backend is authoritative)

Every **read** endpoint (index/list/history/by-*) that returns location-tied records applies
one consistent filter, chosen per model:

1. **Model has `ScopesToUserLocation` trait** → call `->forUser($request->user())` on the
   read query. (Trait: `app/Models/Concerns/ScopesToUserLocation.php`.)
2. **Model has `location_id` but no trait** → add the trait to the model, then call
   `->forUser(...)`.
3. **Dual-location model** (Transfer, InventoryScanEvent) → a custom `forUser` scope that
   matches `from/location OR to_location` for scoped users, passthrough for centralized.
   (`Transfer` already has this at `Transfer.php:112` — mirror it for scan events.)

Applying `forUser` is safe for shared/admin read APIs: centralized users are unaffected, and
scoped users simply see fewer rows — it filters, it does not block. (Contrast with permission
*gating*, which the nav-permission work already covers and which is not changed here.)

Frontend already limits location pickers to `allowedLocations`; no frontend change is
required for correctness, but the backend fix is the real guarantee.

## Endpoint inventory

### A. Already scoped — verify (add a test; fix only if the test shows a read path is unscoped)
`AdjustmentController`, `InventoryController` (index + byLocation), `InventoryBatchController`,
`IssuanceController`, `ISTController`, `PoInventoryController`, `PurchaseOrderController`,
`ReceivingController`, `SaleController`, `TransactionController`, `TransferController`,
`TransferRequestController`, `DashboardController`, `AlertSettingsController`.

### B. Gaps — add scoping (location-tied, currently unscoped)
- **`InventoryScanEventController`** (Activity Log) — dual-location; add a `forUser`-style
  from-or-to filter (currently only filters when the request supplies `location_id`).
- **`PurchaseRequestController`** index — scope by PR `location_id` (and/or detail
  `location_id`, mirroring the PO pattern at `PurchaseOrderController:47-56`).
- **`WarrantyClaimController`** index — scope by the claim's `location_id`.
- **`WarrantyController`** index — scope by `location_id` if the warranty record carries one
  (verify column; if it only relates through issuance, scope via that relation).
- **`BudgetAllocationController`** index — scope by `location_id`.
- **`RestockRecommendationController`** index — scope by `location_id`.
- **`ArchiveController`**, **`AuditTrailController`**, **`BatchController`**,
  **`PendingProductController`** — verify whether each returns location-tied records; if so,
  apply the same pattern; if the endpoint is already admin/AU-only via RBAC and returns
  location-tied rows, still apply `forUser` (defence in depth).

### C. Global reference — explicitly NOT scoped (leave open to all)
`CustomerController`, `LocationController`, `BranchController`, `UserController` (user/role
management is admin-gated via RBAC, not location data), and product/category/supplier
catalog endpoints. Scoping these would break shared reference lookups. The plan must NOT add
`forUser` to these.

## Risks / notes

- **Over-scoping reference data** would break dropdowns and lookups app-wide. The plan treats
  section C as a hard exclusion list.
- **Write endpoints** already enforce location (strict-location roles). This audit is about
  **reads**; do not change write validation.
- **`users.location_id` null for a scoped user** → they see nothing. This is intended.
- Some models are queried through `DB::table(...)` (not Eloquent) in a few controllers
  (e.g. PoInventory serials, scan-event joins). Those need an explicit
  `->where('location_id', $scopedId)` guarded by `isCentralized()`, since the Eloquent scope
  doesn't apply. The plan flags each such raw query.

## Testing

A dedicated feature test (`LocationScopingMatrixTest`, `airkingV5_test`,
`DatabaseTransactions`) that, for each in-scope read endpoint:

1. Seeds two locations A and B, each with one record of that endpoint's type.
2. As a **branch_manager assigned to A**, calls the endpoint and asserts the response
   contains A's record and **not** B's.
3. As an **admin** (and spot-check IA and AU), asserts the response contains **both**.
4. For dual-location endpoints (transfers, scan events), seeds an A→B transfer and asserts
   both the A-user and the B-user see it.

Global (section C) endpoints get a companion assertion that a scoped user still sees all
reference rows (guarding against accidental over-scoping).

Backend suite must stay green; run `php artisan test` for the affected feature groups.
