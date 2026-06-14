# Unified Navigation Access + Action Modal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the SA "User Permission Overrides" → Navigation Access tab a per-page action editor: each navigation row opens a modal showing only that page's primary feature's actions, where ticking grants the action on top of the user's role (unticking = inherit). The Advanced full-matrix tab stays as the deny/fallback. Confirm permission grants never widen a location-scoped user's data scope.

**Architecture:** Pure, unit-tested derivation helpers in `permissions.js` (`actionsForFeature`, `pageAccessStatus`); the page component composes them to render navigation rows + a `Modal`-based action picker that stages edits into the existing `pending` map; the existing bottom Reason+Save commits via the unchanged `permissionsAPI.updateUserPermissions`. A backend test asserts a granted override does not change `isCentralized()`/`scopedLocationId()`.

**Tech Stack:** React (CRA), existing `Modal`/`toast` components, `rbac_console.css` scoped theme; Laravel/PHPUnit for the backend guard test.

Spec: `docs/superpowers/specs/2026-06-14-unified-nav-access-permission-modal-design.md`

---

## File Structure

- Modify `frontend/src/admin/utils/permissions.js` — add pure helpers `actionsForFeature`, `pageAccessStatus`.
- Create `frontend/src/admin/utils/permissions.test.js` — jest unit tests for the two helpers.
- Modify `frontend/src/admin/styles/rbac_console.css` — add nav-row / status-badge / modal-action styles (in `.rbac-console` namespace).
- Modify `frontend/src/admin/pages/UserPermissionOverrides.jsx` — replace the Navigation Access tab's bulk on/off table with a row list + action modal; keep Advanced tab + Reason/Save.
- Create `back-end/tests/Feature/Permissions/ScopeWithOverrideTest.php` — location-scope guard test.

Conventions: `git` runs from repo root `C:/XAMPP1/htdocs/Airking_Inventory`. Frontend commands run in `frontend/`; backend in `back-end/`. Backend tests use `DatabaseTransactions` against the pre-seeded `airkingV5_test` DB. Commit messages end with the `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>` line.

---

## Task 1: Pure derivation helpers in permissions.js

**Files:**
- Modify: `frontend/src/admin/utils/permissions.js`
- Test: `frontend/src/admin/utils/permissions.test.js` (create)

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/utils/permissions.test.js`:

```js
import { actionsForFeature, pageAccessStatus } from './permissions';

const catalog = [
  { permission_id: 10, feature: 'item_master_management', action: 'create' },
  { permission_id: 11, feature: 'item_master_management', action: 'read' },
  { permission_id: 12, feature: 'item_master_management', action: 'update' },
  { permission_id: 13, feature: 'item_master_management', action: 'archive' },
  { permission_id: 14, feature: 'item_master_management', action: 'approve' },
  { permission_id: 20, feature: 'barcode_inventory_processing', action: 'create' },
];

test('actionsForFeature returns the feature actions in read-first display order', () => {
  expect(actionsForFeature(catalog, 'item_master_management')).toEqual([
    { action: 'read', permission_id: 11 },
    { action: 'create', permission_id: 10 },
    { action: 'update', permission_id: 12 },
    { action: 'archive', permission_id: 13 },
    { action: 'approve', permission_id: 14 },
  ]);
});

test('actionsForFeature handles a single-action feature', () => {
  expect(actionsForFeature(catalog, 'barcode_inventory_processing')).toEqual([
    { action: 'create', permission_id: 20 },
  ]);
});

test('actionsForFeature returns [] for unknown feature or empty catalog', () => {
  expect(actionsForFeature(catalog, 'nope')).toEqual([]);
  expect(actionsForFeature(null, 'item_master_management')).toEqual([]);
});

describe('pageAccessStatus', () => {
  const acts = actionsForFeature(catalog, 'item_master_management'); // read + 4 writes

  test("'none' when the access (read) action is not effective", () => {
    expect(pageAccessStatus(acts, { read: false, create: true })).toBe('none');
  });
  test("'view' when only read is effective", () => {
    expect(pageAccessStatus(acts, { read: true })).toBe('view');
  });
  test("'partial' when read + some writes are effective", () => {
    expect(pageAccessStatus(acts, { read: true, create: true })).toBe('partial');
  });
  test("'full' when read + all writes are effective", () => {
    expect(pageAccessStatus(acts, { read: true, create: true, update: true, archive: true, approve: true })).toBe('full');
  });
  test("single create-only feature: 'view' when its sole action is effective, else 'none'", () => {
    const one = actionsForFeature(catalog, 'barcode_inventory_processing');
    expect(pageAccessStatus(one, { create: true })).toBe('view');
    expect(pageAccessStatus(one, { create: false })).toBe('none');
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run (in `frontend/`): `CI=true npx react-scripts test --watchAll=false src/admin/utils/permissions.test.js`
Expected: FAIL — `actionsForFeature`/`pageAccessStatus` are not exported.

- [ ] **Step 3: Implement the helpers**

In `frontend/src/admin/utils/permissions.js`, append (after the existing `groupByFeatureGroup` function):

```js
// Display order for action columns/rows (read first so "access" reads naturally).
const ACTION_DISPLAY_ORDER = ['read', 'create', 'update', 'archive', 'approve'];

/**
 * The catalog actions that exist for a feature, as [{ action, permission_id }],
 * in read-first display order. Returns [] for unknown feature or missing catalog.
 */
export function actionsForFeature(catalog, feature) {
  const byAction = {};
  (catalog || []).forEach((p) => {
    if (p.feature === feature) byAction[p.action] = p.permission_id;
  });
  return ACTION_DISPLAY_ORDER
    .filter((a) => byAction[a] != null)
    .map((a) => ({ action: a, permission_id: byAction[a] }));
}

/**
 * Summarize a page's access from its primary feature's effective action map.
 * @param actions [{action, permission_id}] from actionsForFeature
 * @param effectiveByAction { [action]: boolean } — effective (pending-aware) grants
 * @returns 'none' | 'view' | 'partial' | 'full'
 */
export function pageAccessStatus(actions, effectiveByAction) {
  if (!actions || actions.length === 0) return 'none';
  const has = (a) => effectiveByAction?.[a] === true;
  // "access" gate = read if the feature has it, otherwise its first action (e.g. create).
  const accessAction = actions.some((x) => x.action === 'read') ? 'read' : actions[0].action;
  if (!has(accessAction)) return 'none';
  const writes = actions.filter((x) => x.action !== accessAction);
  if (writes.length === 0) return 'view';
  const granted = writes.filter((x) => has(x.action)).length;
  if (granted === 0) return 'view';
  if (granted === writes.length) return 'full';
  return 'partial';
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run (in `frontend/`): `CI=true npx react-scripts test --watchAll=false src/admin/utils/permissions.test.js`
Expected: PASS (all cases green).

- [ ] **Step 5: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add frontend/src/admin/utils/permissions.js frontend/src/admin/utils/permissions.test.js
git commit -m "feat(rbac): add actionsForFeature + pageAccessStatus helpers

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Console styles for nav rows + action modal

**Files:**
- Modify: `frontend/src/admin/styles/rbac_console.css`

- [ ] **Step 1: Append the styles**

Add at the end of `frontend/src/admin/styles/rbac_console.css`:

```css
/* ===== Navigation Access rows + per-page action modal ===== */
.rcx-navlist { display: flex; flex-direction: column; gap: 0.5rem; }
.rcx-navrow {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  padding: 0.7rem 0.9rem; border: 1px solid var(--rc-line); border-radius: 12px;
  background: var(--rc-card); transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.rcx-navrow:hover { border-color: var(--rc-ink-faint); box-shadow: 0 6px 18px -12px rgba(20,24,31,0.3); }
.rcx-navrow-main { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.rcx-navrow-name { font-weight: 600; font-size: 0.9rem; }
.rcx-navrow-section { font-family: var(--rc-mono); font-size: 0.64rem; letter-spacing: 0.1em; text-transform: uppercase; color: var(--rc-ink-faint); }
.rcx-navrow-right { display: flex; align-items: center; gap: 0.7rem; flex-shrink: 0; }

/* access status badge */
.rcx-statusb { font-family: var(--rc-mono); font-size: 0.64rem; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; padding: 0.2rem 0.55rem; border-radius: 999px; white-space: nowrap; }
.rcx-statusb--none    { background: #fdecee; color: #b91c1c; }
.rcx-statusb--view    { background: #eef2f7; color: #475569; }
.rcx-statusb--partial { background: #fff7ed; color: #b45309; }
.rcx-statusb--full    { background: #e7f8ef; color: #15803d; }
.rcx-navrow-dirty { width: 7px; height: 7px; border-radius: 50%; background: var(--rc-amber); box-shadow: 0 0 0 3px rgba(217,119,6,0.16); }

/* modal action list */
.rcx-actionlist { display: flex; flex-direction: column; gap: 0.35rem; margin: 0.25rem 0 0.5rem; }
.rcx-actionrow {
  display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.6rem;
  border: 1px solid var(--rc-line-soft); border-radius: 9px; background: var(--rc-card);
}
.rcx-actionrow input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--rc-crimson); cursor: pointer; }
.rcx-actionrow input[type="checkbox"]:disabled { cursor: not-allowed; accent-color: var(--rc-green); }
.rcx-actionrow-label { font-weight: 600; font-size: 0.86rem; }
.rcx-actionrow-hint { margin-left: auto; font-family: var(--rc-mono); font-size: 0.62rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--rc-ink-faint); }
.rcx-actionrow-hint--role { color: var(--rc-green); }
.rcx-actionrow-hint--deny { color: var(--rc-crimson); }
.rcx-modal-note { font-size: 0.78rem; color: var(--rc-ink-soft); line-height: 1.5; margin: 0.5rem 0 1rem; }
.rcx-modal-actions { display: flex; justify-content: flex-end; gap: 0.6rem; }
```

- [ ] **Step 2: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add frontend/src/admin/styles/rbac_console.css
git commit -m "style(rbac): nav-row + action-modal styles for the override editor

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Navigation Access tab → row list + action modal

**Files:**
- Modify: `frontend/src/admin/pages/UserPermissionOverrides.jsx`

Context: the component already has state `users, selectedUserId, userPerms ({effective, overrides}), catalog, bundles, tab, pending ({permission_id: 'grant'|'deny'|'inherit'}), reason, loading, saving, usersLoading, error`, helpers `permGrantedNow, currentOverride, stateFor, setPermState, roleDefault, handleSave`, the Simple-tab bundle table, and the Advanced-tab matrix. This task replaces the Simple-tab bundle table with a row list + modal and removes the now-unused bulk helpers (`bundleOnNow`, `bundleDirty`, `toggleBundle`). Keep `permGrantedNow`, the Advanced tab, the user picker, and the Reason/Save block.

- [ ] **Step 1: Update imports**

In `UserPermissionOverrides.jsx`, change the permissions import to add the new helpers:

```js
import { ACTIONS, ACTION_META, groupByFeatureGroup, actionsForFeature, pageAccessStatus } from '../utils/permissions';
```

Also ensure `permissionsAPI` already imported includes `getRoleDefaults` (it does — `permissionsAPI` is imported). And ensure React hooks `useState` is imported (it is).

- [ ] **Step 2: Add role-defaults state + load**

Add state near the other `useState` declarations:

```js
  const [allRoleDefaults, setAllRoleDefaults] = useState([]); // [{role_id, permission_id, is_granted}]
  const [modalBundle, setModalBundle] = useState(null);       // { bundle, feature, actions } | null
  const [modalDraft, setModalDraft]   = useState({});         // { permission_id: 'grant'|'inherit' }
```

In the existing effect that loads catalog + bundles (the `loadCatalogAndBundles` effect), add a third parallel request and store it. Replace that effect body's `Promise.all` with:

```js
        const [catRes, bundleRes, roleDefRes] = await Promise.all([
          permissionsAPI.catalog(),
          navBundlesAPI.list(),
          permissionsAPI.getRoleDefaults(),
        ]);
        setCatalog(Array.isArray(catRes?.data)   ? catRes.data   : []);
        setBundles(Array.isArray(bundleRes?.data) ? bundleRes.data : []);
        setAllRoleDefaults(Array.isArray(roleDefRes?.data) ? roleDefRes.data : []);
```

- [ ] **Step 3: Add the derivation helpers inside the component**

Add these after `selectedUser`/`isAdminUser` are computed (they need `selectedUser`), and after `permGrantedNow` is defined:

```js
  const selRoleId = selectedUser?.role?.role_id ?? selectedUser?.role_id ?? null;

  // True if the SELECTED user's role grants this permission by default (pure role baseline).
  const roleDefaultGranted = (permId) => {
    if (selRoleId == null) return false;
    const row = allRoleDefaults.find(
      (r) => Number(r.role_id) === Number(selRoleId) && Number(r.permission_id) === Number(permId)
    );
    return !!(row && r_is_granted(row));
  };

  // The primary feature behind a nav bundle (its required permission's feature).
  const bundleFeature = (bundle) => {
    const perms = bundle.permissions || [];
    const req = perms.find((p) => p.is_required) || perms[0];
    return req?.feature || null;
  };

  // Effective (pending-aware) status badge for a bundle's primary feature.
  const bundleStatus = (bundle) => {
    const feature = bundleFeature(bundle);
    const acts = actionsForFeature(catalog, feature);
    const eff = {};
    acts.forEach(({ action, permission_id }) => {
      eff[action] = permGrantedNow({ permission_id, feature, action });
    });
    return pageAccessStatus(acts, eff);
  };

  // Row has unsaved changes if any of its feature's permission ids are pending.
  const bundleHasPending = (bundle) => {
    const feature = bundleFeature(bundle);
    return (catalog || []).some((c) => c.feature === feature && c.permission_id in pending);
  };
```

Add this tiny helper near the top of the file (module scope, above the component) so `roleDefaultGranted` can read the granted flag robustly:

```js
const r_is_granted = (row) => row.is_granted === true || row.is_granted === 1 || row.is_granted === '1';
```

- [ ] **Step 4: Add modal open / toggle / apply / cancel handlers**

Add inside the component:

```js
  const openBundleModal = (bundle) => {
    const feature = bundleFeature(bundle);
    const acts = actionsForFeature(catalog, feature);
    const draft = {};
    acts.forEach(({ permission_id }) => {
      if (roleDefaultGranted(permission_id)) return; // locked on; not editable here
      draft[permission_id] = stateFor(permission_id) === 'grant' ? 'grant' : 'inherit';
    });
    setModalDraft(draft);
    setModalBundle({ bundle, feature, actions: acts });
  };

  const toggleModalAction = (permId) => {
    setModalDraft((d) => ({ ...d, [permId]: d[permId] === 'grant' ? 'inherit' : 'grant' }));
  };

  const applyBundleModal = () => {
    setPending((prev) => ({ ...prev, ...modalDraft }));
    setModalBundle(null);
    setModalDraft({});
  };

  const cancelBundleModal = () => {
    setModalBundle(null);
    setModalDraft({});
  };
```

- [ ] **Step 5: Replace the Simple-tab body with the row list**

Find the Simple-tab block (`{tab === 'simple' && ( ... )}`) which currently renders the bundles `<table className="rcx-table">` with the access buttons. Replace the entire `{tab === 'simple' && (...)}` block with:

```jsx
                {tab === 'simple' && (
                  <div>
                    {bundles.length === 0 && <p className="rcx-muted">No navigation bundles found.</p>}
                    <div className="rcx-navlist">
                      {bundles.map((bundle) => {
                        const status = bundleStatus(bundle);
                        const dirty = bundleHasPending(bundle);
                        const labels = { none: 'No access', view: 'View-only', partial: 'Partial', full: 'Full' };
                        return (
                          <div key={bundle.bundle_id} className="rcx-navrow">
                            <div className="rcx-navrow-main">
                              <span className="rcx-navrow-name">{bundle.name}</span>
                              <span className="rcx-navrow-section">{bundle.sidebar_section || '—'}</span>
                            </div>
                            <div className="rcx-navrow-right">
                              {dirty && <span className="rcx-navrow-dirty" title="Unsaved changes" />}
                              <span className={`rcx-statusb rcx-statusb--${status}`}>{labels[status]}</span>
                              <button type="button" className="rcx-btn rcx-btn--ghost" onClick={() => openBundleModal(bundle)}>
                                Configure
                              </button>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}
```

- [ ] **Step 6: Add the action modal (render near the page's other modals / before closing the `.rbac-console` wrapper)**

Add this just before the closing of the `<div className="rbac-console">` (i.e., as the last child of the console wrapper, after the main content):

```jsx
        {modalBundle && (
          <Modal title={`${modalBundle.bundle.name} — actions`} onClose={cancelBundleModal} maxWidth={460}>
            <div className="rcx-actionlist">
              {modalBundle.actions.map(({ action, permission_id }) => {
                const viaRole = roleDefaultGranted(permission_id);
                const checked = viaRole || modalDraft[permission_id] === 'grant';
                const deniedAdv = !viaRole && currentOverride(permission_id) === 'deny' && modalDraft[permission_id] !== 'grant';
                return (
                  <label className="rcx-actionrow" key={permission_id}>
                    <input
                      type="checkbox"
                      checked={checked}
                      disabled={viaRole}
                      onChange={() => toggleModalAction(permission_id)}
                    />
                    <span className="rcx-actionrow-label">{ACTION_META[action]?.label || action}</span>
                    {viaRole && <span className="rcx-actionrow-hint rcx-actionrow-hint--role">via role</span>}
                    {deniedAdv && <span className="rcx-actionrow-hint rcx-actionrow-hint--deny">denied in advanced</span>}
                  </label>
                );
              })}
            </div>
            <p className="rcx-modal-note">
              Ticking grants this user the action on top of their role. Actions marked
              <strong> via role </strong> are already granted by their role and can only be blocked in the Advanced tab.
            </p>
            <div className="rcx-modal-actions">
              <button type="button" className="rcx-btn rcx-btn--ghost" onClick={cancelBundleModal}>Cancel</button>
              <button type="button" className="rcx-btn rcx-btn--primary" onClick={applyBundleModal}>Apply</button>
            </div>
          </Modal>
        )}
```

Confirm `Modal` is imported at the top of the file; if not, add `import Modal from '../components/Modal';`.

- [ ] **Step 7: Remove now-unused Simple-tab helpers**

Delete the `bundleOnNow`, `bundleDirty`, `toggleBundle` function definitions (and the `permGrantedNow` JSDoc comment block referencing them is fine to keep — `permGrantedNow` is still used by `bundleStatus`). Do NOT remove `permGrantedNow`. If any of `bundleOnNow`/`bundleDirty`/`toggleBundle` are now referenced nowhere, removing them prevents lint `no-unused-vars`.

- [ ] **Step 8: Build to verify it compiles**

Run (in `frontend/`): `rm -rf node_modules/.cache .eslintcache && CI=false npx react-scripts build`
Expected: `Compiled` (warnings about html5-qrcode source maps are pre-existing and OK); exit 0. If it fails with `no-undef`/`no-unused-vars`, fix the named symbol and rebuild.

- [ ] **Step 9: Run the helper unit tests again (guard against regressions)**

Run (in `frontend/`): `CI=true npx react-scripts test --watchAll=false src/admin/utils/permissions.test.js`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add frontend/src/admin/pages/UserPermissionOverrides.jsx
git commit -m "feat(rbac): per-page action modal in Navigation Access tab

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Backend guard — permission override does not widen location scope

**Files:**
- Create: `back-end/tests/Feature/Permissions/ScopeWithOverrideTest.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/Permissions/ScopeWithOverrideTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Location;
use App\Models\Permission;
use App\Models\StatusLookup;
use App\Models\User;
use App\Services\PermissionAdminService;
use App\Support\PermissionResolver;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScopeWithOverrideTest extends TestCase
{
    use DatabaseTransactions;

    public function test_permission_override_does_not_widen_location_scope(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionsSeeder::class);

        $statusId = (int) (StatusLookup::where('status_category', 'user')->where('status_name', 'Active')->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id);

        $loc = Location::forceCreate([
            'location_code' => 'SCOPE_T',
            'location_name' => 'Scope Test Branch',
            'location_type' => 'branch_office',
            'status_id'     => 1,
        ]);

        // warehouse_personnel = role_id 4 (location-scoped, NOT centralized)
        $wp = User::forceCreate([
            'first_name'    => 'Scope', 'last_name' => 'WP',
            'username'      => 'scope_wp_' . uniqid(),
            'email'         => 'scope_wp_' . uniqid() . '@rbac.test',
            'password_hash' => Hash::make('x'),
            'role_id'       => 4,
            'status_id'     => $statusId,
            'location_id'   => $loc->location_id,
        ]);

        $admin = User::where('role_id', 1)->first();
        $this->assertNotNull($admin, 'an admin user must exist in the test DB');

        // Baseline: scoped, not centralized.
        $this->assertFalse($wp->isCentralized());
        $this->assertSame((int) $loc->location_id, $wp->scopedLocationId());

        // Grant the scoped user an explicit permission override.
        $perm = Permission::where('feature', 'inventory_adjustments')->where('action', 'read')->firstOrFail();
        app(PermissionAdminService::class)->setUserOverride(
            $admin, (int) $wp->user_id, (int) $perm->permission_id, true, 'guard test'
        );

        // The override is in effect...
        $eff = app(PermissionResolver::class)->effectivePermissionsFor((int) $wp->user_id);
        $this->assertTrue($eff['inventory_adjustments.read'] ?? false, 'override should be effective');

        // ...but data scope is unchanged: still scoped to their own location.
        $wp->refresh();
        $this->assertFalse($wp->isCentralized(), 'a permission override must not make a scoped user centralized');
        $this->assertSame((int) $loc->location_id, $wp->scopedLocationId(), 'a permission override must not change scoped location');
    }
}
```

- [ ] **Step 2: Run the test to verify it passes (invariant already holds)**

Run (in `back-end/`): `php artisan test --filter=ScopeWithOverrideTest`
Expected: PASS. (This test encodes the spec invariant; it should pass against the current code because scoping is role-based. If it FAILS, that is a real finding — stop and report; do not weaken the assertions.)

- [ ] **Step 3: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add back-end/tests/Feature/Permissions/ScopeWithOverrideTest.php
git commit -m "test(rbac): permission override must not widen location scope

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Backend suite green**

Run (in `back-end/`): `php artisan test`
Expected: all pass (prior baseline was 126 passed + this new test).

- [ ] **Step 2: Frontend production build**

Run (in `frontend/`): `CI=false npx react-scripts build`
Expected: exit 0 (`Compiled`; only the pre-existing html5-qrcode source-map warnings).

- [ ] **Step 3: Manual checklist (record results in the final report)**

With backend (`php artisan serve`) + frontend (`npm start`) running, log in as admin → **User Overrides**:
1. Pick a Branch Manager user → Navigation Access shows rows with status badges + Configure.
2. Click **Configure** on **Purchase Requests** → modal lists only `purchase_request_creation` actions; actions the BM's role already grants show **checked + disabled "via role"**.
3. Tick a non-role action (e.g. Create on a page the role lacks) → **Apply** → the row shows the amber unsaved dot and an updated badge; bottom shows pending count.
4. Enter a **Reason** → **Save** → success toast; reopen the modal → the ticked action persists (now possibly "via role" only if it was a role grant; otherwise checked).
5. **Advanced** tab still renders the full tri-state matrix and can set an explicit Deny.
6. Confirm a scoped user (WP/BM) still only sees their own location's data after a grant (open Inventory/Dashboard as that user).

- [ ] **Step 4: Final commit (if any manual-fix tweaks were needed)**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add -A -- ':!.claude/settings.local.json'
git commit -m "chore(rbac): finalize unified nav-access action modal

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Modal scope = primary feature only → Task 3 Steps 3–6 (`bundleFeature` + `actionsForFeature`). ✓
- Keep Advanced tab → Task 3 leaves Advanced + Reason/Save untouched. ✓
- Option 1 (checked=grant / unchecked=inherit; never deny) → Task 3 Step 4 `toggleModalAction` only stages `grant`/`inherit`; Step 6 modal. ✓
- Role-granted actions locked-on "via role" → Task 3 Step 6 (`viaRole` → checked+disabled). ✓
- Status badges (none/view/partial/full) → Task 1 `pageAccessStatus` + Task 3 Step 5. ✓
- Pending/Reason/Save batching reused → Task 3 Steps 4–5 stage into `pending`; existing Save unchanged. ✓
- Role baseline via getRoleDefaults (no API change) → Task 3 Step 2. ✓
- Location-scope invariant (only admin/IA/auditor centralized; grants don't widen scope) → Task 4. ✓
- No backend/API changes (besides the test) → confirmed; only frontend + one test. ✓

**Placeholder scan:** none — every step has concrete code/commands.

**Type/name consistency:** `actionsForFeature(catalog, feature)` and `pageAccessStatus(actions, effectiveByAction)` signatures match across Tasks 1 and 3; `modalBundle`/`modalDraft`/`bundleFeature`/`roleDefaultGranted`/`openBundleModal`/`applyBundleModal`/`cancelBundleModal`/`toggleModalAction` used consistently in Task 3; `r_is_granted` defined (Step 3) and used by `roleDefaultGranted`.
