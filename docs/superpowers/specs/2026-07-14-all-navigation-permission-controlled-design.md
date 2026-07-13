# Every navigation item permission-controlled

**Date:** 2026-07-14
**Area:** backend (seeders, routes, middleware) + frontend (RolePermissions, navBundles, sidebar)
**Depends on:** 2026-07-14-nav-configure-fold-matrix-design.md

## Problem

The Navigation Access list is driven by the 15 DB nav bundles, but the sidebar has ~25
items. Items with no bundle (Budget Management, PO Recommendation, Analytics, Inventory
Monitoring, Customer Registry, Stock Transfers, Dashboard, Role & Permissions, Permission
Audit, System Configuration) never appear in the permission feature and are gated by static
role lists in `roles.js`/`ROUTE_ACCESS` instead of toggleable permissions.

Also a regression from the prior change: dropping the Advanced matrix tab made three real
catalog features unreachable in the UI — `budget_verification`, `po_recommendations`,
`purchase_order_verification`.

## Goal

Show **every** navigation item in the Role & Permissions list and let admin grant/revoke
each per role, enforced both in the UI (sidebar + route guard) and on the backend API
(403 when revoked). Admin can never be locked out.

## Decisions (confirmed)

- **Full backend enforcement** (not just UI gating).
- **Security/admin pages are toggleable** too (Role & Permissions, Permission Audit,
  System Configuration) — made safe by an admin bypass.
- **Dashboard** → revocable `dashboard_access` (default granted to all roles).
- All items including legacy **Stock Transfers** are covered.

## 1. Admin safety guardrail (Phase 1)

`PermissionResolver::canUser()` returns `true` immediately when the user's role is admin
(role_id 1 / slug `admin`), before checking overrides/role_permissions. Structurally
guarantees admin retains access to every page regardless of toggles — the precondition for
making security pages toggleable. Covered by a middleware/resolver test.

## 2. Permissions + grants (Phase 2)

Add to `PermissionSeeder::matrix()` (grants mirror today's `ROUTE_ACCESS`); `RolePermissions
Seeder` picks these up automatically:

| Feature | Actions | Granted roles |
|---|---|---|
| `dashboard_access` | read | all |
| `analytics_dashboard` | read | admin, inventory_analyst, branch_manager, auditor |
| `inventory_monitoring` | read | all |
| `customer_management` | read, create, update, archive | admin, branch_manager, warehouse_personnel, auditor (archive: admin) |
| `stock_transfers` | read | admin |
| `role_assignment` | **add read** | admin |
| `permission_audit` | read | admin |
| `system_configuration` | read, update | admin |

Existing `budget_verification`, `po_recommendations`, `purchase_order_verification` stay as
is — they just gain bundle homes.

## 3. Nav bundles (Phase 2, `NavBundleSeeder`)

Add a bundle (route_path, sidebar_section, icon, required feature+action) for every missing
sidebar item so all appear grouped under their real sections:

- ADMINISTRATION: Dashboard (`dashboard_access.read`), Analytics Dashboard
  (`analytics_dashboard.read`), Role & Permissions (`role_assignment.read`), Permission
  Audit (`permission_audit.read`), System Configuration (`system_configuration.read`).
- PROCUREMENT & PLANNING: Budget Management (`budget_verification.read`), PO Recommendation
  (`po_recommendations.read`).
- INVENTORY INTELLIGENCE: Inventory Monitoring (`inventory_monitoring.read`), Stock
  Transfers (`stock_transfers.read`).
- SALES & LOGISTICS: Customer Registry (`customer_management.read`).

Secondary (extra) features stay a frontend concern via `navConfigureMap` (e.g. System
Configuration hosts `user_account_management`; Purchase Orders hosts
`purchase_order_verification`).

## 4. Backend enforcement (Phase 3, `routes/api.php`)

Convert the read/list gates for the new routes from `role:` to `permission:feature,read`
(and add write gates where a controller action maps cleanly). Admin immune via bypass.
Per-route feature test: granted role → 200, revoked → 403, admin always 200.

## 5. Frontend (Phase 4)

- `navBundles.js` mirror + `RolePermissions` list include all new bundles; admin column
  stays locked-full.
- `sidebarBuilder`/`RoleRoute` become bundle-driven for these routes; remove now-redundant
  `ROUTE_ACCESS`/`adminOnly` entries for covered paths.
- Update `FEATURE_GROUPS` to include every new + previously-missing feature.
- `navConfigureMap`: update `EXTRA_FEATURES_BY_ROUTE`/`ORPHAN_FEATURES` for the new homes;
  remove the now-homed orphans (role_assignment → Roles page; user_account_management →
  System Configuration page).

## 6. Coverage test (Phase 2)

Add a **backend** test asserting every `permissions` feature is reachable from some nav
bundle (required or, via a small allow-list, an intentional non-nav feature) — checked
against the live catalog. Fix the frontend `navConfigureMap` completeness test to stop
relying on a hardcoded feature universe.

## Phasing

1. Admin bypass + test.
2. Seeders (permissions, grants, bundles) + coverage test.
3. Backend route enforcement + per-route tests.
4. Frontend list/sidebar/RoleRoute/FEATURE_GROUPS/navConfigureMap.

## Out of scope

- Redesigning dashboards or the pages themselves.
- Location read-scoping changes (unrelated).
