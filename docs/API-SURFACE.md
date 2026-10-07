# API Surface — Controllers, Methods, and Routes

Every controller in the Laravel backend, its public methods, and the route that
reaches each one, grouped by the module boundaries the codebase itself declares.

This exists so the capstone document and the running system can be checked
against each other claim by claim. If the paper describes an endpoint, it should
appear below; if it appears below, the paper may cite it.

**Provenance**

| | |
|---|---|
| Commit | `56ea27b` (branch `balorov21`) |
| Captured | 5 October 2026 |
| Backend | Laravel 12 · PHP 8.2+ |
| Client | React 19 SPA |
| Database | MySQL / MariaDB |
| Auth | Laravel Sanctum tokens |
| Realtime | Laravel Reverb |

Derived mechanically rather than by reading: route URIs and their controller
bindings come from `php artisan route:list --json`, joined against the section
banners in `back-end/routes/api.php` for module grouping, and against the
declared `public function` signatures in `back-end/app/Http/Controllers` to
catch any method with no route. See [Regenerating this file](#regenerating-this-file).

---

## Summary

| Measure | Count |
|---|---|
| API controller files | 73 |
| Controllers unreachable by any route | **0** |
| Routed controller classes | 74 (73 ours + Sanctum's CSRF controller) |
| Distinct routed methods | 442 |
| Application routes | 449 |
| Module sections | 51 |
| Eloquent models | 85 |
| Migrations | 131 |
| Seeded roles | 5 |

Route totals reconcile: `route:list` reports **457** entries — 449 application
routes listed here, 5 closures (the API health check, a user audit-trail lookup,
and three framework storage routes), and 3 framework-owned routes (Laravel's
view and broadcast-auth controllers).

By HTTP verb:

| Verb | Routes | Notes |
|---|---|---|
| GET | 211 | Reads dominate — dashboards, audit explorers, report catalogs |
| POST | 125 | Every scan, approval, and restore action, not just creation |
| DELETE | 58 | Mostly soft archive; `/force` variants are the hard delete |
| PUT | 45 | Full-record updates on master data |
| PATCH | 11 | Workflow state changes — verify, reject, cancel |

(Sums to 450 because `UserController::update` answers both PUT and PATCH.)

---

## What the system is

Airking Inventory is an inventory, procurement, and issuance system for an
air-conditioning retailer and installer. Its spine is a single chain of custody
over physical stock: a **purchase request** is raised and approved against a
**budget allocation**, becomes a **purchase order**, arrives as a barcode-scanned
**receiving** batch, is drawn down by FIFO batch picking through **issuance**,
**sales**, or inter-location **transfer**, and leaves a serial-level trail that
the audit and warranty modules read back.

Two design commitments shape the API and explain most of its size:

1. **The physical unit is the unit of record.** Items carry serials and batches,
   so receiving, transfer, and issuance each need their own scan, count, and
   verify endpoints rather than a quantity field.
2. **Authority is data, not code.** Permissions live in the database as
   `feature, action` pairs resolved per user, so an endpoint can be re-delegated
   to another role without a code change.

---

## How a request is gated

All but a handful of public authentication routes sit inside one middleware
group. Read top to bottom, this is the access-control story:

| Layer | Effect |
|---|---|
| `auth:sanctum` | A valid bearer token is required. Login, OTP verification, and forgot-password are the only routes outside this. |
| `auditor.readonly` | Blocks every write for the auditor role, so audit access can be granted without granting mutation. |
| `location.scope` | Narrows queries to the location the user belongs to — the multi-location boundary. |
| `extend.sanctum.session` | Slides token expiry on activity, per the admin-configured session policy. |
| `password.changed` | Forces a pending password change to complete before any other endpoint answers. |
| `permission:feature,action` | Per-route check against the DB permission matrix. `role:admin` is used instead where an endpoint must never be delegated. |

Five seeded roles draw from that matrix across roughly forty permission
features: `admin`, `branch_manager`, `warehouse_personnel`,
`inventory_analyst`, `auditor`.

---

## Locations are one entity

**There is one `location` entity.** A branch and a warehouse differ only by a
`location_type` column on that table. They are not separate entities, separate
endpoints, or separate controllers. Every read and write goes through
`LocationController` at `/api/locations`.

The codebase previously blurred this: a `BranchController` and two
`/api/branches` + `/api/warehouses` compatibility routes survived alongside the
unified endpoint. All three have been removed, so the API no longer offers a
second vocabulary for the same thing.

> **Caution for the ERD.** In the UI, `/admin/branches` and `/admin/warehouses`
> remain as client-side React paths, but both render the same **Location
> Management** page. That is navigation history, not a data distinction — the
> ERD should show one entity and must not mirror it.

A `Branch` proxy model still exists in `app/Models/Branch.php`. It is not dead
code (`BatchController` uses it) but it queries `locations` filtered to
`location_type = 'branch_office'`, keeping a second vocabulary alive in the
model layer. Harmless today; worth folding into `Location` if the one-location
rule should hold everywhere.

---

## Why there is no transactions module

The name invites confusion, so it is worth stating plainly. A
`TransactionController` and a `Transaction` model sat in the repo from the very
first commit, but **they were never a working feature**: no commit in the
project's history ever registered a route for them, no migration ever created a
`transactions` table, and that table is absent from the schema dump. Routing it
would have failed on the first call.

The design was superseded rather than finished. Its model was flat — one row per
movement, keyed on `branch_id`, with no serial or batch column — which predates
both the location unification and the system's central commitment to tracking
the individual physical unit. That work went into the purpose-built modules
instead: receiving, issuance, transfer, sales, and adjustment, each with its own
table and its own scan, count, and verify steps.

Both files have now been removed.

> **The "Transactions" screen in the sidebar is unrelated to it.** That page
> (`frontend/src/admin/pages/TransactionManagement.jsx`) reads from
> `receivings`, `issuances`, `transfers`, `adjustments`, and `warranty-claims`.
> It is a consolidated movement history across the real modules — a presentation
> layer, not an entity. **The ERD should show no `transactions` table.**

---

## Dead code removed

Cleared from the codebase, with the full test suite green afterwards
(1,749 passed, 2 skipped, 0 failures). None of it was reachable, so none of it
belongs in the paper:

| Removed | Why it was safe |
|---|---|
| `BranchController` (6 methods) | No route; contradicted the one-location model |
| `TransactionController` (9 methods) | No route, ever; queried a table that does not exist |
| `Models/Transaction` | No backing table |
| `DevMailInboxController` | Local OTP-log reader whose docblock claimed a route that did not exist |
| `UserController::byRole` | Public, unrouted, uncalled |
| `GET /api/branches`, `GET /api/warehouses` | Compatibility proxies with no caller outside their own tests |
| `branchesAPI`, `warehousesAPI` (React) | Exported, never imported |
| `Branch::transactions()` | Relationship to a deleted model |

**The result worth stating in the paper: every controller in the codebase is now
reachable by a route, and every Eloquent model points at a table that exists.**
`SchemaModelContractTest` no longer needs a single exemption.

---

## Known issues

1. **`DatabaseSeeder` cannot run.** It imports `App\Models\Item` — a model
   deleted in commit `0576af4` — and calls `Item::create()`, so
   `php artisan db:seed` fails with a class-not-found error. Nothing in the
   setup scripts invokes it, which is why it went unnoticed. Either repair it or
   delete it, but **do not describe the project as seedable** until then.
2. **`/api/items` duplicates `/api/products`.** Both prefixes hit the same seven
   `ProductController` methods — one legacy alias, not two resources. Still
   live, because unlike the location proxies this one has callers, so retiring
   it is a refactor rather than a deletion.

---

## Reading notes

- A method with two routes shows both, separated by ` · `. For example
  `ProductController::index` is mounted on both `/api/products` and the legacy
  `/api/items` alias. **That is one resource, not two.**
- Six lookup controllers — AC type, brand, capacity, category, unit, variant —
  inherit `index` / `show` / `store` / `update` / `destroy` / `archived` /
  `restore` / `forceDestroy` (and `merge`, where two spellings of a string can
  collide) from the `HandlesLookupCrud` trait. The methods are expanded below,
  but each file holds only configuration. Describe the pattern once, not six
  times.
- Four endpoints are single-action controllers, shown as `__invoke`: receive
  count, receive top-up, receive verify, and receiving-draft submit.
- `IncidentInspectionController` and `BarcodeAdjustmentController` each appear
  under two modules, because the route file deliberately splits their endpoints
  into separate sections.
- Most records follow one archive pattern worth naming once rather than
  per-entity: `archived`, `restore`, and `forceDestroy` alongside ordinary CRUD,
  plus a unified `/api/archive/{module}` surface and a scheduled cleanup job.
- Module 51 is Laravel Sanctum's own controller, not project code. The other 50
  are ours.

---

## The full map

Grouped by the section banners declared in `back-end/routes/api.php`, in file
order — so the sequence below is the one the codebase asserts. Each entry reads
`method` — `VERB /path`.

### 1. AUTH (DO NOT TOUCH)

_3 controllers · 11 routes_

**AuthController**

- `login` — POST /api/login
- `loginChallenge` — GET /api/login/challenge
- `logout` — POST /api/logout
- `refresh` — POST /api/refresh-token
- `submitRecoveryEmail` — POST /api/login/recovery-email
- `user` — GET /api/user
- `verifyOtp` — POST /api/verify-otp

**ForgotPasswordOtpController**

- `forgot` — POST /api/forgot-password
- `reset` — POST /api/reset-password
- `verify` — POST /api/verify-reset-otp

**PasswordResetController**

- `complete` — POST /api/password-reset/complete


### 2. DASHBOARD (DO NOT TOUCH)

_2 controllers · 11 routes_

**BatchController**

- `index` — GET /api/batch

**DashboardController**

- `filterOptions` — GET /api/dashboard/filter-options
- `filteredCharts` — GET /api/dashboard/charts
- `index` — GET /api/dashboard
- `layoutSummary` — GET /api/layout-summary
- `lowStock` — GET /api/dashboard/low-stock
- `pendingApprovals` — GET /api/dashboard/pending-approvals
- `recentMovements` — GET /api/dashboard/recent-movements
- `recentSales` — GET /api/dashboard/recent-sales
- `stats` — GET /api/dashboard/stats
- `stockMovementTrends` — GET /api/dashboard/stock-movement-trends


### 3. ALERT SETTINGS (Mark All Read)

_6 controllers · 14 routes_

**AccountPolicyController**

- `show` — GET /api/system/account-policy
- `update` — PUT /api/system/account-policy

**AlertSettingsController**

- `index` — GET /api/alerts/settings
- `markAllRead` — POST /api/alerts/mark-all-read

**CompanySettingsController**

- `show` — GET /api/system/company-settings
- `update` — PUT /api/system/company-settings

**LabelPrinterSettingsController**

- `index` — GET /api/system/label-printers
- `test` — POST /api/system/label-printers/{location}/test
- `update` — PUT /api/system/label-printers/{location}

**PasswordResetController**

- `active` — GET /api/password-reset/active
- `issue` — POST /api/password-reset/issue
- `revoke` — POST /api/password-reset/active/{passwordResetCode}/revoke

**SystemSessionSettingsController**

- `show` — GET /api/system/session-settings
- `update` — PUT /api/system/session-settings


### 4. USER MANAGEMENT (ADMIN ONLY)

_4 controllers · 26 routes_

**AccountController**

- `deleteAvatar` — DELETE /api/me/avatar
- `show` — GET /api/me/profile
- `update` — PUT /api/me/profile
- `updateEmail` — PUT /api/me/email
- `updatePassword` — PUT /api/me/password
- `uploadAvatar` — POST /api/me/avatar

**NavBundleController**

- `index` — GET /api/nav-bundles

**PermissionController**

- `auditLogs` — GET /api/permission-audit-logs
- `index` — GET /api/permissions
- `mine` — GET /api/me/permissions
- `roleDefaults` — GET /api/role-permissions
- `updateRoleDefaults` — PUT /api/role-permissions
- `updateUserPermissions` — PUT /api/users/{userId}/permissions
- `userPermissions` — GET /api/users/{userId}/permissions

**UserController**

- `archived` — GET /api/users/archived
- `byLocation` — GET /api/users/by-location/{locationId}
- `changePassword` — PUT /api/users/{user}/change-password
- `destroy` — DELETE /api/users/{user}
- `forceDestroy` — DELETE /api/users/{id}/force
- `index` — GET /api/users
- `resendCredentials` — POST /api/users/{user}/resend-credentials
- `restore` — POST /api/users/{id}/restore
- `show` — GET /api/users/{user}
- `store` — POST /api/users
- `update` — PUT|PATCH /api/users/{user}
- `updateStatus` — PUT /api/users/{user}/status


### 5. ERD: ROLES (roles)

_1 controller · 5 routes_

**RoleController**

- `destroy` — DELETE /api/roles/{id}
- `index` — GET /api/roles
- `show` — GET /api/roles/{id}
- `store` — POST /api/roles
- `update` — PUT /api/roles/{id}


### 6. ERD: STATUS (status_lookup)

_1 controller · 5 routes_

**StatusLookupController**

- `destroy` — DELETE /api/status-lookup/{id}
- `index` — GET /api/status-lookup
- `show` — GET /api/status-lookup/{id}
- `store` — POST /api/status-lookup
- `update` — PUT /api/status-lookup/{id}


### 7. ERD: TRANSACTION TYPE (transaction_type_lookup)

_1 controller · 1 route_

**TransactionTypeLookupController**

- `index` — GET /api/transaction-type-lookup


### 8. INVENTORY SCAN EVENTS (audit trail)

_1 controller · 1 route_

**InventoryScanEventController**

- `index` — GET /api/scan-events


### 9. ARCHIVE MANAGEMENT — unified endpoints

_1 controller · 5 routes_

**ArchiveController**

- `forceDestroy` — DELETE /api/archive/{module}/{id}/force
- `index` — GET /api/archive/{module}
- `restore` — POST /api/archive/{module}/{id}/restore
- `restoreAll` — POST /api/archive/{module}/restore-all
- `summary` — GET /api/archive/summary


### 10. ARCHIVE SETTINGS — configuration, scheduler, run history

_1 controller · 7 routes_

**ArchiveSettingsController**

- `index` — GET /api/archive-settings
- `logs` — GET /api/archive-settings/logs
- `preview` — POST /api/archive-settings/preview
- `runCleanup` — POST /api/archive-settings/run-cleanup
- `runNow` — POST /api/archive-settings/run-now
- `updateModule` — PUT /api/archive-settings/module/{module}
- `updateSchedule` — PUT /api/archive-settings/schedule


### 11. BACKUP — schedule, manual run, history, restore

_1 controller · 6 routes_

**BackupController**

- `download` — GET /api/backup/history/{id}/download
- `history` — GET /api/backup/history
- `restore` — POST /api/backup/history/{id}/restore
- `runNow` — POST /api/backup/run
- `settings` — GET /api/backup/settings
- `updateSettings` — PUT /api/backup/settings


### 12. ERD: CATEGORY (category_lookup)

_1 controller · 9 routes_

**CategoryLookupController**

- `archived` — GET /api/categories/archived
- `destroy` — DELETE /api/categories/{category}
- `forceDestroy` — DELETE /api/categories/{category}/force
- `index` — GET /api/categories
- `performance` — GET /api/categories/performance
- `restore` — POST /api/categories/{category}/restore
- `show` — GET /api/categories/{category}
- `store` — POST /api/categories
- `update` — PUT /api/categories/{category}


### 13. ERD: BRAND (brand_lookup)

_1 controller · 8 routes_

**BrandLookupController**

- `archived` — GET /api/brands/archived
- `destroy` — DELETE /api/brands/{brand}
- `forceDestroy` — DELETE /api/brands/{brand}/force
- `index` — GET /api/brands
- `restore` — POST /api/brands/{brand}/restore
- `show` — GET /api/brands/{brand}
- `store` — POST /api/brands
- `update` — PUT /api/brands/{brand}


### 14. ERD: UNIT LOOKUP (unit_lookup)

_4 controllers · 34 routes_

**AcTypeLookupController**

- `archived` — GET /api/ac-types/archived
- `destroy` — DELETE /api/ac-types/{id}
- `forceDestroy` — DELETE /api/ac-types/{id}/force
- `index` — GET /api/ac-types
- `restore` — POST /api/ac-types/{id}/restore
- `show` — GET /api/ac-types/{id}
- `store` — POST /api/ac-types
- `update` — PUT /api/ac-types/{id}

**CapacityLookupController**

- `archived` — GET /api/capacities/archived
- `destroy` — DELETE /api/capacities/{id}
- `forceDestroy` — DELETE /api/capacities/{id}/force
- `index` — GET /api/capacities
- `merge` — POST /api/capacities/{id}/merge
- `restore` — POST /api/capacities/{id}/restore
- `show` — GET /api/capacities/{id}
- `store` — POST /api/capacities
- `update` — PUT /api/capacities/{id}

**UnitLookupController**

- `archived` — GET /api/units/archived
- `destroy` — DELETE /api/units/{id}
- `forceDestroy` — DELETE /api/units/{id}/force
- `index` — GET /api/units
- `restore` — POST /api/units/{id}/restore
- `show` — GET /api/units/{id}
- `store` — POST /api/units
- `update` — PUT /api/units/{id}

**VariantLookupController**

- `archived` — GET /api/variants/archived
- `destroy` — DELETE /api/variants/{id}
- `forceDestroy` — DELETE /api/variants/{id}/force
- `index` — GET /api/variants
- `merge` — POST /api/variants/{id}/merge
- `restore` — POST /api/variants/{id}/restore
- `show` — GET /api/variants/{id}
- `store` — POST /api/variants
- `update` — PUT /api/variants/{id}


### 15. ERD: LOCATION (location) — unified physical places

_1 controller · 8 routes_

**LocationController**

- `archived` — GET /api/locations/archived
- `destroy` — DELETE /api/locations/{location}
- `forceDestroy` — DELETE /api/locations/{id}/force
- `index` — GET /api/locations
- `restore` — POST /api/locations/{id}/restore
- `show` — GET /api/locations/{location}
- `store` — POST /api/locations
- `update` — PUT /api/locations/{location}


### 16. ERD: PRODUCT (Product table)

_2 controllers · 19 routes_

**PendingProductController**

- `approve` — POST /api/pending-products/{pendingProduct}/approve
- `catalog` — GET /api/installation-material-supply/catalog
- `index` — GET /api/pending-products
- `store` — POST /api/pending-products
- `supplyTypesIndex` — GET /api/supply-types

**ProductController**

- `archived` — GET /api/products/archived
- `destroy` — DELETE /api/items/{product} · DELETE /api/products/{product}
- `export` — GET /api/products/export
- `forceDestroy` — DELETE /api/products/{id}/force
- `index` — GET /api/items · GET /api/products
- `restore` — POST /api/products/{id}/restore
- `show` — GET /api/items/{product} · GET /api/products/{product}
- `store` — POST /api/items · POST /api/products
- `update` — PUT /api/items/{product} · PUT /api/products/{product}


### 17. ERD: ITEM_SERIAL (item_serial)

_3 controllers · 10 routes_

**BarcodeQueueController**

- `ids` — GET /api/barcode-labels/queue/ids
- `index` — GET /api/barcode-labels/queue

**LabelPrintController**

- `print` — POST /api/barcode-labels/print
- `status` — GET /api/barcode-labels/status

**SerialController**

- `destroy` — DELETE /api/serials/{serial}
- `index` — GET /api/serials
- `scan` — POST /api/serials/scan
- `show` — GET /api/serials/{serial}
- `store` — POST /api/serials
- `update` — PUT /api/serials/{serial}


### 18. ERD: SUPPLIER (supplier)

_1 controller · 12 routes_

**SupplierController**

- `addProduct` — POST /api/suppliers/{id}/products
- `archived` — GET /api/suppliers/archived
- `destroy` — DELETE /api/suppliers/{id}
- `forceDestroy` — DELETE /api/suppliers/{id}/force
- `index` — GET /api/suppliers
- `products` — GET /api/suppliers/{id}/products
- `removeProduct` — DELETE /api/suppliers/{id}/products/{productId}
- `restore` — POST /api/suppliers/{id}/restore
- `show` — GET /api/suppliers/{id}
- `store` — POST /api/suppliers
- `update` — PUT /api/suppliers/{id}
- `updateProduct` — PUT /api/suppliers/{id}/products/{supplierProdId}


### 19. Customer Management

_1 controller · 11 routes_

**CustomerController**

- `archived` — GET /api/customers/archived
- `destroy` — DELETE /api/customers/{id}
- `export` — GET /api/customers/export
- `forceDestroy` — DELETE /api/customers/{id}/force
- `history` — GET /api/customers/{id}/history
- `index` — GET /api/customers
- `restore` — POST /api/customers/{id}/restore
- `search` — GET /api/customers/search
- `show` — GET /api/customers/{id}
- `store` — POST /api/customers
- `update` — PUT /api/customers/{id}


### 20. Warranty Management

_1 controller · 5 routes_

**WarrantyController**

- `export` — GET /api/warranties/export
- `index` — GET /api/warranties
- `lookupBySerial` — GET /api/warranties/lookup/{serial}
- `markClaimed` — PUT /api/warranties/{id}/claim
- `show` — GET /api/warranties/{id}


### 21. Warranty Claims (repair / replacement)

_1 controller · 12 routes_

**WarrantyClaimController**

- `addCustomOrderLine` — POST /api/warranty-claims/{claimId}/order/custom
- `addItem` — POST /api/warranty-claims/{claimId}/item
- `addSetOrder` — POST /api/warranty-claims/{claimId}/order/set
- `complete` — POST /api/warranty-claims/{claimId}/complete
- `downloadSlip` — GET /api/warranty-claims/{claimId}/slip
- `index` — GET /api/warranty-claims
- `init` — POST /api/warranty-claims/init
- `receiptPdf` — GET /api/warranty-claims/{claimId}/receipt/pdf
- `removeItem` — DELETE /api/warranty-claims/{claimId}/item/{detailId}
- `removeOrderLine` — DELETE /api/warranty-claims/{claimId}/order/{detailId}
- `show` — GET /api/warranty-claims/{claimId}
- `updateOrderLine` — PUT /api/warranty-claims/{claimId}/order/{detailId}


### 22. Issuance Scan (barcode-driven issuance flow)

_1 controller · 8 routes_

**IssuanceScanController**

- `activeDraft` — GET /api/issuance-scan/active-draft
- `completeIssuance` — POST /api/issuance-scan/complete/{issuance_id}
- `getSessionStatus` — GET /api/issuance-scan/session/{issuance_id}
- `initSession` — POST /api/issuance-scan/init
- `removeItem` — DELETE /api/issuance-scan/item/{detail_id}
- `revertToDraft` — POST /api/issuance-scan/{issuance_id}/revert-to-draft · POST /api/issuance-scan/{issuance_id}/revert-to-draft-beacon
- `scanItem` — POST /api/issuance-scan/scan


### 23. Product Sets (BOQ templates)

_1 controller · 7 routes_

**ProductSetController**

- `archived` — GET /api/product-sets/archived
- `destroy` — DELETE /api/product-sets/{id}
- `index` — GET /api/product-sets
- `restore` — POST /api/product-sets/{id}/restore
- `show` — GET /api/product-sets/{id}
- `store` — POST /api/product-sets
- `update` — PUT /api/product-sets/{id}


### 24. Aircon Models (indoor + outdoor unit compositions)

_1 controller · 7 routes_

**AirconModelController**

- `archived` — GET /api/aircon-models/archived
- `destroy` — DELETE /api/aircon-models/{airconModel}
- `index` — GET /api/aircon-models
- `restore` — POST /api/aircon-models/{id}/restore
- `show` — GET /api/aircon-models/{airconModel}
- `store` — POST /api/aircon-models
- `update` — PUT /api/aircon-models/{airconModel}


### 25. Legacy aircon conversion (one complete row -> an indoor/outdoor pair)

_1 controller · 1 route_

**AirconConversionController**

- `convert` — POST /api/products/{product}/convert-to-two-piece


### 26. Incident Reports (FIFO-violation overrides)

_1 controller · 5 routes_

**IncidentReportController**

- `index` — GET /api/incident-reports
- `lookup` — GET /api/incident-reports/lookup
- `showPhoto` — GET /api/incident-reports/{id}/photo
- `store` — POST /api/incident-reports
- `uploadPhoto` — POST /api/incident-reports/photo


### 27. Inspection (before the admin decides) and the decision itself

_1 controller · 7 routes_

**IncidentInspectionController**

- `decide` — POST /api/incident-reports/{id}/decide
- `destroyEvidence` — DELETE /api/incident-reports/{id}/evidence/{evidenceId}
- `setOutcome` — POST /api/incident-reports/{id}/items/{itemId}/outcome
- `show` — GET /api/incident-reports/{id}
- `showEvidencePhoto` — GET /api/incident-reports/{id}/evidence/{evidenceId}/photo
- `storeEvidence` — POST /api/incident-reports/{id}/evidence
- `submit` — POST /api/incident-reports/{id}/inspection


### 28. The incident-items register (replaces /stock-holds)

_1 controller · 1 route_

**IncidentInspectionController**

- `index` — GET /api/incident-items


### 29. ERD: PURCHASE_ORDER (purchase_order)

_1 controller · 14 routes_

**PurchaseOrderController**

- `archived` — GET /api/purchase-orders/archived
- `destroy` — DELETE /api/purchase-orders/{id}
- `export` — GET /api/purchase-orders/export
- `forceDestroy` — DELETE /api/purchase-orders/{id}/force
- `history` — GET /api/purchase-orders/{id}/history
- `index` — GET /api/purchase-orders
- `pdf` — GET /api/purchase-orders/{id}/pdf
- `productsBySupplier` — GET /api/purchase-orders/products-by-supplier/{supplier_id}
- `reject` — PATCH /api/purchase-orders/{id}/reject
- `restore` — POST /api/purchase-orders/{id}/restore
- `show` — GET /api/purchase-orders/{id}
- `store` — POST /api/purchase-orders
- `update` — PUT /api/purchase-orders/{id}
- `verify` — PATCH /api/purchase-orders/{id}/verify


### 30. BUDGET ALLOCATIONS

_1 controller · 10 routes_

**BudgetAllocationController**

- `archived` — GET /api/budget-allocations/archived
- `available` — GET /api/budget-allocations/{id}/available
- `destroy` — DELETE /api/budget-allocations/{id}
- `forceDestroy` — DELETE /api/budget-allocations/{id}/force
- `index` — GET /api/budget-allocations
- `restore` — POST /api/budget-allocations/{id}/restore
- `show` — GET /api/budget-allocations/{id}
- `store` — POST /api/budget-allocations
- `transactions` — GET /api/budget-allocations/{id}/transactions
- `update` — PUT /api/budget-allocations/{id}


### 31. PURCHASE REQUESTS (PR → PO workflow)

_1 controller · 15 routes_

**PurchaseRequestController**

- `approve` — PATCH /api/purchase-requests/{id}/approve
- `archived` — GET /api/purchase-requests/archived
- `convert` — GET /api/purchase-requests/{id}/convert
- `convertStore` — POST /api/purchase-requests/{id}/convert
- `destroy` — DELETE /api/purchase-requests/{id}
- `export` — GET /api/purchase-requests/export
- `forceDestroy` — DELETE /api/purchase-requests/{id}/force
- `history` — GET /api/purchase-requests/{id}/history
- `index` — GET /api/purchase-requests
- `lowStock` — GET /api/purchase-requests/low-stock
- `pending` — GET /api/purchase-requests/pending/list
- `reject` — PATCH /api/purchase-requests/{id}/reject
- `restore` — POST /api/purchase-requests/{id}/restore
- `show` — GET /api/purchase-requests/{id}
- `store` — POST /api/purchase-requests


### 32. PR Recommendation analytics (restock engine)

_1 controller · 5 routes_

**RestockRecommendationController**

- `applySuggestion` — POST /api/restock/suggestions/{id}/apply
- `dismissSuggestion` — POST /api/restock/suggestions/{id}/dismiss
- `index` — GET /api/restock/recommendations
- `recalculate` — POST /api/restock/recalculate
- `suggestions` — GET /api/restock/suggestions


### 33. Analytics Dashboard (admin / inventory analyst / auditor)

_1 controller · 6 routes_

**AnalyticsDashboardController**

- `filters` — GET /api/analytics/filters
- `locationPerformance` — GET /api/analytics/location-performance
- `movement` — GET /api/analytics/movement
- `predictions` — GET /api/analytics/predictions
- `profitLoss` — GET /api/analytics/profit-loss
- `stockLevel` — GET /api/analytics/stock-level


### 34. ERD: RECEIVING (RECEIVING)

_1 controller · 7 routes_

**ReceivingController**

- `destroy` — DELETE /api/receivings/{id}
- `index` — GET /api/receivings
- `poLog` — GET /api/receivings/po-log
- `poScanStatus` — GET /api/receivings/po-scan-status/{poId}
- `show` — GET /api/receivings/{id}
- `store` — POST /api/receivings
- `update` — PUT /api/receivings/{id}


### 35. ERD: PURCHASE_RETURN (purchase_return)

_1 controller · 5 routes_

**PurchaseReturnController**

- `destroy` — DELETE /api/purchase-returns/{id}
- `index` — GET /api/purchase-returns
- `show` — GET /api/purchase-returns/{id}
- `store` — POST /api/purchase-returns
- `update` — PUT /api/purchase-returns/{id}


### 36. ERD: SALES (sales)

_1 controller · 5 routes_

**SaleController**

- `destroy` — DELETE /api/sales/{id}
- `index` — GET /api/sales
- `show` — GET /api/sales/{id}
- `store` — POST /api/sales
- `update` — PUT /api/sales/{id}


### 37. ERD: DELIVERY_RECEIPT (delivery_receipt)

_1 controller · 5 routes_

**DeliveryReceiptController**

- `destroy` — DELETE /api/delivery-receipts/{id}
- `index` — GET /api/delivery-receipts
- `show` — GET /api/delivery-receipts/{id}
- `store` — POST /api/delivery-receipts
- `update` — PUT /api/delivery-receipts/{id}


### 38. ERD: ISSUANCE (issuance)

_2 controllers · 16 routes_

**IssuanceController**

- `archived` — GET /api/issuances/archived
- `cancel` — POST /api/issuances/{id}/cancel
- `destroy` — DELETE /api/issuances/{id}
- `forceDestroy` — DELETE /api/issuances/{id}/force
- `index` — GET /api/issuances
- `restore` — POST /api/issuances/{id}/restore
- `show` — GET /api/issuances/{id}
- `store` — POST /api/issuances
- `update` — PUT /api/issuances/{id}

**IssuanceOrderController**

- `addCustomLine` — POST /api/issuances/{id}/lines/custom
- `addSetLine` — POST /api/issuances/{id}/lines/set
- `confirmQuote` — POST /api/issuances/{id}/confirm-quote
- `pickPlan` — GET /api/issuances/{id}/pick-plan
- `quote` — GET /api/issuances/{id}/quote
- `removeLine` — DELETE /api/issuances/{id}/lines/{lineId}
- `updateLine` — PUT /api/issuances/{id}/lines/{lineId}


### 39. ERD: TRANSFER (transfer)

_1 controller · 27 routes_

**TransferController**

- `addTracking` — POST /api/transfers/{id}/tracking
- `archived` — GET /api/transfers/archived
- `cancel` — PATCH /api/transfers/{id}/cancel
- `confirmDispatch` — POST /api/transfers/{id}/confirm-dispatch
- `countReceiveScan` — POST /api/transfers/{id}/count-receive-scan
- `countScan` — POST /api/transfers/{id}/count-scan
- `destroy` — DELETE /api/transfers/{id}
- `forceDestroy` — DELETE /api/transfers/{id}/force
- `index` — GET /api/transfers
- `pickList` — GET /api/transfers/{id}/pick-list
- `receipt` — GET /api/transfers/{id}/receipt
- `receive` — POST /api/transfers/{id}/receive
- `receiveForm` — GET /api/transfers/{id}/receive-form
- `receiveScan` — POST /api/transfers/{id}/receive-scan
- `receiveStore` — POST /api/transfers/{id}/receive-confirm
- `reject` — PATCH /api/transfers/{id}/reject
- `removeReceiveScan` — DELETE /api/transfers/{id}/receive-scan/{scanId}
- `removeScan` — DELETE /api/transfers/{id}/scan/{scanId}
- `restore` — POST /api/transfers/{id}/restore
- `scan` — POST /api/transfers/{id}/scan
- `scanStatus` — GET /api/transfers/{id}/scan-status
- `show` — GET /api/transfers/{id}
- `sourceAvailability` — GET /api/transfers/{id}/source-availability
- `store` — POST /api/transfers
- `update` — PUT /api/transfers/{id}
- `updateRequest` — PATCH /api/transfers/{id}/request
- `verify` — POST /api/transfers/{id}/verify


### 40. ERD: ADJUSTMENT (adjustment)

_2 controllers · 14 routes_

**AdjustmentController**

- `archived` — GET /api/adjustments/archived
- `destroy` — DELETE /api/adjustments/{id}
- `forceDestroy` — DELETE /api/adjustments/{id}/force
- `index` — GET /api/adjustments
- `restore` — POST /api/adjustments/{id}/restore
- `show` — GET /api/adjustments/{id}
- `store` — POST /api/adjustments
- `update` — PUT /api/adjustments/{id}

**BarcodeAdjustmentController**

- `getAdjustmentHistory` — GET /api/adjustments/correction-history
- `getReceivingHistory` — GET /api/adjustments/receiving-history/{po_id}
- `getScanCorrectionDetail` — GET /api/adjustments/corrections/{correction_id}
- `lookupBarcode` — GET /api/adjustments/lookup-barcode
- `previewCorrection` — POST /api/adjustments/preview
- `submitCorrection` — POST /api/adjustments/submit


### 41. COMPLIANCE REVIEW

_1 controller · 1 route_

**BarcodeAdjustmentController**

- `getComplianceLogs` — GET /api/compliance/logs


### 42. ERD: PROFIT_LOSS (profit_loss)

_1 controller · 5 routes_

**ProfitLossController**

- `destroy` — DELETE /api/profit-loss/{id}
- `index` — GET /api/profit-loss
- `show` — GET /api/profit-loss/{id}
- `store` — POST /api/profit-loss
- `update` — PUT /api/profit-loss/{id}


### 43. AUDIT TRAIL (legacy, unstructured)

_1 controller · 1 route_

**AuditTrailController**

- `legacyIndex` — GET /api/audit-trail


### 44. AUDIT TRAIL v2 (structured, filterable, with labels)

_2 controllers · 4 routes_

**AuditTrailController**

- `filterOptions` — GET /api/audit-trail/v2/filter-options
- `index` — GET /api/audit-trail/v2
- `show` — GET /api/audit-trail/v2/{audit_id}

**LoginSessionController**

- `index` — GET /api/login-sessions


### 45. AUDIT & EVIDENCE EXPLORER

_1 controller · 9 routes_

**AuditEvidenceController**

- `batchLifecycle` — GET /api/audit-evidence/batches/{batchId}/lifecycle
- `breakdown` — GET /api/audit-evidence/breakdown
- `event` — GET /api/audit-evidence/events/{correlationId}
- `exceptions` — GET /api/audit-evidence/exceptions
- `movements` — GET /api/audit-evidence/movements
- `reconciliation` — GET /api/audit-evidence/reconciliation
- `resolveException` — POST /api/audit-evidence/exceptions/{findingId}/resolve
- `serialLifecycle` — GET /api/audit-evidence/serials/{serial}/lifecycle
- `summary` — GET /api/audit-evidence/summary


### 46. ERD: ACTIVITY_LOG (activity_log)

_1 controller · 4 routes_

**ActivityLogController**

- `destroy` — DELETE /api/activity-log/{id}
- `index` — GET /api/activity-log
- `show` — GET /api/activity-log/{id}
- `store` — POST /api/activity-log


### 47. INVENTORY (legacy scan/adjust endpoints via InventoryController)

_3 controllers · 11 routes_

**InventoryBatchController**

- `index` — GET /api/inventory-batches
- `update` — PATCH /api/inventory-batches/{batch}

**InventoryController**

- `batches` — GET /api/inventory/{inventory}/batches
- `byItem` — GET /api/inventory/product/{product}
- `byLocation` — GET /api/inventory/location/{location}
- `index` — GET /api/inventory
- `show` — GET /api/inventory/{inventory}
- `stockHistory` — GET /api/inventory/{inventory}/history

**PoInventoryController**

- `index` — GET /api/po-inventory-groups
- `show` — GET /api/po-inventory-groups/{po}
- `updateLocation` — PATCH /api/po-inventory-groups/{po}


### 48. INVENTORY OPERATION (barcode scan)

_8 controllers · 15 routes_

**BarcodeScanController**

- `index` — GET /api/barcode-scans
- `scanLookup` — POST /api/barcode/scan
- `store` — POST /api/barcode-scans

**InventoryController**

- `scanBarcode` — POST /api/inventory/scan-barcode

**InventoryScanTransactionController**

- `store` — POST /api/inventory/scan-transaction

**ReceiveCountController**

- `__invoke` — POST /api/inventory/receive-count

**ReceiveTopUpController**

- `__invoke` — POST /api/inventory/receive-topup

**ReceiveVerifyController**

- `__invoke` — POST /api/inventory/receive-verify

**ReceivingDraftController**

- `destroy` — DELETE /api/receiving-drafts/{draft}
- `destroyItem` — DELETE /api/receiving-drafts/{draft}/items/{item}
- `show` — GET /api/receiving-drafts
- `store` — POST /api/receiving-drafts
- `storeItem` — POST /api/receiving-drafts/{draft}/items
- `updateItem` — PATCH /api/receiving-drafts/{draft}/items/{item}

**ReceivingDraftSubmitController**

- `__invoke` — POST /api/receiving-drafts/{draft}/submit


### 49. REPORTS & ANALYTICS

_1 controller · 6 routes_

**ReportController**

- `catalog` — GET /api/reports/catalog
- `download` — GET /api/reports/runs/{id}/download · GET /api/reports/runs/{id}/file
- `filterUsers` — GET /api/reports/filter-users
- `generate` — POST /api/reports/generate
- `runs` — GET /api/reports/runs


### 50. Download endpoints — token passed as ?token= query param (browser download)

_4 controllers · 7 routes_

**AdjustmentController**

- `pdf` — GET /api/adjustments/{id}/pdf

**IssuanceScanController**

- `downloadDeliveryReceiptPdf` — GET /api/issuance-scan/delivery-receipt/{issuance_id}/pdf

**ReceivingController**

- `poReceiptCsv` — GET /api/purchase-orders/{id}/receiving-receipt/csv
- `poReceiptPdf` — GET /api/purchase-orders/{id}/receiving-receipt/pdf
- `receiptCsv` — GET /api/receivings/{id}/receipt/csv
- `receiptPdf` — GET /api/receivings/{id}/receipt/pdf

**TransferController**

- `receiptPdf` — GET /api/transfers/{id}/receipt/pdf


### 51. Sanctum / framework

_1 controller · 1 route_

**CsrfCookieController**

- `show` — GET /sanctum/csrf-cookie


### 52. OPS: external scheduler trigger

_1 controller · 1 route_

**SchedulerTickController**

- `__invoke` — GET|POST /api/scheduler/tick

The only route outside `auth:sanctum` that causes server-side work. It exists
because the deployed container has no cron, so `schedule:run` never fires on its
own; a free external cron service calls this once a minute instead.

It carries its own shared-secret check rather than Sanctum, because the caller
is a cron service with no user and no session. Disabled — 404, not 403 — unless
`SCHEDULER_RUN_TOKEN` is set to 32+ characters, so it does not exist at all in
environments that do not use it. Rate limited to 6/min.

It runs one tick of Laravel's scheduler and exposes no way to name a command;
`routes/console.php` still decides what is due. See
[DEPLOY-RENDER.md](DEPLOY-RENDER.md) Phase 9.


---

## Regenerating this file

The listing above is generated, not hand-maintained. To refresh it after a
routing change:

```bash
cd back-end
php artisan route:list --json > routes.json
```

Then group the entries by the `|`-delimited section banners in
`routes/api.php` (a banner title is the first `|` line after a block-opening
rule) and emit one bullet per controller method. Two details matter for an
accurate result:

- Route actions appear both as `Controller@method` and as a bare class name
  for `__invoke` single-action controllers — handle both, or the four receive
  endpoints go missing.
- Some routes reference controllers fully qualified
  (`[\App\Http\Controllers\Api\FooController::class, bar]`) and some by short name,
  so a pattern matching only the short form silently drops whole modules.

Cross-check the result against the declared `public function` signatures in
`app/Http/Controllers` — a method present there but absent from `route:list`
is unreachable, which is how the dead code above was found.
