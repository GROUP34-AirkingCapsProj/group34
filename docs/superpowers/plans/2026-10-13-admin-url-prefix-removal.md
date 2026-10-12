# Removing the `/admin` URL Prefix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve every admin screen at `/login`, `/dashboard`, `/inventory` instead of `/admin/login`, `/admin/dashboard`, `/admin/inventory`, with old URLs redirecting.

**Architecture:** A mechanical rename of route-path string literals in the React
router and its permission maps, plus a migration moving the same prefix off the
`nav_permission_bundles.route_path` column that `RoleRoute` matches pathnames
against. One `LegacyAdminRedirect` component at `/admin/*` translates old URLs,
which also covers already-persisted notification deep links. Two files need
genuine logic changes rather than a rename — `SessionIdleGuard` (its predicate
gates on `startsWith('/admin')`, which would become permanently false) and
`App.jsx`.

**Tech Stack:** React 18 + react-router-dom 7.18.4 (pinned `^7.12.0`), Vite, Jest + React Testing
Library, Laravel 11 (PHP 8), MySQL.

**Spec:** `docs/superpowers/specs/2026-10-13-admin-url-prefix-removal-design.md`

---

## Verified Inventory

Measured against the working tree, not estimated. The partition below is exact —
the three groups are disjoint and account for every `/admin` occurrence in
`frontend/src`:

| Group | Count | Handling |
|---|---|---|
| Quoted route literals, `'/admin/...'` | 387 | `sed` (Task 7) |
| Bare `/admin` — `SessionIdleGuard.jsx:15`, `App.jsx:91` | 2 | By hand (Tasks 2, 3) |
| One comment — `App.jsx:145` | 1 | By hand (Task 2) |
| Module/asset imports, `'./admin/pages/X'`, `'../../admin/styles/x.css'` | 73 | **Must not change** |

**The discriminator that makes the bulk rename safe:** a route literal is a
quote character immediately followed by `/admin/`. Module and asset imports are
always relative, so the character after the quote is `.`. This was verified:

```
grep -rnE "['\"\`]/admin/(styles|components|pages|utils|hooks|services|context)\b" frontend/src
→ 0 matches
```

Zero occurrences are API paths — no `apiRequest`/`axios`/`fetch` call targets
`/admin`, and `routes/api.php` defines no `/admin` endpoint — so nothing
server-facing is at risk.

> Note: the spec says "398 occurrences across 59 files". That came from a looser
> regex that also counted file-path comments like
> `// frontend/src/admin/utils/findById.js`. The exact figure is 390 route-form
> occurrences (387 + 2 + 1). The spec's conclusions are unaffected.

## Regression Not Covered By The Spec

`frontend/src/admin/components/SessionIdleGuard.jsx:15` reads:

```js
if (!pathname.startsWith('/admin')) return false;
```

That value feeds `active` at line 56, which arms the inactivity logout. After
the rename no pathname starts with `/admin`, so the predicate returns false
everywhere and **idle logout silently stops working across the whole app** — no
error, no failing test, just a security control that quietly does nothing. The
spec only flagged this file's login exemption. Task 3 inverts the predicate and
pins the behaviour with tests.

## File Structure

**Created:**
- `frontend/src/admin/components/LegacyAdminRedirect.jsx` — translates one old URL to its new form. Sole responsibility; no routing table knowledge.
- `frontend/src/admin/components/LegacyAdminRedirect.test.jsx`
- `frontend/src/admin/components/SessionIdleGuard.paths.test.jsx`
- `back-end/database/migrations/2026_10_13_000001_strip_admin_prefix_from_nav_bundles.php`

**Modified:**
- `frontend/src/App.jsx` — 116 occurrences; route table, bare `/admin` route, stale comment
- `frontend/src/admin/components/SessionIdleGuard.jsx` — predicate inversion
- `frontend/src/admin/components/RoleRoute.jsx` — 7 occurrences
- `frontend/src/admin/services/api.js` — 401/403 hard redirects
- `frontend/src/admin/utils/{navBundles,roles,navConfigureMap,alertTarget,sidebarBuilder}.js` — permission maps
- 36 further page/component files — plain path literals
- 15 frontend test files
- `back-end/database/seeders/NavBundleSeeder.php` — 31 bundle paths
- `back-end/app/Http/Controllers/Api/AlertSettingsController.php` — 7 links
- `back-end/app/Services/WarehouseNotificationService.php` — 2 links
- `back-end/tests/Feature/Permissions/NavBundleTest.php` — new assertion
- `frontend/src/admin/README.md` — documented route list

## Commands

- Frontend, one file: `cd frontend && npx jest src/path/to/file.test.jsx`
- Frontend, everything: `cd frontend && npx jest`
- Backend, filtered: `cd back-end && php artisan test --filter=NavBundleTest`
- Migrate: `cd back-end && php artisan migrate --force`
  (`--force` is required — `APP_ENV=production` locally)
- Migrate the test database too:
  `cd back-end && DB_DATABASE=airkingV5_test php artisan migrate --force`

  There is **no** `mysql_test` connection, so `--database=mysql_test` fails.
  `phpunit.xml:26-29` selects the test database by overriding `DB_DATABASE` on
  the `mysql` connection, so the migrate command overrides the same variable.
  Verified: `DB_DATABASE=airkingV5_test php artisan tinker --execute="echo DB::connection()->getDatabaseName();"`
  prints `airkingV5_test`, against `airkingvv6` by default. In PowerShell the
  inline form does not work — use
  `$env:DB_DATABASE='airkingV5_test'; php artisan migrate --force` and clear it
  afterwards with `$env:DB_DATABASE=$null`.

  The test database is separate and must be migrated, or the backend tests run
  against the old schema.

---

### Task 1: `LegacyAdminRedirect` component

Self-contained and depends on nothing else in this plan, so it lands first.

**Files:**
- Create: `frontend/src/admin/components/LegacyAdminRedirect.jsx`
- Test: `frontend/src/admin/components/LegacyAdminRedirect.test.jsx`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/admin/components/LegacyAdminRedirect.test.jsx`:

```jsx
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route, useLocation } from 'react-router-dom';
import LegacyAdminRedirect from './LegacyAdminRedirect';

// Renders wherever the redirect lands, so assertions can read the final URL.
function Landing() {
  const { pathname, search, hash } = useLocation();
  return <div data-testid="landed">{`${pathname}${search}${hash}`}</div>;
}

function renderAt(initialEntry) {
  render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route path="/admin/*" element={<LegacyAdminRedirect />} />
        <Route path="*" element={<Landing />} />
      </Routes>
    </MemoryRouter>
  );
  return screen.getByTestId('landed').textContent;
}

test('strips the /admin prefix from a simple path', () => {
  expect(renderAt('/admin/dashboard')).toBe('/dashboard');
});

test('strips the prefix from a nested path', () => {
  expect(renderAt('/admin/ist/42/receive')).toBe('/ist/42/receive');
});

test('preserves the query string', () => {
  expect(renderAt('/admin/incidents?tab=items')).toBe('/incidents?tab=items');
});

test('preserves both query string and hash', () => {
  expect(renderAt('/admin/transfers?tab=send&focus=transfer:7#row-7'))
    .toBe('/transfers?tab=send&focus=transfer:7#row-7');
});

test('sends bare /admin to the login page', () => {
  expect(renderAt('/admin')).toBe('/login');
});

test('sends a trailing-slash /admin/ to the login page', () => {
  expect(renderAt('/admin/')).toBe('/login');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx jest src/admin/components/LegacyAdminRedirect.test.jsx`

Expected: FAIL — `Cannot find module './LegacyAdminRedirect'`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/admin/components/LegacyAdminRedirect.jsx`:

```jsx
import { Navigate, useLocation, useParams } from 'react-router-dom';

/**
 * Translates a legacy /admin/* URL to its unprefixed form.
 *
 * The /admin prefix was removed from every route; this keeps old bookmarks, open
 * tabs, and — importantly — the deep links already written into notification
 * rows by AlertSettingsController and WarehouseNotificationService working.
 * Those rows are not back-filled, so this component is the only thing resolving
 * them.
 *
 * `replace` is deliberate: without it every legacy visit leaves an /admin entry
 * in history and the back button bounces through the redirect.
 */
export default function LegacyAdminRedirect() {
  const params = useParams();
  const { search, hash } = useLocation();

  // react-router exposes the splat under the '*' key.
  const rest = params['*'] || '';

  // Bare /admin and /admin/ carry no destination, so fall back to login rather
  // than redirecting to '/', which would bounce again.
  const target = rest ? `/${rest}` : '/login';

  return <Navigate to={`${target}${search}${hash}`} replace />;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx jest src/admin/components/LegacyAdminRedirect.test.jsx`

Expected: PASS — 6 passed.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/LegacyAdminRedirect.jsx frontend/src/admin/components/LegacyAdminRedirect.test.jsx
git commit -m "feat(routing): add LegacyAdminRedirect for old /admin URLs"
```

---

### Task 2: Rename the router paths in `App.jsx`

**Files:**
- Modify: `frontend/src/App.jsx` (116 occurrences)

- [ ] **Step 1: Rename the quoted route literals**

The lazy `import('./admin/pages/X')` calls must survive untouched, which is why
the pattern anchors on a quote followed directly by `/admin/`:

```bash
cd frontend && sed -i -E "s#(['\"\`])/admin/#\1/#g" src/App.jsx
```

- [ ] **Step 2: Verify the lazy imports were not damaged**

Run: `cd frontend && grep -c "import('./admin/pages/" src/App.jsx`

Expected: `47` — unchanged. If this dropped, the sed pattern was wrong; revert
with `git checkout src/App.jsx` and retry.

- [ ] **Step 3: Replace the bare `/admin` route with the catch-all**

`App.jsx:90-91` currently reads:

```jsx
          <Route path="/" element={<Navigate to="/login" replace />} />
          <Route path="/admin" element={<Navigate to="/login" replace />} />
```

(line 90 already updated by Step 1). Replace both lines with:

```jsx
          <Route path="/" element={<Navigate to="/login" replace />} />
          {/* Legacy /admin/* URLs — see LegacyAdminRedirect. Also resolves the
              deep links already stored in notification rows. */}
          <Route path="/admin/*" element={<LegacyAdminRedirect />} />
```

- [ ] **Step 4: Add the import**

`App.jsx` imports its non-lazy components at the top. After the `RoleRoute`
import on line 5, add:

```jsx
import LegacyAdminRedirect from './admin/components/LegacyAdminRedirect';
```

- [ ] **Step 5: Fix the stale comment**

`App.jsx:145` reads:

```jsx
          {/* /admin/transfer-scan retired — receiving is now handled via IST flow (/admin/ist/:id/receive) */}
```

Replace with:

```jsx
          {/* /transfer-scan retired — receiving is now handled via IST flow (/ist/:id/receive) */}
```

- [ ] **Step 6: Verify no route-form `/admin` survives**

Run:

```bash
cd frontend && grep -nE "['\"\`]/admin/|['\"\`]/admin['\"\`]" src/App.jsx
```

Expected: exactly one line — the `path="/admin/*"` catch-all from Step 3.

- [ ] **Step 7: Confirm the app still builds**

Run: `cd frontend && npx vite build`

Expected: build completes, no unresolved-import errors. A broken lazy import
from Step 1 surfaces here.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/App.jsx
git commit -m "feat(routing): drop the /admin prefix from the route table"
```

---

### Task 3: Fix the `SessionIdleGuard` predicate

This is the regression described above, not a rename. Do not simply sed this file.

**Files:**
- Modify: `frontend/src/admin/components/SessionIdleGuard.jsx:13-17`
- Test: `frontend/src/admin/components/SessionIdleGuard.paths.test.jsx` (create)

- [ ] **Step 1: Write the failing test**

The predicate is module-private, so the test asserts it through observable
behaviour: the guard only attaches activity listeners on a protected route.

Create `frontend/src/admin/components/SessionIdleGuard.paths.test.jsx`:

```jsx
import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import SessionIdleGuard from './SessionIdleGuard';

jest.mock('../context/AuthContext', () => ({
  useAuth: () => ({ clearSession: jest.fn() }),
}));

jest.mock('../services/api', () => ({
  authAPI: { logout: jest.fn() },
  getSessionTimeoutMinutes: () => 15,
  getToken: () => 'a-token',
  SESSION_TIMEOUT_CHANGED_EVENT: 'session-timeout-changed',
}));

jest.mock('../utils/toast', () => ({ toast: { warning: jest.fn() } }));

// The guard arms itself by subscribing to activity events, so a mousedown
// listener is the observable proof that it is active on a given path.
function armedAt(pathname) {
  const addSpy = jest.spyOn(window, 'addEventListener');
  render(
    <MemoryRouter initialEntries={[pathname]}>
      <SessionIdleGuard />
    </MemoryRouter>
  );
  const armed = addSpy.mock.calls.some(([event]) => event === 'mousedown');
  addSpy.mockRestore();
  return armed;
}

afterEach(() => jest.clearAllMocks());

test('arms on an unprefixed protected route', () => {
  expect(armedAt('/dashboard')).toBe(true);
});

test('arms on a nested protected route', () => {
  expect(armedAt('/ist/42/receive')).toBe(true);
});

test('arms on the change-password screen', () => {
  // Protected today: it is reachable only with a valid session.
  expect(armedAt('/change-password')).toBe(true);
});

test('does not arm on the login screen', () => {
  expect(armedAt('/login')).toBe(false);
});

test('does not arm on the forgot-password screen', () => {
  expect(armedAt('/forgot-password')).toBe(false);
});

test('does not arm on the root redirect', () => {
  expect(armedAt('/')).toBe(false);
});

test('does not arm on a Supabase PWA route', () => {
  // The PWA has its own Supabase session handling.
  expect(armedAt('/pwa/dashboard')).toBe(false);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx jest src/admin/components/SessionIdleGuard.paths.test.jsx`

Expected: FAIL — the three "arms on..." tests return `false`, because
`startsWith('/admin')` is false for every unprefixed path. This failure *is* the
regression; confirm you see it before fixing.

- [ ] **Step 3: Invert the predicate**

Replace lines 13-17 of `frontend/src/admin/components/SessionIdleGuard.jsx`:

```js
function isLaravelAdminProtectedRoute(pathname) {
  if (!pathname.startsWith('/admin')) return false;
  if (pathname === '/admin/login' || pathname === '/admin/forgot-password') return false;
  return true;
}
```

with:

```js
/**
 * Paths reachable without a session. Everything else in the admin app is behind
 * a login, so the idle timer applies.
 */
const UNPROTECTED_PATHS = new Set(['/', '/login', '/forgot-password']);

/**
 * Was `pathname.startsWith('/admin')` plus two exemptions. Once the /admin
 * prefix was dropped from every route that test matched nothing, which disabled
 * the idle logout app-wide — so the check is now an explicit deny-list.
 *
 * The Supabase PWA is excluded because it manages its own session; this guard
 * only speaks for the Laravel-backed admin app.
 */
function isLaravelAdminProtectedRoute(pathname) {
  if (pathname.startsWith('/pwa')) return false;
  return !UNPROTECTED_PATHS.has(pathname);
}
```

- [ ] **Step 4: Rename the logout redirect in the same file**

`runIdleLogout` at line 44 still navigates to the old login path. Rename it now
rather than leaving it to Task 7, so this file is internally consistent when
committed:

```bash
cd frontend && sed -i -E "s#(['\"\`])/admin/#\1/#g" src/admin/components/SessionIdleGuard.jsx
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx jest src/admin/components/SessionIdleGuard.paths.test.jsx`

Expected: PASS — 7 passed.

- [ ] **Step 6: Verify no `/admin` literal remains in the file**

Run: `cd frontend && grep -n "/admin" src/admin/components/SessionIdleGuard.jsx`

Expected: no output. The `import` statements are relative (`../context/...`), so
nothing legitimate matches here.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/components/SessionIdleGuard.jsx frontend/src/admin/components/SessionIdleGuard.paths.test.jsx
git commit -m "fix(session): keep idle logout armed after the /admin prefix removal

The guard gated on pathname.startsWith('/admin'), which became permanently
false once the prefix was dropped, silently disabling inactivity logout on
every page. Replaced with an explicit unprotected-path deny-list."
```

---

### Task 4: `RoleRoute` and its four test files

**Files:**
- Modify: `frontend/src/admin/components/RoleRoute.jsx` (7 occurrences)
- Test: `RoleRoute.test.jsx`, `RoleRoute.mustChangePassword.test.jsx`, `RoleRoute.permissionGated.test.jsx`, `RoleRoute.reportIncident.test.jsx`

- [ ] **Step 1: Update the tests first**

These tests assert real redirect targets, so they must be moved to the new
paths before the source — then they fail for the right reason.

```bash
cd frontend && sed -i -E "s#(['\"\`])/admin/#\1/#g" \
  src/admin/components/RoleRoute.test.jsx \
  src/admin/components/RoleRoute.mustChangePassword.test.jsx \
  src/admin/components/RoleRoute.permissionGated.test.jsx \
  src/admin/components/RoleRoute.reportIncident.test.jsx
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd frontend && npx jest src/admin/components/RoleRoute`

Expected: FAIL. The tests now route to `/items` and `/dashboard` while
`RoleRoute` still redirects to `/admin/dashboard`, so the expected
`Dashboard Page` text is absent.

- [ ] **Step 3: Rename the paths in `RoleRoute.jsx`**

```bash
cd frontend && sed -i -E "s#(['\"\`])/admin/#\1/#g" src/admin/components/RoleRoute.jsx
```

This covers all 7 occurrences, including the
`location.pathname === '/admin/dashboard'` comparison on line 25 that lets the
dashboard render while permissions hydrate, and the comment on line 33.

- [ ] **Step 4: Run them to verify they pass**

Run: `cd frontend && npx jest src/admin/components/RoleRoute`

Expected: PASS — all four files green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/components/RoleRoute.jsx frontend/src/admin/components/RoleRoute*.test.jsx
git commit -m "refactor(routing): drop the /admin prefix from RoleRoute"
```

---

### Task 5: The `api.js` session redirects

Called out separately because these are `window.location.href` assignments, not
router navigation — a miss here sends an expired session to a blank page, which
reads as an unrelated bug.

**Files:**
- Modify: `frontend/src/admin/services/api.js:282,289,334`

- [ ] **Step 1: Rename the paths**

```bash
cd frontend && sed -i -E "s#(['\"\`])/admin/#\1/#g" src/admin/services/api.js
```

- [ ] **Step 2: Verify all three redirects moved**

Run: `cd frontend && grep -n "window.location.href" src/admin/services/api.js`

Expected three lines, reading `'/login'`, `'/change-password'`, and `'/login'` —
no `/admin`.

- [ ] **Step 3: Confirm no API endpoint string was touched**

Run: `cd frontend && git diff --stat src/admin/services/api.js`

Expected: 3 insertions, 3 deletions. More than that means an endpoint literal
was caught — inspect `git diff` and revert any change to an `apiRequest(...)`
path.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "refactor(routing): point the session redirects at the unprefixed paths"
```

---

### Task 6: Permission and navigation maps

These are matched against `location.pathname`. A stale key does not throw — the
page silently redirects to the dashboard — so the tests matter here.

**Files:**
- Modify: `frontend/src/admin/utils/navBundles.js` (30), `roles.js` (39), `navConfigureMap.js` (10), `alertTarget.js` (9), `sidebarBuilder.js` (1)
- Test: `navBundles.test.js`, `navConfigureMap.test.js`, `alertTarget.test.js`

- [ ] **Step 1: Update the tests first**

```bash
cd frontend && sed -i -E "s#(['\"\`])/admin/#\1/#g" \
  src/admin/utils/navBundles.test.js \
  src/admin/utils/navConfigureMap.test.js \
  src/admin/utils/alertTarget.test.js
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd frontend && npx jest src/admin/utils`

Expected: FAIL — the tests look up `/draft-po-creator` and similar while the
maps are still keyed on `/admin/draft-po-creator`, so lookups return undefined.

- [ ] **Step 3: Rename the paths in the five source files**

```bash
cd frontend && sed -i -E "s#(['\"\`])/admin/#\1/#g" \
  src/admin/utils/navBundles.js \
  src/admin/utils/roles.js \
  src/admin/utils/navConfigureMap.js \
  src/admin/utils/alertTarget.js \
  src/admin/utils/sidebarBuilder.js
```

- [ ] **Step 4: Run them to verify they pass**

Run: `cd frontend && npx jest src/admin/utils`

Expected: PASS.

- [ ] **Step 5: Spot-check the maps**

Run:

```bash
cd frontend && grep -n "routePath: '/dashboard'" src/admin/utils/navBundles.js \
  && grep -n "'/dashboard':" src/admin/utils/roles.js \
  && grep -n "ROLE_OPEN_PATHS" src/admin/utils/sidebarBuilder.js
```

Expected: the `Dashboard` bundle, the `ROUTE_ACCESS` dashboard entry, and
`ROLE_OPEN_PATHS = new Set(['/account'])`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/utils/
git commit -m "refactor(routing): rekey the permission and nav maps without /admin"
```

---

### Task 7: The remaining frontend literals

Everything not already handled: ~36 page/component files and 8 remaining test
files. `Sidebar.jsx` (56 occurrences) and `Dashboard.jsx` (17) are the bulk.

**Files:**
- Modify: all remaining files under `frontend/src` carrying a quoted `/admin/` literal

- [ ] **Step 1: Rename across everything that remains**

Already-committed files are idempotent under this pattern, so a repo-wide pass
is safe:

```bash
cd frontend && grep -rlE "['\"\`]/admin/" src --include=*.jsx --include=*.js \
  | xargs sed -i -E "s#(['\"\`])/admin/#\1/#g"
```

- [ ] **Step 2: Verify nothing route-shaped survives**

Run:

```bash
cd frontend && grep -rnE "['\"\`]/admin/|['\"\`]/admin['\"\`]" src --include=*.jsx --include=*.js
```

Expected: exactly one line — `path="/admin/*"` in `src/App.jsx`.

- [ ] **Step 3: Verify the module and asset imports are intact**

Run:

```bash
cd frontend && grep -rc "admin/styles/" src --include=*.jsx --include=*.js | grep -v ":0$" | awk -F: '{s+=$2} END {print s}'
```

Expected: `12` — the stylesheet imports are untouched. Then confirm the build:

Run: `cd frontend && npx vite build`

Expected: build completes with no unresolved imports.

- [ ] **Step 4: Run the whole frontend suite**

Run: `cd frontend && npx jest`

Expected: PASS. Any failure here is a test still asserting an old path — fix
the test's path, not the source.

- [ ] **Step 5: Commit**

Stage the renamed files **explicitly**, not with `git add frontend/src`:

```bash
cd frontend && git add $(git diff --name-only -- src)
cd .. && git commit -m "refactor(routing): drop the /admin prefix from the remaining pages and tests"
```

`git add frontend/src` is unsafe here. The working tree carries unrelated
in-progress scan-feedback changes (`ScanFeedback.jsx`, `ScanFeedback.test.jsx`,
`scan_feedback.css` — a 2s fade-out animation) that a directory-wide add would
sweep into this routing commit. Those three files contain no `/admin` literal,
so the rename never modifies them; staging only what the rename actually
touched keeps them out. Verify with `git status --porcelain` before committing
that those three remain unstaged.

---

### Task 8: Migrate `nav_permission_bundles.route_path`

The riskiest task: the seeder and the migration must land together. The seeder
calls `updateOrCreate(['route_path' => $routePath])`, so an updated seeder run
against an un-migrated table matches nothing and **duplicates all 31 bundles**
instead of updating them.

**Files:**
- Create: `back-end/database/migrations/2026_10_13_000001_strip_admin_prefix_from_nav_bundles.php`
- Modify: `back-end/database/seeders/NavBundleSeeder.php` (31 paths)
- Modify: `back-end/tests/Feature/Permissions/NavBundleTest.php`

- [ ] **Step 1: Write the failing test**

Append to `back-end/tests/Feature/Permissions/NavBundleTest.php`, inside the
test class:

```php
    public function test_no_active_bundle_retains_the_admin_prefix(): void
    {
        $prefixed = \App\Models\NavPermissionBundle::where('is_active', true)
            ->where('route_path', 'like', '/admin/%')
            ->pluck('route_path')
            ->all();

        $this->assertSame(
            [],
            $prefixed,
            'These nav bundles still carry the /admin prefix, so RoleRoute will '
            . 'not match them against the router paths: ' . implode(', ', $prefixed)
        );
    }

    public function test_every_active_bundle_route_path_is_absolute(): void
    {
        // Guards against a double-strip turning '/admin/items' into 'items'.
        $relative = \App\Models\NavPermissionBundle::where('is_active', true)
            ->where('route_path', 'not like', '/%')
            ->pluck('route_path')
            ->all();

        $this->assertSame([], $relative, 'Bundle route paths must start with "/".');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd back-end && php artisan test --filter=test_no_active_bundle_retains_the_admin_prefix`

Expected: FAIL — all 31 seeded paths are listed as still prefixed.

- [ ] **Step 3: Write the migration**

Create `back-end/database/migrations/2026_10_13_000001_strip_admin_prefix_from_nav_bundles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the /admin prefix from nav_permission_bundles.route_path.
 *
 * NavBundleController serves route_path straight to the frontend, and RoleRoute
 * matches those values against location.pathname. The router lost its /admin
 * prefix, so this column has to move with it or every sidebar page fails its
 * permission check and bounces to the dashboard.
 *
 * Prefix-guarded, so re-running is a no-op rather than a second strip.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('nav_permission_bundles')
            ->where('route_path', 'like', '/admin/%')
            ->update([
                'route_path' => DB::raw("CONCAT('/', SUBSTRING(route_path, 8))"),
            ]);
    }

    public function down(): void
    {
        DB::table('nav_permission_bundles')
            ->where('route_path', 'like', '/%')
            ->where('route_path', 'not like', '/admin/%')
            ->update([
                'route_path' => DB::raw("CONCAT('/admin', route_path)"),
            ]);
    }
};
```

`SUBSTRING(route_path, 8)` drops the 7 characters of `/admin/` and keeps the
rest, so `/admin/items` becomes `/items`. MySQL's `SUBSTRING` is 1-indexed.

- [ ] **Step 4: Update the seeder in the same change**

```bash
cd back-end && sed -i -E "s#'/admin/#'/#g" database/seeders/NavBundleSeeder.php
```

Verify all 31 moved and none were missed:

```bash
cd back-end && grep -c "'/admin/" database/seeders/NavBundleSeeder.php
```

Expected: `0`.

- [ ] **Step 5: Apply the migration to both databases**

```bash
cd back-end && php artisan migrate --force
```

Expected: `2026_10_13_000001_strip_admin_prefix_from_nav_bundles ... DONE`.

Then the test database, or the backend tests run against the old schema:

```bash
cd back-end && DB_DATABASE=airkingV5_test php artisan migrate --force
```

Confirm it landed on the right database:

```bash
cd back-end && DB_DATABASE=airkingV5_test php artisan tinker --execute="echo DB::connection()->getDatabaseName().': '.DB::table('nav_permission_bundles')->where('route_path','like','/admin/%')->count().' still prefixed';"
```

Expected: `airkingV5_test: 0 still prefixed`.

- [ ] **Step 6: Confirm the rows moved and nothing duplicated**

```bash
cd back-end && php artisan tinker --execute="\$m=App\Models\NavPermissionBundle::class; echo \$m::count().' bundles, '.\$m::where('route_path','like','/admin/%')->count().' still prefixed, '.\$m::distinct('route_path')->count('route_path').' distinct paths';"
```

Expected: `31 bundles, 0 still prefixed, 31 distinct paths`. A count above 31 means
the seeder ran before the migration and duplicated rows — restore from backup or
delete the prefixed duplicates before continuing.

- [ ] **Step 7: Run the backend nav-bundle tests**

Run: `cd back-end && php artisan test --filter=NavBundleTest`

Expected: PASS. The pre-existing assertion that each bundle has exactly one
required permission is the duplication canary — it must stay green.

- [ ] **Step 8: Commit**

```bash
git add back-end/database/migrations/2026_10_13_000001_strip_admin_prefix_from_nav_bundles.php back-end/database/seeders/NavBundleSeeder.php back-end/tests/Feature/Permissions/NavBundleTest.php
git commit -m "feat(nav): strip the /admin prefix from nav bundle route paths

Migration and seeder move together on purpose: the seeder upserts on
route_path, so an updated seeder against an un-migrated table would duplicate
all 31 bundles instead of updating them."
```

---

### Task 9: Backend-generated deep links

**Files:**
- Modify: `back-end/app/Http/Controllers/Api/AlertSettingsController.php:117,124,131,138,145,152,159`
- Modify: `back-end/app/Services/WarehouseNotificationService.php:37,61`

- [ ] **Step 1: Rename the links**

```bash
cd back-end && sed -i -E "s#'/admin/#'/#g" \
  app/Http/Controllers/Api/AlertSettingsController.php \
  app/Services/WarehouseNotificationService.php
```

- [ ] **Step 2: Verify**

Run:

```bash
cd back-end && grep -rn "'/admin/" app/
```

Expected: no output.

Then confirm the nine links read correctly:

```bash
cd back-end && grep -nE "'link'|'/inventory-operation|'/transfers" app/Http/Controllers/Api/AlertSettingsController.php app/Services/WarehouseNotificationService.php
```

Expected: `/purchase-orders`, `/purchase-requests?tab=create`, `/items`,
`/draft-po-creator`, `/purchase-orders`, `/purchase-requests`, `/inventory`,
`/inventory-operation?mode=receive-po`, `/transfers?tab=...`.

- [ ] **Step 3: Confirm nothing else in the backend broke**

Run: `cd back-end && php artisan test --filter=Rbac`

Expected: PASS. (`RbacRouteMatrixTest` covers the API route matrix; these are
notification payload strings, so it should be unaffected — confirm rather than
assume.)

- [ ] **Step 4: Commit**

```bash
git add back-end/app/Http/Controllers/Api/AlertSettingsController.php back-end/app/Services/WarehouseNotificationService.php
git commit -m "fix(notifications): generate deep links without the /admin prefix

Links already written to notification rows keep the old prefix; LegacyAdminRedirect
resolves those."
```

---

### Task 10: Documentation and full verification

**Files:**
- Modify: `frontend/src/admin/README.md`

- [ ] **Step 1: Update the documented route list**

```bash
cd frontend && sed -i -E "s#\`/admin/#\`/#g" src/admin/README.md
```

Verify:

```bash
cd frontend && grep -n "/admin/" src/admin/README.md
```

Expected: no output, or only prose explaining the legacy redirect.

- [ ] **Step 2: Final repo-wide sweep**

```bash
cd "$(git rev-parse --show-toplevel)" && grep -rnE "['\"\`]/admin/|['\"\`]/admin['\"\`]" frontend/src back-end/app back-end/database/seeders --include=*.jsx --include=*.js --include=*.php
```

Expected: exactly one line — `path="/admin/*"` in `frontend/src/App.jsx`.
Historical migrations under `back-end/database/migrations/` are deliberately
excluded; they keep their literals.

- [ ] **Step 3: Full frontend suite**

Run: `cd frontend && npx jest`

Expected: PASS, with the same test-file count as before this work plus the two
new files from Tasks 1 and 3.

- [ ] **Step 4: Full backend suite**

Run: `cd back-end && php artisan test`

Expected: PASS. Per the project notes the local test database is populated and
runs Berlin time while CI's is empty and UTC, so a green local run is necessary
but not sufficient — note any pre-existing failures rather than attributing them
to this change.

- [ ] **Step 5: Manual verification of the four landmines**

Start the app (`cd frontend && npx vite` with the Laravel API running), then check:

1. **Login URL** — visiting `/` lands on `/login`, and the address bar shows no `/admin`.
2. **Legacy redirect** — entering `/admin/dashboard` lands on `/dashboard`, and the back button does not bounce.
3. **Expired session** — clear the token from storage, trigger any API call, confirm the redirect reaches `/login` rather than a blank page.
4. **Dashboard hydration** — log in fresh and confirm the dashboard renders rather than sitting on "Checking access…".
5. **Idle timer** — sit on `/login` and confirm no inactivity logout fires; then on `/dashboard` confirm the timer is armed (shorten the session timeout in system settings to test quickly).
6. **Sidebar navigation** — as a non-admin role, open a permission-gated page from the sidebar and confirm it loads instead of redirecting to the dashboard.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/admin/README.md
git commit -m "docs(admin): update the documented routes after the prefix removal"
```

---

## Amendments During Execution

Recorded so the plan stays truthful against what was actually built.

- **Task 7's sweep had to exempt three files — the discriminator is blind to intent.**
  The "quote immediately followed by `/admin/`" rule separates route literals from
  module imports, but it cannot tell a *current* route literal from an
  *intentional legacy* one. Re-running the sweep over `App.jsx` matched
  `path="/admin/*"` and rewrote the legacy catch-all to `path="/*"`, silently
  destroying the redirect Task 1 exists to provide — and Task 7 Step 2's own
  expectation ("exactly one line: `path="/admin/*"`") contradicted its Step 1
  sed, which had already eaten that line. It also rewrote every legacy-URL input
  in `LegacyAdminRedirect.test.jsx` (10 tests failed) and a comment example in
  `LegacyAdminRedirect.jsx`.

  **Exempt these three from any future `/admin` sweep:** `frontend/src/App.jsx`
  (the catch-all route line only), `LegacyAdminRedirect.jsx`,
  `LegacyAdminRedirect.test.jsx`. Their `/admin` strings are the subject matter,
  not stale paths.

  Also: `git diff --name-only -- src` run from `frontend/` returns
  repo-root-relative paths already, so the plan's `sed 's#^#frontend/#'` prefix
  in the staging command double-prefixes and fails. Drop it.

- **A subagent's `git stash` / `git stash pop` resurrected a pre-existing stash.**
  While verifying whether a failing suite was pre-existing, a subagent ran a
  stash round-trip on what it believed was a clean tree. The stash list is now
  empty and the working tree gained real scan-feedback changes (a 2s fade-out:
  `ScanFeedback.jsx`, `ScanFeedback.test.jsx`, `scan_feedback.css`) — i.e. it
  popped and dropped a stash entry that predated this work. No content was lost,
  but the "saved for later" marker is gone and the changes are loose in the tree.
  Mitigation: Task 7 stages explicitly instead of `git add frontend/src`.
  **Lesson for future subagent prompts: forbid `git stash` outright** — use
  `git worktree` or `git show <sha>:<path>` to inspect another tree state.

- **Tasks 4 and 6 were merged — the plan's decomposition was wrong.**
  `RoleRoute` matches `location.pathname` against the keys in
  `navBundles.PERMISSION_GATED_ROUTES` and `roles.ROUTE_ACCESS`, so the
  component and those maps share one contract. Renaming either alone makes
  every permission-gated lookup miss, `requiredPerm` come back `undefined`, and
  execution fall through to `canAccessRoute`, which redirects to the dashboard
  regardless of granted permissions. `RoleRoute.permissionGated.test.jsx` and
  `RoleRoute.reportIncident.test.jsx` exercise the real map (only `AuthContext`
  is mocked) and caught this: 3 tests failed in the intermediate state. Splitting
  them could only ever produce a broken commit, so Task 6 was folded into Task 4
  as a single 13-file commit. The alternative — mocking the maps in those two
  tests — was rejected because it would weaken genuine integration coverage to
  fit an artificial task boundary.

- **Frontend test baseline is NOT green.** Measured on the tree before any
  rename landed: `Test Suites: 2 failed, 68 passed, 70 total` /
  `Tests: 6 failed, 498 passed, 504 total`. The two failing suites are
  `src/admin/components/PickScanner.test.jsx` and
  `src/admin/pages/transfers/ReceivePanel.test.jsx` — scan-feedback assertions,
  last touched in `c734817`. Neither references a `/admin` path, so neither is
  related to this work. Where Task 7 Step 4 and Task 10 Step 3 say the suite
  must PASS, read that as: **exactly these 2 suites / 6 tests may fail and no
  others**. A third failing suite means the rename broke something.

- **Tech stack corrected.** This plan originally said react-router-dom v6; the
  project pins `^7.12.0` and resolves 7.18.4. The splat/`Navigate`/`useParams`
  APIs used here behave the same in both, so no task changed — but the comment
  in Task 1's component said "v6" and was corrected.
- **Task 1's component was hardened after review.** The version in this plan
  builds its target by string concatenation, which has two defects, both
  reproduced: `/admin//dashboard` yields `//dashboard`, a protocol-relative URL
  that throws `External navigation is not allowed` and — with no error boundary
  in `App.jsx` — blanks the page; and a percent-encoded `#` inside a path
  segment gets re-parsed out of the pathname into the hash. The shipped version
  collapses runs of slashes and passes `to` in `{ pathname, search, hash }`
  object form, with four added tests. See the follow-up commit on Task 1.

## Out of Scope

Carried from the spec, recorded so neither is mistaken for an oversight:

- **Opaque record IDs.** Deferred. The IST routes are the only URLs carrying a raw database ID, and those pages 404 against the backend today, so there is no working surface to protect.
- **The IST endpoint mismatch.** `istAPI` (`frontend/src/admin/services/api.js:1214-1239`) calls `/ist/*` endpoints that do not exist; the live ones are `/transfers/*` on `TransferController`. "Interbranch Transfers" is an active sidebar entry, so this is user-visible. Unchanged by this plan — the IST route paths are renamed like any other and the pages stay exactly as broken as they are now.
- **A central route-constants module.** Extracting the path literals into a shared `paths.js` would turn this mechanical rename into a refactor touching every page.
