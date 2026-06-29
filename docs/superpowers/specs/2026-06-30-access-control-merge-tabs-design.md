# Merge Role Permissions + User Overrides into one tabbed page

**Date:** 2026-06-30
**Status:** Approved (design)
**Branch:** backupV3

## Summary

Remove the standalone **User Overrides** sidebar item and merge the Role Permissions
page and the User Permission Overrides page into a single page with two top-level tabs:
**Role Permissions** and **User Overrides**. The active tab is driven by the URL, so both
existing routes keep working and each tab stays deep-linkable.

## Background

Two separate admin pages exist today, both admin-only, both styled with `rbac_console.css`:

- `frontend/src/admin/pages/RolePermissions.jsx` — route `/admin/roles`. Configures default
  permission grants per role. Has its own internal sub-tabs: "Navigation Access" and
  "Advanced · Full Matrix", a Save button + role legend in its header, and a per-page modal.
- `frontend/src/admin/pages/UserPermissionOverrides.jsx` — route `/admin/user-permissions`.
  Grants/denies individual permissions per user (tri-state inherit/grant/deny). Also has the
  same internal sub-tabs, a user picker, a reason field, and a per-page modal.

Each page wraps itself in `<AdminLayout>` and renders a `<div className="rbac-console">` root.
The `/admin/user-permissions` route is referenced only by the sidebar nav item and `roles.js`
route access — nothing deep-links to it elsewhere.

## Goals

1. Remove the "User Overrides" sidebar item; keep "Role & Permissions".
2. Present both views as top-level tabs within one page.
3. Keep both URLs working: `/admin/roles` opens the Role Permissions tab,
   `/admin/user-permissions` opens the User Overrides tab. URL is the source of truth for the
   active tab; switching tabs navigates between the two paths.
4. Preserve each panel's existing behavior, including its own inner sub-tabs (two tab levels).

## Non-goals

- No change to permission semantics, the RBAC catalog, backend routes, or `roles.js` access
  rules (both paths remain admin-only).
- No flattening or merging of the panels' existing inner "Navigation Access / Advanced" tabs.
- No change to the Permission Audit page (`/admin/permission-audit`).

## Architecture

A new thin parent page **`AccessControlPage`** owns the single `AdminLayout` and a top-level
tab bar. The active tab is derived from `useLocation().pathname` (not internal state):

- `/admin/roles` → Role Permissions tab active → renders the Role Permissions panel.
- `/admin/user-permissions` → User Overrides tab active → renders the User Overrides panel.

Tab clicks call `useNavigate()` to the corresponding path; React Router re-renders the parent,
which recomputes the active tab from the new pathname. No internal `activeTab` state is needed.

Visual hierarchy (two tab levels):

```
┌ Access Control ─────────────────────────────────┐
│ [ Role Permissions ]  [ User Overrides ]        │  ← new page-level tabs (.rcx-toptabs)
│ ───────────────────────────────────────────────│
│  Navigation Access | Advanced · Full Matrix     │  ← each panel's existing inner sub-tabs
│  …panel content…                                │
└─────────────────────────────────────────────────┘
```

## Components / files

- **New `frontend/src/admin/pages/AccessControlPage.jsx`** — renders `AdminLayout` → top tab
  bar (`.rcx-toptabs`) → the active panel. Imports `RolePermissions` and
  `UserPermissionOverrides` as child panels. Derives active tab from the current pathname;
  tab buttons navigate to `/admin/roles` or `/admin/user-permissions`.
- **Modify `frontend/src/admin/pages/RolePermissions.jsx`** — remove its `<AdminLayout>`
  wrapper; the component now returns its `<div className="rbac-console">…</div>` root directly.
  All internals unchanged (sub-tabs, Save button, legend, modal).
- **Modify `frontend/src/admin/pages/UserPermissionOverrides.jsx`** — same: drop the
  `<AdminLayout>` wrapper, keep all internals (user picker, sub-tabs, reason field, modal).
- **Modify `frontend/src/App.jsx`** — both `/admin/roles` and `/admin/user-permissions` render
  `<RoleRoute><AccessControlPage/></RoleRoute>`. Lazy-import `AccessControlPage`; the two old
  lazy imports are used by `AccessControlPage` instead of the router. (Keep
  `UserPermissionOverrides` lazy import wherever `AccessControlPage` references it.)
- **Modify `frontend/src/admin/components/Sidebar.jsx`** — remove the
  `{ name: 'User Overrides', path: '/admin/user-permissions', icon: 'roles' }` item from
  `adminMenuSections`. Keep `Role & Permissions`. Update the `routePrefetchMap` `/admin/roles`
  entry to import `AccessControlPage` (and remove the now-unused `/admin/user-permissions`
  prefetch entry if present — it is not currently present).
- **Modify `frontend/src/admin/styles/rbac_console.css`** — add a `.rcx-toptabs` style for the
  page-level tabs, visually heavier/segmented so the two tab levels read as a clear hierarchy.
- **No change** to `frontend/src/admin/utils/roles.js` — `/admin/roles` and
  `/admin/user-permissions` are already admin-only and stay routed through `RoleRoute`.

## Data flow

`AccessControlPage` holds no data of its own. Each panel continues to fetch and manage its own
state (catalog, role defaults, user permissions) exactly as today. Switching tabs unmounts one
panel and mounts the other, so each panel re-fetches on mount — acceptable and matches current
per-page behavior.

## Error handling

No new error paths. Each panel keeps its own loading/error states. Route access is unchanged
(admin-only via `RoleRoute`); a non-admin hitting either path is redirected to the dashboard as
today.

## Testing

Frontend uses Jest + Testing Library (see `RoleRoute.test.jsx`).

Add `frontend/src/admin/pages/AccessControlPage.test.jsx` that **mocks the two panel
components** (each renders a simple marker) and mocks `react-router-dom`'s `useNavigate`, then
asserts:

1. At `/admin/roles`: the Role Permissions tab is active and the Role Permissions panel marker
   renders (Overrides marker absent).
2. At `/admin/user-permissions`: the User Overrides tab is active and the Overrides panel
   marker renders (Role Permissions marker absent).
3. Clicking the inactive tab calls `navigate` with the other path.

Mocking the panels keeps the test focused on the new tab/route wiring without pulling in the
panels' API calls.

Manual verification:
- Sidebar shows "Role & Permissions" but no "User Overrides".
- `/admin/roles` loads on the Role Permissions tab; `/admin/user-permissions` loads on the
  User Overrides tab.
- Clicking each top tab switches panels and updates the URL accordingly.
- Each panel's inner sub-tabs, Save, user picker, and modals still work.
- A non-admin is redirected to the dashboard from both paths.
