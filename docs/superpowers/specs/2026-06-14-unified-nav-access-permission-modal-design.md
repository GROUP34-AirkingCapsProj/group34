# Unified Navigation Access + Action Modal — Design

Date: 2026-06-14
Status: Approved (brainstorm) — pending spec review
Area: `frontend/src/admin/pages/UserPermissionOverrides.jsx` (SA permission editor)

## Problem

The User Permission Overrides page has two disconnected tabs:

- **Navigation Access** — per-page on/off buttons (grants/denies a page's required read perm in bulk).
- **Advanced · Full Matrix** — a tri-state (Grant / Deny / Inherit) control for every `feature.action`.

To grant a role/user *access to a page* **and** choose *which actions* they get on it, the admin
must use the Navigation Access tab for the page, then switch to the Advanced tab and hunt for that
feature's actions. The two views are not connected.

## Goal

When the admin configures a navigation item for a user, a **modal** opens showing **only that page's
own feature and its actions**, so the admin picks exactly what the user can do on that page without
leaving the page or searching the full matrix.

Example: admin configures **Purchase Requests** access for a **Warehouse Personnel** user → a modal
opens showing **only** `purchase_request_creation`'s actions (Read / Create / Archive) with the
user's current state, and the admin ticks what that WP user may do.

## Non-goals

- No backend/API changes. `permissionsAPI`, `PermissionController`, `PermissionAdminService`,
  `PermissionResolver`, the catalog, and route enforcement are unchanged.
- The **Advanced** tab is unchanged (full tri-state matrix; still the only place to set an explicit
  *Deny* and to reach features that aren't a navigation item's primary feature).
- No change to **location data scoping** (see Invariant below) — only re-confirmed by a guard test.

## Decisions (from brainstorm)

1. **Modal scope = primary feature only.** Each navigation row maps to its page's single primary
   feature (the bundle's required permission's feature). The modal lists that feature's catalog
   actions only.
2. **Keep a slim Advanced tab** as the fallback for explicit deny and non-navigation features.
3. **Checkbox semantics = "desired effective access" (revised 2026-06-14 to support deny).**
   - Each checkbox represents whether the user should **have** that `feature.action`: ticked = allow,
     unticked = block. It is initialised from the user's current effective (pending-aware) access.
   - On Apply, each action is compared to the user's **role default**:
     - desired **matches** the role default → **inherit** (no override).
     - desired is **true** and role default is false → **grant** override.
     - desired is **false** and role default is true → **deny** override.
   - This lets the admin **deny** an action the role would otherwise allow (e.g. deny view/read),
     not just add access. (Supersedes the original "Option 1 / grant-only, deny in Advanced" decision.)

## Design

### Navigation Access tab (changed)

A list of navigation pages (the active nav bundles). Each row shows:

- Page **name** and **section**.
- A **status badge** summarizing the user's *effective* access to that page's primary feature:
  - `No access` — the primary read action is not effectively granted.
  - `View-only` — read effective, but no write actions effective.
  - `Partial` — read + some (not all) write actions effective.
  - `Full` — all of the feature's actions effective.
  - A **• unsaved** amber marker if the row has pending changes (consistent with existing amber pending styling).
- A **Configure** button (and clicking the row) → opens the action modal.

Bundles whose primary feature has only a `create` action (e.g. Scan Barcode →
`barcode_inventory_processing.create`) still work — the modal simply lists that one action.
Two pages sharing a feature (e.g. Approval Queue and Purchase Orders both → `purchase_order_creation`)
each open a modal for the same feature; edits made in one reflect in the other after save (same
underlying permission).

### Action modal (new)

Title: the page name. Body: one checkbox row per action of the page's primary feature
(`read, create, update, archive, approve` — only those that exist in the catalog for that feature),
in a stable order. Each row's initial/displayed state:

- **Granted by the user's role default** → checkbox **checked and disabled**, with a `(via role)`
  label. Rationale: Option 1 cannot *remove* a role default; showing it editable would let the box
  lie. To block a role-granted action, the admin uses Advanced (explicit deny).
- **Not granted by role default** →
  - checkbox **checked** if the user currently has a **grant** override for it (or a pending grant),
  - else **unchecked**.
  - Editable: ticking stages a `grant`; unticking stages `inherit` (clear).
- **Currently denied by an explicit override** (set via Advanced) → shown unchecked with a small
  `(denied in Advanced)` hint; ticking it here stages a `grant` (which would replace the deny on save).

Footer note: *"Ticking grants this user the action on top of their role. To block an action the role
normally allows, use the Advanced tab."*

Buttons: **Apply** (stages the modal's checkbox changes into the page `pending` map, closes the modal)
and **Cancel** (discard the modal's edits, close).

### Data flow (reuses existing machinery)

- The page already holds `pending` (`{permission_id: 'grant'|'deny'|'inherit'}`), `reason`, `userPerms`
  (`{effective, overrides}`), and `catalog`. The modal reads `catalog` (to map the feature's actions →
  permission_ids), `userPerms.overrides`/`effective` (initial state), and the role default.
- **Apply** writes only `grant`/`inherit` entries into `pending` (never `deny`).
- The page keeps the bottom **Reason** (required) + **Save** + **Discard** + pending count. **Save**
  commits all pending across modals in one `permissionsAPI.updateUserPermissions(userId, reason, changes)`
  call (unchanged), where `is_granted = state==='inherit' ? null : state==='grant'`.
- After Save, `loadUserPerms` refreshes; badges + modal states reflect the new reality.

### Deriving the role default for an action

The modal needs to know if an action is granted by the user's **role default** (to lock it on).
`userPerms.effective` is role-default overlaid with overrides, so it is not the pure role default
when an override exists. To get the true role baseline without a backend change, the page will fetch
the role defaults once via the existing `permissionsAPI.getRoleDefaults()` (already used by the Role
Permissions page) and index them by the selected user's `role_id`. (Small, read-only addition to the
page's existing load; no API change.)

### Components / files

- `frontend/src/admin/pages/UserPermissionOverrides.jsx` — replace the Simple-tab bundle-button list
  with the row-list + Configure; add the action modal (reuse existing `Modal` component); load role
  defaults; keep Advanced tab and reason/save as-is.
- `frontend/src/admin/styles/rbac_console.css` — add modal/row/badge styles in the existing
  `.rbac-console` namespace (consistent with the console theme).
- No other files.

## Invariant — location data scope (re-confirmed, not changed)

Permissions (capabilities) and location data scope are **separate systems**:

- **Who sees all locations' data:** `User::isCentralized()` → role_id ∈ {admin=1, inventory_analyst=2,
  auditor=5}. `scopedLocationId()` returns null for these; for branch_manager(3)/warehouse_personnel(4)
  it returns the user's own `location_id`. Read scoping is enforced server-side via
  `forUser($user)` / `location.scope`.
- **This is role-based, never permission-based.** Granting a navigation/action permission to a WP/BM
  user (via this modal or the editor) gives them the *capability* but does **not** widen their data
  scope — they still only see their own location's records.

Guardrail: add/confirm a test asserting that a location-scoped user (e.g. WP) granted extra
permissions still only sees their own location's data (the existing
`tests/Feature/Permissions/{InventoryScopeTest,DashboardScopeTest,LocationScopeTest}` cover scope;
add one case that grants the scoped user a permission override and re-asserts the scope holds).

## Edge cases

- Admin-role target user: still blocked (existing guard) — page shows the "cannot override admin" notice.
- A feature with a single action: modal shows one checkbox.
- All actions role-granted: every checkbox checked+disabled; modal is informational (nothing to stage).
- Cancel after edits: pending for that page is not changed.
- Switching users: pending + reason reset (existing behavior); modals re-derive from the new user.

## Testing

- Frontend build compiles (`CI=false npm run build` → exit 0).
- Existing frontend tests still pass; update/replace any Simple-tab test that referenced the old
  bundle buttons.
- Backend: full suite stays green (no backend change); the scope guardrail test (above) passes.
- Manual: grant WP the Purchase Requests actions via the modal → Save → WP sees the Create tab; and
  confirm WP still sees only their location's data.
