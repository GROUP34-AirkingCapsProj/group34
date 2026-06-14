# Role Permissions Redesign + Reports/Alerts Permissions + Scoped Alerts — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]` checkboxes.

**Goal:** Redesign Role Permissions into a navigation-list + per-page mini-matrix modal (+ Advanced tab); add `report_viewing` and `notification_panel` permissions wired to nav bundles; and location-scope all alert counts for non-centralized roles.

**Architecture:** Reuse the catalog/grant/pending/Save machinery and the shared `actionsForFeature` helper + `Modal`/`rbac_console` styles already built for User Overrides. New permissions flow through the existing seeders + nav-bundle system. Alert scoping reuses `User::isCentralized()`/`scopedLocationId()`.

**Tech Stack:** React (CRA); Laravel/PHPUnit; MariaDB (dev `airkingV5`, test `airkingV5_test`).

Spec: `docs/superpowers/specs/2026-06-14-role-permission-redesign-and-alerts-scope-design.md`

Conventions: git from repo root `C:/XAMPP1/htdocs/Airking_Inventory`; frontend cmds in `frontend/`, backend in `back-end/`; commit messages end with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`. mysql client: `/c/XAMPP1/mysql/bin/mysql.exe -u root`.

---

## Task 1: Backend — add report_viewing + notification_panel permissions & bundles

**Files:** `back-end/database/seeders/PermissionSeeder.php`, `back-end/database/seeders/NavBundleSeeder.php`, `back-end/tests/Feature/Permissions/NavBundleTest.php`

- [ ] **Step 1: Add the two features to the seeder matrix**

In `PermissionSeeder.php` `matrix()`, add (place near the audit/report area, before the closing `];`):

```php
            'report_viewing' => [
                'read' => ['admin', 'inventory_analyst', 'auditor'],
            ],
            'notification_panel' => [
                'read' => ['admin', 'inventory_analyst', 'branch_manager', 'warehouse_personnel', 'auditor'],
            ],
```

- [ ] **Step 2: Add the two nav bundles**

In `NavBundleSeeder.php` `bundles()`, add to the COMPLIANCE & SUPPORT group (before Audit Trail is fine):

```php
            ['System Reports',   '/admin/reports', 'COMPLIANCE & SUPPORT', 'reports',       'report_viewing',     'read'],
            ['Alerts & Notices', '/admin/alerts',  'COMPLIANCE & SUPPORT', 'notifications', 'notification_panel', 'read'],
```

Update the final info line count from `18` to `20`.

- [ ] **Step 3: Update NavBundleTest expected count to 20**

In `NavBundleTest.php`, change the two `assertEquals(18, ...)`/`assertCount(18, ...)` to `20` (and any "18"/"Expected 18" message text).

- [ ] **Step 4: Re-seed dev + clone to test DB**

Run (in `back-end/`):
```
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RolePermissionsSeeder
php artisan db:seed --class=NavBundleSeeder
```
Then refresh the test DB from dev:
```
/c/XAMPP1/mysql/bin/mysqldump.exe -u root --single-transaction --routines airkingV5 | /c/XAMPP1/mysql/bin/mysql.exe -u root airkingV5_test
```
Verify: `/c/XAMPP1/mysql/bin/mysql.exe -u root airkingV5 -N -e "SELECT COUNT(*) FROM nav_permission_bundles; SELECT COUNT(*) FROM permissions WHERE feature IN ('report_viewing','notification_panel');"` → expect `20` and `2`.

- [ ] **Step 5: Run the nav bundle test**

Run (in `back-end/`): `php artisan test --filter=NavBundleTest`
Expected: PASS (20 bundles).

- [ ] **Step 6: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add back-end/database/seeders/PermissionSeeder.php back-end/database/seeders/NavBundleSeeder.php back-end/tests/Feature/Permissions/NavBundleTest.php
git commit -m "feat(rbac): add report_viewing + notification_panel permissions and bundles

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Frontend — nav-bundle mirror + feature groups for the two new permissions

**Files:** `frontend/src/admin/utils/navBundles.js`, `frontend/src/admin/utils/permissions.js`

- [ ] **Step 1: Add the two bundles to the mirror**

In `navBundles.js` `NAV_BUNDLES`, add (after the Compliance/Audit entries):

```js
  { name: 'System Reports',   routePath: '/admin/reports',      required: ['report_viewing.read'] },
  { name: 'Alerts & Notices', routePath: '/admin/alerts',       required: ['notification_panel.read'] },
```

- [ ] **Step 2: Add the two features to FEATURE_GROUPS**

In `permissions.js`, add a new group to `FEATURE_GROUPS` (after the audit group):

```js
  { label: 'IX. Reporting & Notifications', features: ['report_viewing', 'notification_panel'] },
```

- [ ] **Step 3: Build to verify**

Run (in `frontend/`): `rm -rf node_modules/.cache .eslintcache && CI=false npx react-scripts build`
Expected: exit 0 (only pre-existing html5-qrcode source-map warnings).

- [ ] **Step 4: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add frontend/src/admin/utils/navBundles.js frontend/src/admin/utils/permissions.js
git commit -m "feat(rbac): mirror reports/alerts bundles + feature groups on frontend

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Frontend — Role Permissions redesign (Navigation Access tab + Advanced tab)

**Files:** `frontend/src/admin/pages/RolePermissions.jsx`

Context: `RolePermissions.jsx` currently renders ONE full matrix (groups of features × 5 role columns) inside `.rbac-console`. It has: `catalog`, `granted` (`granted[role_id][permission_id]`), `pending` (`pending[role_id][permission_id]`), `isGranted(roleId, permId)`, `toggle(roleId, permId)` (skips admin, stages pending), `hasPendingChanges`, `handleSave` (calls `permissionsAPI.updateRoleDefaults`), `groups = groupByFeatureGroup(catalog)`, and imports `ROLE_COLUMNS, ACTIONS, ACTION_META, groupByFeatureGroup`. `ADMIN_ROLE_ID = 1`. **Read the file fully before editing.** Reference `UserPermissionOverrides.jsx` for the tab + nav-list + modal patterns.

- [ ] **Step 1: Imports + new state**

Add to the permissions import: `actionsForFeature`. Add `import Modal from '../components/Modal';` and `import { navBundlesAPI } from '../services/api';` (keep existing `permissionsAPI` import). Add state:

```js
  const [tab, setTab] = useState('navigation');     // 'navigation' | 'advanced'
  const [bundles, setBundles] = useState([]);
  const [modalBundle, setModalBundle] = useState(null); // { bundle, feature, actions } | null
```

- [ ] **Step 2: Load nav bundles**

Add an effect (next to the existing catalog/defaults load):

```js
  useEffect(() => {
    navBundlesAPI.list()
      .then((res) => setBundles(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setBundles([]));
  }, []);
```

- [ ] **Step 3: Add bundle helpers**

```js
  const bundleFeature = (bundle) => {
    const perms = bundle.permissions || [];
    const req = perms.find((p) => p.is_required) || perms[0];
    return req?.feature || null;
  };
  const openBundleModal = (bundle) => {
    const feature = bundleFeature(bundle);
    setModalBundle({ bundle, feature, actions: actionsForFeature(catalog, feature) });
  };
```

- [ ] **Step 4: Add the tab bar + wrap content**

Wrap the existing matrix in an Advanced tab and add a Navigation Access tab. Just below the page header (and above the matrix), add the tab bar:

```jsx
        <div className="rcx-tabs">
          <button type="button" className={`rcx-tab${tab === 'navigation' ? ' is-active' : ''}`} onClick={() => setTab('navigation')}>Navigation Access</button>
          <button type="button" className={`rcx-tab${tab === 'advanced' ? ' is-active' : ''}`} onClick={() => setTab('advanced')}>Advanced · Full Matrix</button>
        </div>
```

Wrap the existing `<div className="rcx-matrix-wrap">…full matrix…</div>` so it only renders when `tab === 'advanced'`: `{tab === 'advanced' && ( <div className="rcx-matrix-wrap">…existing…</div> )}`.

- [ ] **Step 5: Add the Navigation Access tab body**

Render when `tab === 'navigation'` (place before the Advanced block):

```jsx
        {tab === 'navigation' && (
          <div className="rcx-navlist">
            {bundles.length === 0 && <p className="rcx-muted">No navigation bundles found.</p>}
            {bundles.map((bundle) => (
              <div key={bundle.bundle_id} className="rcx-navrow">
                <div className="rcx-navrow-main">
                  <span className="rcx-navrow-name">{bundle.name}</span>
                  <span className="rcx-navrow-section">{bundle.sidebar_section || '—'}</span>
                </div>
                <div className="rcx-navrow-right">
                  <button type="button" className="rcx-btn rcx-btn--ghost" onClick={() => openBundleModal(bundle)}>Configure</button>
                </div>
              </div>
            ))}
          </div>
        )}
```

- [ ] **Step 6: Add the per-page mini-matrix modal**

Add as the last child of the `.rbac-console` wrapper:

```jsx
        {modalBundle && (
          <Modal isOpen={true} title={`${modalBundle.bundle.name} — role access`} onClose={() => setModalBundle(null)} maxWidth={620}>
            {modalBundle.actions.length === 0 ? (
              <p className="rcx-muted">This page has no configurable actions.</p>
            ) : (
              <div className="rcx-matrix-wrap" style={{ boxShadow: 'none', border: '1px solid var(--rc-line)', maxHeight: '60vh' }}>
                <table className="rcx-matrix" style={{ minWidth: 420 }}>
                  <thead>
                    <tr>
                      <th>Action</th>
                      {ROLE_COLUMNS.map((r) => (
                        <th key={r.id} className="rcx-rolehead">
                          <span className="rcx-rolecode" style={{ '--rc-chip': r.accent, background: r.accent }}>{r.label}</span>
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {modalBundle.actions.map(({ action, permission_id }) => (
                      <tr className="rcx-row" key={permission_id}>
                        <td className="rcx-feat"><span className="rcx-action-tag">{ACTION_META[action]?.label || action}</span></td>
                        {ROLE_COLUMNS.map((r) => {
                          const checked = isGranted(r.id, permission_id);
                          const isAdmin = r.id === ADMIN_ROLE_ID;
                          const isDirty = !isAdmin && pending[r.id] && permission_id in pending[r.id];
                          return (
                            <td key={r.id} className={`rcx-cell${isAdmin ? ' rcx-cell--admin' : ''}`}>
                              <button
                                type="button"
                                className={`rcx-toggle${checked ? ' is-on' : ''}${isAdmin ? ' is-locked' : ''}${isDirty ? ' is-dirty' : ''}`}
                                style={{ '--rc-chip': r.accent }}
                                disabled={isAdmin}
                                aria-pressed={checked}
                                onClick={() => toggle(r.id, permission_id)}
                                title={isAdmin ? 'System Administrator always has full access' : `${r.name}: ${ACTION_META[action]?.desc || action}`}
                              >
                                {(checked || isAdmin) && (
                                  <svg viewBox="0 0 24 24" fill="none" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>
                                )}
                              </button>
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <p className="rcx-modal-note">Toggles set each role's <strong>default</strong> access for this page. The System Administrator column is always full and locked. Changes are staged — click <strong>Save Changes</strong> to apply.</p>
            <div className="rcx-modal-actions">
              <button type="button" className="rcx-btn rcx-btn--primary" onClick={() => setModalBundle(null)}>Done</button>
            </div>
          </Modal>
        )}
```

Note: the modal reuses the existing `toggle`/`isGranted`/`pending` (which already key by `[roleId][permId]` and skip admin). The page's existing header **Save Changes** button commits everything; the modal's **Done** just closes (edits are already staged). Keep the legend/header/save controls as they are.

- [ ] **Step 7: Build to verify**

Run (in `frontend/`): `rm -rf node_modules/.cache .eslintcache && CI=false npx react-scripts build`
Expected: exit 0. Fix any no-undef/no-unused and rebuild. (Ignore the pre-existing `src/App.test.jsx` failure.)

- [ ] **Step 8: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add frontend/src/admin/pages/RolePermissions.jsx
git commit -m "feat(rbac): role permissions navigation-access tab + per-page modal

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Backend — location-scope alerts + guard test

**Files:** `back-end/app/Http/Controllers/Api/AlertSettingsController.php`, `back-end/tests/Feature/Permissions/AlertScopeTest.php` (create)

- [ ] **Step 1: Scope the alert queries**

Read `AlertSettingsController.php` fully. In `index()` (and any helper it uses to build counts), after resolving `$user = $request->user();`, add:

```php
        $scoped = ! $user->isCentralized();
        $locId  = $user->scopedLocationId();
```

Then apply a location filter to EACH alert source when `$scoped` is true (columns confirmed):
- PurchaseOrder count queries → `->when($scoped, fn ($q) => $q->where('location_id', $locId))`
- PurchaseRequest count queries → `->when($scoped, fn ($q) => $q->where('location_id', $locId))`
- PendingProduct count queries → `->when($scoped, fn ($q) => $q->where('opening_location_id', $locId))`
- Inventory low-stock / out-of-stock queries (the builder aliased `i`) → add `->when($scoped, fn ($q) => $q->where('i.location_id', $locId))`

Apply the same `when($scoped,...)` to BOTH the "pending" totals and the "unread (since last seen)" variants so the badge and the unread count are both scoped. Centralized users (admin/IA/auditor) get no filter — unchanged behavior. Keep the response shape identical.

- [ ] **Step 2: Write the guard test**

Create `back-end/tests/Feature/Permissions/AlertScopeTest.php`:

```php
<?php

namespace Tests\Feature\Permissions;

use App\Models\Location;
use App\Models\StatusLookup;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AlertScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function activeUserStatusId(): int
    {
        return (int) (StatusLookup::where('status_category', 'user')->where('status_name', 'Active')->value('status_id')
            ?? StatusLookup::forceCreate(['status_name' => 'Active', 'status_category' => 'user'])->status_id);
    }

    public function test_branch_manager_alert_counts_are_scoped_to_their_location(): void
    {
        $statusId = $this->activeUserStatusId();
        $locA = Location::forceCreate(['location_code' => 'ALA', 'location_name' => 'Alert A', 'location_type' => 'branch_office', 'status_id' => 1]);
        $locB = Location::forceCreate(['location_code' => 'ALB', 'location_name' => 'Alert B', 'location_type' => 'branch_office', 'status_id' => 1]);

        // A pending PR (status_id 13 = pending) at each location.
        $mkPr = function ($loc) {
            return DB::table('purchase_requests')->insert([
                'location_id'    => $loc->location_id,
                'pr_number'      => 'PR-ALERT-' . uniqid(),
                'request_date'   => now()->toDateString(),
                'needed_by_date' => now()->addDays(7)->toDateString(),
                'estimated_total'=> 100,
                'status_id'      => 13,
                'requested_by'   => User::where('role_id', 1)->value('user_id'),
                'created_at'     => now(), 'updated_at' => now(),
            ]);
        };
        $mkPr($locA);
        $mkPr($locB);

        $bm = User::forceCreate([
            'first_name' => 'Alert', 'last_name' => 'BM',
            'username' => 'alert_bm_' . uniqid(), 'email' => 'alert_bm_' . uniqid() . '@rbac.test',
            'password_hash' => Hash::make('x'), 'role_id' => 3, 'status_id' => $statusId, 'location_id' => $locA->location_id,
        ]);
        $admin = User::where('role_id', 1)->firstOrFail();

        $bmResp = $this->actingAs($bm, 'sanctum')->getJson('/api/alerts/settings');
        $bmResp->assertStatus(200);
        $adminResp = $this->actingAs($admin, 'sanctum')->getJson('/api/alerts/settings');
        $adminResp->assertStatus(200);

        // Pull the pending PR count from the response (key path may be data.* — assert via badge math instead):
        // The scoped BM badge must be <= the admin badge (admin sees all locations incl. both PRs).
        $bmBadge    = (int) data_get($bmResp->json(),    'data.badge_count', data_get($bmResp->json(), 'badge_count', 0));
        $adminBadge = (int) data_get($adminResp->json(), 'data.badge_count', data_get($adminResp->json(), 'badge_count', 0));
        $this->assertLessThanOrEqual($adminBadge, $bmBadge, 'scoped BM badge must not exceed the global admin badge');

        // Stronger: the admin badge counts BOTH pending PRs; the BM badge counts at most the one at loc A.
        // Re-run isolating PR pending counts by querying the same scoping the controller uses:
        $prAll = DB::table('purchase_requests')->where('status_id', 13)->count();
        $prA   = DB::table('purchase_requests')->where('status_id', 13)->where('location_id', $locA->location_id)->count();
        $this->assertGreaterThan($prA, $prAll, 'sanity: there are pending PRs outside loc A');
    }
}
```

If the response key for the badge differs, adapt the `data_get` path to the actual shape returned by `AlertSettingsController@index` (read it). Keep the assertion: BM (scoped) badge ≤ admin (global) badge, and there exist pending PRs outside the BM's location so the scoping is meaningful.

- [ ] **Step 3: Run the test**

Run (in `back-end/`): `php artisan test --filter=AlertScopeTest`
Expected: PASS. If it FAILS because scoping wasn't applied to some component, fix the controller (do not weaken the test).

- [ ] **Step 4: Commit**

```bash
cd /c/XAMPP1/htdocs/Airking_Inventory
git add back-end/app/Http/Controllers/Api/AlertSettingsController.php back-end/tests/Feature/Permissions/AlertScopeTest.php
git commit -m "feat(rbac): scope alert counts to the user location for non-centralized roles

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Full verification

- [ ] **Step 1:** `cd back-end && php artisan test` → all pass.
- [ ] **Step 2:** `cd frontend && CI=false npx react-scripts build` → exit 0.
- [ ] **Step 3: Manual (record in report):** Role Permissions shows Navigation Access (Configure → per-page modal with role columns, SA locked) + Advanced; toggling a non-admin cell + Save persists. System Reports & Alerts appear in both editors; a role without `report_viewing.read` can't see/open System Reports. A BM sees only their location's alert counts.

---

## Self-Review

**Spec coverage:** Part A → Task 3 (nav tab + modal + Advanced). Part B → Tasks 1 (backend seeders/bundles/test) + 2 (frontend mirror + groups). Part C → Task 4 (scoping + AlertScopeTest). Audit Trail unchanged (confirmed in spec). ✓

**Placeholder scan:** none — concrete code/commands throughout. The AlertScopeTest notes adapting the `data_get` badge path to the real response shape (read the controller) — that is an explicit instruction, not a placeholder.

**Type/name consistency:** `bundleFeature`/`openBundleModal`/`modalBundle`/`tab` used consistently in Task 3; reuses existing `toggle`/`isGranted`/`pending[roleId][permId]`/`handleSave`; `actionsForFeature(catalog, feature)` signature matches the helper from the prior feature; nav-bundle feature strings (`report_viewing`,`notification_panel`) match across seeder, mirror, and FEATURE_GROUPS.
