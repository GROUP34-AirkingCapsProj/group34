# Inventory Management System — Production Readiness QA Report

**Test date:** 2026-07-28  
**Environment:** Local production configuration with isolated `airkingV5_test` workflow database  
**Backend:** Laravel 12.55.1 / PHP 8.2.30 / MySQL  
**Frontend:** React 19.2.3

## Executive result

**Overall readiness score: 76% — not ready for an unqualified production release.**

The backend business layer is strong: 611 automated tests passed with 2,282 assertions, covering procurement budgets, receiving, FIFO batches, issuance, transfers, warranties, permissions, scoping, dashboards, history, and real-time events. Database foreign-key/orphan checks and monetary issuance reconciliation passed.

Release should be held until the one production stock/batch inconsistency is repaired and prevented, and the frontend regression suite is restored to green. Internet-facing deployment also requires tighter origin and transport security configuration.

## Test evidence

- Backend: **611 passed, 1 skipped, 0 failed** after bringing the isolated test schema current.
- Frontend: **100 passed, 5 failed** across 22 suites.
- Frontend production build: completed successfully with ESLint warnings.
- Database engine check: every application table reported `OK`.
- API inventory: 366 routes; 190 state-changing routes.
- Unauthenticated mutations: only login, registration, OTP verification, and password-reset completion.
- Live smoke:
  - Frontend `http://localhost:3000`: HTTP 200.
  - Protected `/api/issuances` without a token: HTTP 401 as expected.
- Production data:
  - Negative or over-available inventory rows: 0.
  - Duplicate product/location inventory rows: 0.
  - Invalid batch balances: 0.
  - Orphan receiving, issuance, transfer, and DR rows: 0.
  - Invalid issuance/transfer quantities: 0.
  - Issuance subtotal mismatches: 0.
  - Audit rows: 179; missing timestamps: 0; orphan user references: 0.

## Passed functional areas

The passing backend suite verifies:

- Account profile, avatar, email, password, password policies, authentication restrictions.
- Budgets, PR approval, PO verification/approval, commitment, settlement, warehouse budget flows, merge/split conversion.
- Receiving, PO receiving, batch creation, zone labels, serial capture, scan transactions, receiving history.
- Inventory batch services, FIFO behavior, inventory location scoping, stock-history movement summaries.
- Issuance draft/order creation, quote confirmation, line CRUD, FIFO picking, scan enforcement, cancel/revert/remove operations.
- Transfers: request CRUD, verification, FIFO dispatch, serialized dispatch, receipt/offload, cross-location stock changes.
- Adjustments and inventory permissions through route/permission coverage.
- Product sets and related CRUD, barcode resolution, shortage and incident handling.
- Warranty lookup, claim flows, and location scoping.
- Dashboard and restock scoping and analytics services.
- Role-based access control, navigation bundles, user overrides, auditor restrictions, location-based record scoping.
- Audit/history creation for receiving and related entity changes.
- Real-time broadcast events and authorized private channels.
- PO export calculation utility and receiving total calculations.

## Defects

### QA-001 — Inventory total and FIFO batch balance disagree

**Severity:** High

**Steps to reproduce**

1. Open Inventory for **MAPP Gas** at **CDO Warehouse B**.
2. Observe inventory row 51.
3. Compare `inventory.quantity_on_hand` and `available_quantity` with the sum of `inventory_batches.quantity_remaining`.

**Expected**

The inventory summary and sum of FIFO batches must both equal the stock physically available.

**Actual**

- Quantity on hand: 1 piece
- Available quantity: 1 piece
- FIFO batch remaining: 0 pieces
- Difference: 1 piece

The UI can report stock that FIFO issuance/transfer cannot allocate.

**Suggested fix**

Reconcile the affected row from an auditable source transaction. Do not manufacture a batch without identifying the source. Add a transactional invariant/reconciliation test after every receive, issue, return, transfer, and adjustment. Add an alert or scheduled check for `inventory.quantity_on_hand != SUM(inventory_batches.quantity_remaining)`.

### QA-002 — Stock-transfer permission cannot be configured from navigation access

**Severity:** Medium

**Steps to reproduce**

1. Open the role/navigation permission configuration.
2. Inspect all configurable page features.
3. Search for `stock_transfers`.

**Expected**

Every permission catalog feature is reachable from a page configuration or the “Other permissions” group.

**Actual**

`stock_transfers` exists in the permission catalog but is absent from all navigation configuration mappings. The coverage test fails.

**Suggested fix**

Map `stock_transfers` to `/admin/inventory-operation`, or remove/consolidate it if `inventory_transfers` is the canonical permission. Run the permission/navigation coverage suite after changing the catalog.

### QA-003 — Inventory Warehouse Map regression tests target a removed UI

**Severity:** Low (test-suite defect); Medium if Warehouse Map is still a requirement

**Steps to reproduce**

1. Run `CI=true npm test -- --watchAll=false`.
2. Observe `InventoryManagement.test.jsx`.

**Expected**

Tests match the current Inventory tabs and all required warehouse/batch views remain available.

**Actual**

Three tests expect a **Warehouse Map** tab and `getWarehouseBatches()` calls, but the current page exposes only Stock Movement & Turnover, Inventory, and Activity Log. The suite fails before testing the old behavior.

**Suggested fix**

Confirm the product requirement. Restore the Warehouse Map if it was removed accidentally; otherwise replace these tests with current Inventory/zone/batch interactions.

### QA-004 — Default Create React App test is obsolete

**Severity:** Low

**Steps to reproduce**

1. Run the frontend test suite.
2. Observe `src/App.test.jsx`.

**Expected**

The application shell test verifies a real route, loading state, or login page.

**Actual**

It searches for “Learn React,” which is not part of this application.

**Suggested fix**

Replace it with an application-route smoke test and mock lazy-loaded routes/auth context correctly.

### QA-005 — Test database migration history is inconsistent

**Severity:** Medium

**Steps to reproduce**

1. Set `APP_ENV=testing` and `DB_DATABASE=airkingV5_test`.
2. Run `php artisan migrate --force`.

**Expected**

All pending migrations apply cleanly.

**Actual**

Migration `2026_06_28_120000_add_avatar_and_address_to_users` attempts to add an existing `avatar_path` column. A targeted application of the new issuance reference migration was required before the suite could run successfully.

**Suggested fix**

Rebuild the test database from zero in CI, or make historical schema/migration state consistent. Add a CI job that creates an empty database and runs all migrations before tests.

### QA-006 — Credentialed CORS and WebSocket origins are too broad for public production

**Severity:** High for an internet-facing deployment; Medium for a restricted LAN

**Steps to reproduce**

1. Inspect `config/cors.php`.
2. Inspect the Reverb app configuration in `config/reverb.php`.

**Expected**

Production accepts credentialed API/WebSocket connections only from explicitly approved application origins.

**Actual**

CORS permits broad private-network ranges, any Vercel/Netlify preview subdomain, and Cloudflare tunnel hosts while credentials are enabled. Reverb uses `allowed_origins => ['*']`.

**Suggested fix**

Make origin patterns environment-specific. In production, allow only the deployed frontend origin(s), configure explicit Reverb origins, enable TLS, and set secure cookie attributes.

### QA-007 — Production-scale performance remains unproven

**Severity:** Medium risk

**Steps to reproduce**

1. Review current production row counts.
2. Compare them with expected long-term product, transaction, serial, audit, and movement volumes.

**Expected**

Performance tests represent anticipated peak data volume and concurrency.

**Actual**

The largest relevant domain tables contain only hundreds of rows. No browser E2E/load framework or large-dataset benchmark is configured, so pagination behavior, report generation time, dashboard query latency, lock contention, and PDF memory usage at production scale are not proven.

**Suggested fix**

Add seeded load profiles (for example 100k products/serials, 1m movements/audits), API latency budgets, concurrent receive/issue/transfer tests, and multi-page PDF/export tests. Record p50/p95/p99 latency and database query counts.

## Audit and document findings

- Audit rows have valid timestamps and valid referenced users.
- Fourteen audit rows have no old/new payload; inspection shows these are login/logout events, where entity-change payloads are not applicable.
- Receiving history and verification exports are covered by backend tests.
- PO export and receiving totals are covered by frontend utility tests.
- Issuance, receiving, transfer, and adjustment PDF templates compile successfully.
- Document regeneration routes require authentication/query-token middleware.
- Full pixel-level PDF comparison and physical printer tests were not possible with the current automated tooling; these remain manual acceptance items for A4, Letter, Legal, and A5.

## Security findings

Passed:

- Protected API access without authentication returns 401.
- All business mutation routes are authenticated.
- Permission, auditor read-only, route matrix, override, and location-scope tests pass.
- Login and password-reset endpoints are throttled.
- SQL searches found parameter binding for user-supplied raw predicates in the reviewed barcode/serial paths.
- Production debug mode is disabled.

Risks:

- Broad CORS/Reverb origins as described in QA-006.
- Secure-cookie/TLS production values were not explicitly present in the local configuration.
- A full dynamic penetration test, dependency CVE scan, and external network/TLS scan were outside the available local test setup.

## Release recommendation

**Do not issue an unconditional production approval yet.**

Required before release:

1. Reconcile and explain the MAPP Gas stock/batch discrepancy.
2. Add an automated invariant that prevents summary/batch divergence.
3. Resolve the five frontend test failures and enforce green frontend/backend suites in CI.
4. Repair clean-database migration reproducibility.
5. Restrict CORS and Reverb origins for the actual deployment.

Recommended immediately after:

1. Add Playwright/Cypress browser E2E coverage for role-specific workflows.
2. Add large-data and concurrent stock-operation tests.
3. Add PDF visual snapshots plus multi-page/print acceptance checks.
4. Add scheduled database reconciliation and alerting.

