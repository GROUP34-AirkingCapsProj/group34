# User Management Panel — Shared Component Design

**Date:** 2026-06-29
**Status:** Approved

## Problem

`SystemConfiguration.jsx` (the `/admin/config` page) has a **User Management** tab
whose first card, titled **"User Accounts"**, is a basic user table with role/location
filters and **non-functional Edit / Deactivate buttons** (no click handlers). It is a
broken, duplicated stub of the real, full-featured **User Management** screen
(`UserManagement.jsx`) reachable from the sidebar at `/admin/users`.

We want the System Config tab to host the *real* working User Management UI instead of
the dead stub, without duplicating code.

## Goal

Replace only the broken "User Accounts" table card inside the System Config
**User Management** tab with the full, working User Management UI. The three
password-reset cards below it (Password Reset Requests, Active Reset Codes, Manual
Password Reset) stay unchanged. The sidebar User Management page keeps working and
looking the same.

## Approach

Extract the entire User Management screen into a single shared component and render it
in both hosts — one source of truth, no duplication.

### 1. New component: `frontend/src/admin/components/UserManagementPanel.jsx`

Move everything currently in `UserManagement.jsx` into this component **except** the
outer `<AdminLayout>` wrapper and the page header (`ak-header` with eyebrow/title/sub).

The panel owns all state, data fetching, and handlers:
- fetch users (paginated) + roles + locations
- search, role/location/status filters (debounced, server-side pagination)
- add / edit (modal), activate-deactivate, archive
- pagination
- the Add/Edit `Modal`

The panel's own style imports move with it: `dashboard_air.css`, `user_management.css`,
`archive_management.css`.

Render order, top to bottom:
1. toolbar row with **+ Add New User** button (right-aligned)
2. 4 stats cards (Total / Active / Inactive / Locations)
3. search + filter bar
4. table with working Edit / Activate-Deactivate / Archive
5. pagination
6. Add/Edit modal

The panel renders **no** `AdminLayout` and **no** `ak-header` page title — only the host
provides page chrome.

### 2. `UserManagement.jsx` becomes a thin wrapper

```
AdminLayout
  └ div.ak-page
      ├ header.ak-header  (keeps "User Management" eyebrow/title/subtitle)
      └ <UserManagementPanel />
```

The **+ Add New User** button moves out of `ak-header-aside` into the panel toolbar, so
it appears once and behaves identically in both hosts. The sidebar page looks the same
to the user.

### 3. `SystemConfiguration.jsx` tab swap

In the **User Management** tab (`activeTab === 'users'`), replace the broken
"User Accounts" `sc-card` (table + its role/location filter row) with
`<UserManagementPanel />`. Keep the three password-reset cards exactly as they are.

Cleanup that follows from removing the old table:
- Delete now-unused state/derived values that only fed the old table: `userRoleFilter`,
  `userLocFilter`, `filteredUsers`, `roleOptions`, `locationOptions`.
- **Keep** the existing `users` fetch — the Manual Password Reset "Select User" dropdown
  still depends on it. (Roles/locations fetch may be trimmed if nothing else uses them.)
- Hide the footer Save/Reset bar on the **User Management** tab. Its
  "Save User Settings" button is a fake no-op toast; the panel saves through its own
  modal, so the tab has no tab-level settings to save.

## Out of scope

- No API changes. The panel uses the same `usersAPI` / `batchAPI` / `authAPI` calls.
- No change to the separate **User Accounts** tab (account-editing policy toggles).
- No visual redesign — same components, same styles.

## Risks / notes

- The panel fetches users independently of System Config's own `users` fetch (used by
  Manual Password Reset). This is a small, acceptable redundancy; both are self-contained.
- Class namespaces differ (`um-*` panel vs `sc-*` tab), so embedding the panel inside
  `sc-tab-content` should not cause style collisions.
