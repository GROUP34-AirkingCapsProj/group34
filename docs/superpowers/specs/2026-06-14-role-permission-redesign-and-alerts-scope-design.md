# Role Permissions Redesign + Reports/Alerts Permissions + Location-Scoped Alerts — Design

Date: 2026-06-14
Status: Approved (brainstorm) — pending spec review
Areas: `frontend/src/admin/pages/RolePermissions.jsx`, RBAC catalog/seeders/nav-bundles, `back-end/app/Http/Controllers/Api/AlertSettingsController.php`

## Problem

1. **Role Permissions page** is one long scrollable matrix (all features × 5 roles) — confusing and
   slow to navigate. It should use the same navigation-list + per-page modal UX as User Overrides.
2. **System Reports** (`/admin/reports`) and **Alerts & Notices** (`/admin/alerts`) have **no
   permission** and no nav bundle, so they can't be granted/denied per role or user. (Audit Trail
   already has `audit_trail_viewing` granted to admin/branch_manager/auditor — confirmed correct,
   no change.)
3. **Alerts are global** — the bell/feed counts are not location-scoped, so a Branch Manager /
   Warehouse user would see other locations' alerts.

## Goals

- Redesign Role Permissions to a nav-list + per-page modal (per-page mini-matrix across roles),
  with an Advanced tab kept for non-navigation features.
- Add `report_viewing` and `notification_panel` permissions, wired to nav bundles, so both editors
  manage them and they gate page access.
- Scope every alert component to the user's assigned location for non-centralized roles.

## Non-goals

- No change to the resolver / `CheckPermission` / `PermissionController` / `PermissionAdminService`.
- No change to Audit Trail (already correct) or the User Overrides page.
- The raw `/api/reports/*` and `/api/alerts/*` data endpoints stay open (per the standing "leave
  open GETs open" decision); the new permissions enforce at sidebar + page-guard level. The alerts
  *counts* returned by `/api/alerts/settings` ARE scoped (Part C) regardless.

---

## Part A — Role Permissions redesign

`RolePermissions.jsx` becomes two tabs (mirrors `UserPermissionOverrides.jsx`):

- **Navigation Access** (default tab): a nav-list of pages built from the nav bundles
  (`navBundlesAPI.list()`, newly loaded by this page). Each row: page name, section, **Configure**
  button. Configure opens a **modal** = a per-page mini-matrix for that page's **primary feature**:
  one row per action (`actionsForFeature(catalog, feature)`), columns = the 5 roles
  (`ROLE_COLUMNS`), cells = checkboxes.
  - The **admin (role_id 1) column is locked**: checkbox checked + disabled (admin always full).
  - Toggling a non-admin cell stages into the existing `pending[roleId][permId] = bool` diff
    (reuse the page's current toggle/diff logic). **Apply** closes the modal.
- **Advanced** tab: the **existing full matrix**, unchanged — keeps non-navigation features (UoM,
  role_assignment, barcode_assignment, item_supplier_mapping, brand_management,
  delivery_receipt_generation, inventory_adjustments, purchase_request_approval, data_archiving,
  the two new ones, etc.) reachable.
- Reuse the existing `catalog` (`permissionsAPI.catalog()`), `granted`/`pending` state, and
  **Save** (`permissionsAPI.updateRoleDefaults(changes)` → `[{role_id, permission_id, is_granted}]`).
  No reason field (role defaults need none). The admin-role edit guard (backend already 403s admin
  changes; frontend keeps the admin column disabled) is preserved.
- Reuse the shared helpers `actionsForFeature` / `pageAccessStatus` and the `Modal`, `rbac_console`
  styles already added for User Overrides. A small status badge per row (optional) may show whether
  any non-admin role has access; if cheap, include it, else omit.

Pending-diff key reminder: Role Permissions already keys pending by `roleId` then `permId`
(`pending[roleId][permId]`), distinct from User Overrides (`pending[permId]`). Keep that shape.

---

## Part B — `report_viewing` + `notification_panel` permissions

### Catalog (`back-end/database/seeders/PermissionSeeder.php` matrix)

Add:

```
report_viewing:     read => [admin, inventory_analyst, auditor]
notification_panel: read => [admin, inventory_analyst, branch_manager, warehouse_personnel, auditor]
```

(Single `read` action each — both pages are view-only.)

### Nav bundles (`back-end/database/seeders/NavBundleSeeder.php`)

Add two bundles (count 18 → 20):

```
['System Reports',    '/admin/reports', 'COMPLIANCE & SUPPORT', 'reports',        'report_viewing',     'read']
['Alerts & Notices',  '/admin/alerts',  'COMPLIANCE & SUPPORT', 'notifications',  'notification_panel', 'read']
```

Update `NavBundleTest`'s expected count to **20**.

### Frontend

- `frontend/src/admin/utils/navBundles.js` — add the two bundles to the `NAV_BUNDLES` mirror:
  `{ name:'System Reports', routePath:'/admin/reports', required:['report_viewing.read'] }`,
  `{ name:'Alerts & Notices', routePath:'/admin/alerts', required:['notification_panel.read'] }`.
- `frontend/src/admin/utils/permissions.js` — add `report_viewing` and `notification_panel` to
  `FEATURE_GROUPS` (new group `IX. Reporting & Notifications`) so they appear grouped in the Advanced
  matrix of both editors.
- The Sidebar already lists "System Reports" and "Alerts & Notices"; once the bundles exist, the
  permission-driven `sidebarBuilder` + `RoleRoute` gate them automatically (they leave the
  role-fallback path). No Sidebar/App.jsx change required.

### Enforcement

- Sidebar visibility + `RoleRoute` page guard via the new `read` perms.
- Default grants seeded as above (reports = admin/IA/AU; alerts = all 5).
- Re-seed dev + clone to test DB (per the established test-DB procedure).

---

## Part C — Location-scoped alerts

In `back-end/app/Http/Controllers/Api/AlertSettingsController@index` (and any other method building
the alert feed/counts), scope **every** component to the requesting user's location when the user is
**not centralized**:

- Compute `$scoped = ! $user->isCentralized();` and `$locId = $user->scopedLocationId();`.
- When `$scoped` is true, add a location filter to each source (the columns confirmed present):
  - low-stock / out-of-stock → `inventory.location_id = $locId`
  - pending PO count + overdue PO count → `purchase_orders.location_id = $locId`
  - pending PR count → `purchase_requests.location_id = $locId`
  - pending product registration → `pending_products.opening_location_id = $locId`
- When the user is centralized (admin/IA/auditor), no location filter (all locations) — current
  behavior.
- Edge: a scoped user with `scopedLocationId() === null` (unassigned) sees zero alerts (filter on a
  null location yields no rows) — acceptable and consistent with the app's "unassigned → deny" rule.

The `AlertsNotices` page consumes this endpoint, so scoping the controller scopes the page too. No
frontend change needed for Part C beyond what Part B adds.

---

## Components / files

- `frontend/src/admin/pages/RolePermissions.jsx` — two-tab restructure + nav-list + modal (Part A).
- `back-end/database/seeders/PermissionSeeder.php` — +2 features (Part B).
- `back-end/database/seeders/NavBundleSeeder.php` — +2 bundles (Part B).
- `back-end/tests/Feature/Permissions/NavBundleTest.php` — expected count 18 → 20 (Part B).
- `frontend/src/admin/utils/navBundles.js` — +2 mirror bundles (Part B).
- `frontend/src/admin/utils/permissions.js` — +2 features in FEATURE_GROUPS (Part B).
- `back-end/app/Http/Controllers/Api/AlertSettingsController.php` — location scoping (Part C).
- `back-end/tests/Feature/Permissions/AlertScopeTest.php` (new) — Part C guard test.

## Testing

- **Part A:** frontend build green; manual — Role Permissions shows Navigation Access (per-page
  modal, SA column locked) + Advanced; toggling a non-admin cell + Save persists (reload reflects it).
- **Part B:** `report_viewing.read` / `notification_panel.read` exist in the catalog with the seeded
  grants; both appear in both editors; a role lacking `report_viewing.read` does not see System
  Reports in the sidebar and is redirected from `/admin/reports`. `NavBundleTest` expects 20.
- **Part C (`AlertScopeTest`, DatabaseTransactions):** create 2 locations, inventory rows below
  reorder at both, a pending PO/PR at each; a BM user at location A. Assert the `/api/alerts/settings`
  badge/low-stock for the BM reflects **only** location A; an admin sees both. Assert a centralized
  user is unfiltered.
- Full backend suite green; `CI=false npm run build` exit 0.
