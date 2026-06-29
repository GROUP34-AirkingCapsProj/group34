# Warranty Claim: move sidebar entry to an in-records button

**Date:** 2026-06-29
**Status:** Approved (design)
**Branch:** backupV3

## Summary

Remove the standalone **"Warranty Claims"** item from the sidebar. Users reach the
warranty-claim flow only through a **"Claim Warranty"** button on the Warranty Records
page, which already navigates to `/admin/warranty-claims?serial=…`. The route and its
permission gate are unchanged — only the sidebar shortcut is removed and the in-records
buttons are relabeled for clarity.

## Background

The Warranty Records page (`frontend/src/admin/pages/WarrantyManagement.jsx`) already
navigates to the claim page from three places (all via `openClaimModal`, which despite its
name performs `window.location.href = '/admin/warranty-claims?serial=…'`):

- The per-row **"Claim"** button on Active warranties (status_id 33).
- The serial-lookup result's **"Mark as Claimed"** button.
- The View modal's **"Mark as Claimed"** button.

The sidebar exposes the claim page in three coordinated places:

- `frontend/src/admin/components/Sidebar.jsx` — static `adminMenuSections` (COMPLIANCE & SUPPORT).
- `frontend/src/admin/utils/navBundles.js` — `NAV_BUNDLES` frontend mirror.
- `back-end/database/seeders/NavBundleSeeder.php` — DB-seeded `nav_permission_bundles` row.

Because `openClaimModal` was changed to navigate, the in-page "Mark Warranty as Claimed"
notes modal and its handlers are now unreachable dead code (`claimTarget` is never set to a
truthy value).

## Goals

1. Remove "Warranty Claims" from the sidebar everywhere (static list, frontend mirror, DB seeder + existing DB row).
2. Relabel all three navigation buttons on the records page to **"Claim Warranty"**.
3. Delete the dead claim-notes modal and its handlers.
4. Keep the route `/admin/warranty-claims` and its `warranty_claims` permission gate intact.

## Non-goals

- No change to `WarrantyClaimPage.jsx` (it already reads `?serial=` from the URL).
- No change to the `warranty_claims` permission, its backend routes (`back-end/routes/api.php`),
  `PermissionSeeder`, or RBAC tests.
- No removal of the backend `markClaimed` endpoint/controller (`WarrantyController`) — only
  the dead frontend usage is removed. See note below.

## Changes

### 1. Relabel navigation buttons — `WarrantyManagement.jsx`

- Per-row button (currently `"Claim"`, ~line 393) → **"Claim Warranty"**.
- Serial-lookup result button (currently `"Mark as Claimed"`, ~line 283) → **"Claim Warranty"**.
- View modal button (currently `"Mark as Claimed"`, ~line 507) → **"Claim Warranty"**.

These all already call `openClaimModal(...)`, which navigates to the claim page. The label
"Mark as Claimed" is misleading post-redesign (the buttons navigate; they no longer mark in
place), so the consistent label is "Claim Warranty".

### 2. Remove the sidebar entry (all three places)

- `Sidebar.jsx` — delete the `{ name: 'Warranty Claims', path: '/admin/warranty-claims', icon: 'warranty' }`
  item from `adminMenuSections` (COMPLIANCE & SUPPORT section).
- `navBundles.js` — delete the `Warranty Claims` entry from `NAV_BUNDLES`.
- `NavBundleSeeder.php` — delete the `['Warranty Claims', '/admin/warranty-claims', …]` row.

**DB cleanup (required):** `NavBundleSeeder` only upserts (`updateOrCreate`) and never deletes
removed bundles, so the row already present in the dev DB will persist after re-seed. A one-off
cleanup must remove the existing `nav_permission_bundles` row for `/admin/warranty-claims`
(and its `nav_bundle_permissions` link) from the dev DB — via tinker or SQL. The plan will
specify the exact command.

### 3. Remove dead claim-notes modal — `WarrantyManagement.jsx`

Remove the now-unreachable:

- The "Mark Warranty as Claimed" `<Modal>` block (~lines 516–545).
- `submitClaim` function and its `warrantiesAPI.markClaimed(...)` call.
- The associated state: `claimTarget`/`setClaimTarget`, `claimNotes`/`setClaimNotes`,
  `claimError`/`setClaimError`, `claiming`/`setClaiming`.

**Note on `warrantiesAPI.markClaimed`:** after the modal is removed, the frontend service
method `markClaimed` in `frontend/src/admin/services/api.js` becomes unused (its only frontend
caller is this modal). Leave the service method and the backend endpoint in place for now to
keep this change minimal and avoid touching backend behavior; the unused one-line service
method is harmless. Flag it for a future cleanup pass if desired.

## Explicitly unchanged

- Route `/admin/warranty-claims` stays, wrapped in `RoleRoute`, gated by the `warranty_claims`
  permission.
- `warranty_claims` permission, backend routes, `PermissionSeeder`, RBAC route-matrix tests.
- `WarrantyClaimPage.jsx`.

## Testing / verification

- **Automated:** No test asserts the nav-bundle count, so removing one bundle is safe for the
  suite. RBAC route-matrix tests for `warranty-claims` still pass (route unchanged). Run the
  relevant feature tests to confirm green.
- **Manual:**
  1. "Warranty Claims" no longer appears in the sidebar (after frontend rebuild + DB cleanup).
  2. On an Active warranty record, "Claim Warranty" navigates to the claim page with the serial prefilled.
  3. Serial-lookup result and View modal "Claim Warranty" buttons navigate correctly.
  4. A user with `warranty_records` read but no `warranty_claims` permission is still blocked at the route.
