# Page-Load Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make admin page navigation feel near-instant by serving the API on a concurrent web server (XAMPP Apache) and cutting the number of requests fired per page.

**Architecture:** Move the backend off single-threaded `php artisan serve` onto Apache + mod_php with OPcache and Laravel config/route caches (Approach A). On the frontend, stop refetching permissions on every navigation and collapse three layout badge polls into one endpoint (Approach C).

**Tech Stack:** Laravel 12 (PHP 8.2), React 18 (CRA), MariaDB via XAMPP, Apache (XAMPP), Sanctum.

**Spec:** `docs/superpowers/specs/2026-06-23-page-load-performance-design.md`

---

## File Map

**Backend**
- Modify: `back-end/app/Http/Controllers/Api/DashboardController.php` — add `layoutSummary()` endpoint (reuses existing private `awaitingTransferCounts()`).
- Modify: `back-end/routes/api.php` — register `GET /api/layout-summary`.
- Create: `back-end/database/migrations/2026_06_23_000000_add_performance_indexes.php` — additive indexes for hot list queries.
- Create: `back-end/tests/Feature/LayoutSummaryTest.php` — endpoint test.

**Frontend**
- Modify: `frontend/src/admin/services/api.js` — new API base default (Apache, no :8000) + `layoutAPI.getSummary()`.
- Modify: `frontend/.env` — `REACT_APP_API_URL` default off port 8000.
- Create: `frontend/src/admin/utils/permissionCache.js` — pure `shouldRefreshPermissions()` helper.
- Create: `frontend/src/admin/utils/permissionCache.test.js` — unit test for the helper.
- Modify: `frontend/src/admin/context/AuthContext.jsx` — SWR + throttled permission refresh.
- Modify: `frontend/src/admin/components/AdminLayout.jsx` — remove per-nav permission refetch; collapse 3 polls into `layoutAPI.getSummary()`.

**Ops / config (host machine)**
- Modify: `C:\XAMPP1\apache\conf\extra\httpd-vhosts.conf` — Airking vhost.
- Modify: `C:\Windows\System32\drivers\etc\hosts` — `airking.local`.
- Modify: `C:\XAMPP1\php\php.ini` — OPcache.

---

## Task 1: Apache virtual host for the Laravel API

**Files:**
- Modify: `C:\XAMPP1\apache\conf\extra\httpd-vhosts.conf`
- Modify: `C:\Windows\System32\drivers\etc\hosts`

This is host configuration, not code — verify by HTTP request rather than a unit test.

- [ ] **Step 1: Confirm mod_rewrite is enabled**

Open `C:\XAMPP1\apache\conf\httpd.conf` and confirm this line is NOT commented:

```
LoadModule rewrite_module modules/mod_rewrite.so
```

If it has a leading `#`, remove it.

- [ ] **Step 2: Add the vhost**

Append to `C:\XAMPP1\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName airking.local
    DocumentRoot "C:/XAMPP1/htdocs/Airking_Inventory/back-end/public"
    <Directory "C:/XAMPP1/htdocs/Airking_Inventory/back-end/public">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog "logs/airking-error.log"
    CustomLog "logs/airking-access.log" common
</VirtualHost>
```

Note: if port 80 is already taken by another vhost you rely on, change both `*:80`
and the `Listen`/usage to `*:8080` and use `airking.local:8080` everywhere below.

- [ ] **Step 3: Map the hostname**

Add to `C:\Windows\System32\drivers\etc\hosts` (requires admin editor):

```
127.0.0.1  airking.local
```

- [ ] **Step 4: Restart Apache and verify the API responds**

Restart Apache from the XAMPP Control Panel, then run:

```bash
curl -s http://airking.local/api | head -c 200
```

Expected: a JSON body (e.g. `{"message":...}` or `{"version":...}`) — NOT an Apache
404/403 page. A 404 HTML page means `.htaccess`/mod_rewrite is not active — recheck
Steps 1–2.

- [ ] **Step 5: Commit (none required)**

No repo files changed in this task. Continue.

---

## Task 2: Enable OPcache

**Files:**
- Modify: `C:\XAMPP1\php\php.ini`

- [ ] **Step 1: Set OPcache options**

In `C:\XAMPP1\php\php.ini`, ensure these are present and uncommented (edit existing
keys rather than duplicating them):

```ini
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

`validate_timestamps=1` keeps dev sane (edited PHP is picked up). Production would set
it to `0`.

- [ ] **Step 2: Restart Apache and verify OPcache is on**

Create a throwaway file `back-end/public/_opcache_check.php`:

```php
<?php echo opcache_get_status() ? 'OPCACHE ON' : 'OPCACHE OFF';
```

Run:

```bash
curl -s http://airking.local/_opcache_check.php
```

Expected: `OPCACHE ON`.

- [ ] **Step 3: Remove the check file**

```bash
rm "C:/XAMPP1/htdocs/Airking_Inventory/back-end/public/_opcache_check.php"
```

- [ ] **Step 4: Commit (none required)**

No repo files changed. Continue.

---

## Task 3: Point the frontend at the Apache host

**Files:**
- Modify: `frontend/src/admin/services/api.js:6` (the `apiPort` default and base resolution)
- Modify: `frontend/.env`

- [ ] **Step 1: Update the `.env` default**

In `frontend/.env`, change the active line:

```
REACT_APP_API_URL=http://airking.local/api
```

(from `http://127.0.0.1:8000/api`). Leave the commented guidance lines as-is.

- [ ] **Step 2: Update the LAN port default in `api.js`**

In `frontend/src/admin/services/api.js`, change line 6 so LAN auto-detection no longer
forces `:8000` (Apache is on 80):

```js
const apiPort = String(process.env.REACT_APP_API_PORT || '80').replace(/^:+/, '');
```

Then in `getApiBaseUrl()`, the private-LAN branch (around line 52-53) must omit the
port when it is `80`. Replace that block with:

```js
    const proto = String(process.env.REACT_APP_API_HTTPS || '').toLowerCase() === 'true' ? 'https:' : 'http:';
    const portSuffix = (apiPort === '80' || apiPort === '') ? '' : `:${apiPort}`;
    return trimSlash(`${proto}//${h}${portSuffix}/api`);
```

- [ ] **Step 3: Verify the resolved URL in the browser**

Start the React dev server (`cd frontend && npm start`), open the app on the desktop,
log in, and check the console — `[API] GET ...` lines should read
`http://airking.local/api/...` (or `http://<LAN-IP>/api/...` on a phone), with no
`:8000`.

Expected: requests succeed (200s) against the Apache host.

- [ ] **Step 4: Commit**

```bash
git add frontend/.env frontend/src/admin/services/api.js
git commit -m "perf(frontend): point API base at Apache host (drop :8000 default)"
```

---

## Task 4: Cache Laravel config and routes

**Files:** none (commands + doc note)

- [ ] **Step 1: Build the caches**

```bash
cd "C:/XAMPP1/htdocs/Airking_Inventory/back-end"
php artisan config:cache
php artisan route:cache
```

Expected: `Configuration cached successfully.` and `Routes cached successfully.`

- [ ] **Step 2: Verify the app still serves**

```bash
curl -s http://airking.local/api | head -c 200
```

Expected: same JSON as Task 1 Step 4 (no 500).

- [ ] **Step 3: Document the dev caveat**

Append to `back-end/README.md` (create the file if absent) a short note:

```markdown
## Performance (XAMPP Apache)

The API is served by Apache (vhost `airking.local`), not `php artisan serve`.
After editing `.env` or routes, clear the caches before testing:

    php artisan config:clear
    php artisan route:clear

Rebuild with `php artisan config:cache` / `php artisan route:cache` when stable.
```

- [ ] **Step 4: Commit**

```bash
git add back-end/README.md
git commit -m "docs(backend): note Apache serving + config/route cache workflow"
```

---

## Task 5: Backend `GET /api/layout-summary` endpoint

Returns the badge/pending/awaiting counts the header needs in ONE response, reusing
the existing alert-settings computation and the private `awaitingTransferCounts()`
helper (DRY — no duplicated count logic).

**Files:**
- Test: `back-end/tests/Feature/LayoutSummaryTest.php`
- Modify: `back-end/app/Http/Controllers/Api/DashboardController.php`
- Modify: `back-end/routes/api.php`

- [ ] **Step 1: Write the failing test**

Create `back-end/tests/Feature/LayoutSummaryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LayoutSummaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_layout_summary_returns_badge_and_awaiting_counts(): void
    {
        $user = User::query()->whereNotNull('role_id')->first();
        $this->assertNotNull($user, 'Seeded user required for this test.');

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/layout-summary');

        $res->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'badge_count',
                    'unread_count',
                    'total_pending',
                    'awaiting_fulfillment',
                    'awaiting_receipt',
                ],
            ]);
    }

    public function test_layout_summary_requires_auth(): void
    {
        $this->getJson('/api/layout-summary')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd "C:/XAMPP1/htdocs/Airking_Inventory/back-end"
php artisan config:clear
php artisan test --filter=LayoutSummaryTest
```

Expected: FAIL — route `/api/layout-summary` not defined (404, assertOk fails).

- [ ] **Step 3: Add the controller method**

In `back-end/app/Http/Controllers/Api/DashboardController.php`, add this public method
(place it just after `stats()`, near line 46). It delegates to the existing
`AlertSettingsController::index()` for the badge/pending numbers and reuses the private
`awaitingTransferCounts()` already defined in this controller:

```php
    /**
     * One-shot payload for the admin header badges.
     * Combines alert badge/unread/pending counts with awaiting-transfer counts so the
     * layout needs a single request instead of three.
     */
    public function layoutSummary(Request $request)
    {
        $alertJson = app(\App\Http\Controllers\Api\AlertSettingsController::class)
            ->index($request);
        $alert = json_decode($alertJson->getContent(), true)['data'] ?? [];

        $awaiting = $this->awaitingTransferCounts();

        return response()->json([
            'success' => true,
            'data' => [
                'badge_count'          => $alert['badge_count']   ?? 0,
                'unread_count'         => $alert['unread_count']  ?? 0,
                'total_pending'        => $alert['total_pending'] ?? 0,
                'awaiting_fulfillment' => $awaiting['fulfillment'],
                'awaiting_receipt'     => $awaiting['receipt'],
            ],
        ]);
    }
```

- [ ] **Step 4: Register the route**

In `back-end/routes/api.php`, inside the same `auth:sanctum` group that contains the
existing dashboard routes (search for `[DashboardController::class, 'stats']`), add:

```php
    Route::get('/layout-summary', [DashboardController::class, 'layoutSummary']);
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
php artisan test --filter=LayoutSummaryTest
```

Expected: PASS (2 tests).

- [ ] **Step 6: Run the full backend suite (no regressions)**

```bash
php artisan test
```

Expected: your existing green baseline plus the 2 new tests, no new failures.

- [ ] **Step 7: Rebuild the route cache**

```bash
php artisan route:cache
```

- [ ] **Step 8: Commit**

```bash
git add back-end/app/Http/Controllers/Api/DashboardController.php back-end/routes/api.php back-end/tests/Feature/LayoutSummaryTest.php
git commit -m "perf(api): add GET /layout-summary consolidating header badge counts"
```

---

## Task 6: Frontend — use the single layout-summary call

**Files:**
- Modify: `frontend/src/admin/services/api.js` (add `layoutAPI`)
- Modify: `frontend/src/admin/components/AdminLayout.jsx:71-128` (replace 3 effects)

- [ ] **Step 1: Add `layoutAPI` to the service**

In `frontend/src/admin/services/api.js`, add near the `dashboardAPI` block:

```js
/* ======================
   LAYOUT SUMMARY API (header badges in one call)
====================== */
export const layoutAPI = {
  getSummary: async () => apiRequest('/layout-summary'),
};
```

- [ ] **Step 2: Replace the three polling effects with one**

In `frontend/src/admin/components/AdminLayout.jsx`, change the import on line 4 from:

```js
import { authAPI, dashboardAPI, alertsAPI } from '../services/api';
```

to:

```js
import { authAPI, layoutAPI } from '../services/api';
```

Then DELETE the three `useEffect` blocks at lines 71-128 (the `syncUnread` /
`fetchPending` / `fetchIstCounts` effects) and replace them with a single effect:

```js
  // One request feeds all header badges (unread, pending approvals, awaiting IST)
  useEffect(() => {
    let mounted = true;
    const syncBadges = async () => {
      try {
        const res = await layoutAPI.getSummary();
        const d = res?.data ?? {};
        if (!mounted) return;
        setUnreadNotifications(Number(d.badge_count ?? 0));
        setPendingApprovalCount(Number(d.total_pending ?? 0));
        setAwaitingIstCount(Number(d.awaiting_fulfillment ?? 0) + Number(d.awaiting_receipt ?? 0));
      } catch (_) {
        // leave existing counts on transient failure
      }
    };
    syncBadges();
    const interval = setInterval(syncBadges, 60000);
    window.addEventListener('approval-notifications-updated', syncBadges);
    window.addEventListener('storage', syncBadges);
    return () => {
      mounted = false;
      clearInterval(interval);
      window.removeEventListener('approval-notifications-updated', syncBadges);
      window.removeEventListener('storage', syncBadges);
    };
  }, []);
```

- [ ] **Step 3: Verify in the browser Network tab**

Reload the app, open DevTools → Network, navigate to a page. Confirm exactly ONE
`layout-summary` request fires for the header (no `alerts/settings`,
`dashboard/pending-approvals`, or `dashboard/stats` calls from the layout), and the
badge numbers still render correctly.

Expected: badge counts identical to before; 2 fewer requests per layout load.

- [ ] **Step 4: Run the frontend test suite (no regressions)**

```bash
cd "C:/XAMPP1/htdocs/Airking_Inventory/frontend"
CI=true npm test
```

Expected: same baseline (the pre-existing `App.test.jsx` "learn react" boilerplate
failure is unrelated; no NEW failures).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/admin/services/api.js frontend/src/admin/components/AdminLayout.jsx
git commit -m "perf(frontend): collapse 3 header badge polls into one /layout-summary call"
```

---

## Task 7: Frontend — stop refetching permissions on every navigation

Hydrate permissions from `sessionStorage` immediately, and refresh in the background
only when stale (60s), on window focus, and on mount — NOT on every route change. This
keeps mid-session revoke working (within ~60s / on focus) while removing a blocking
call from each navigation.

**Files:**
- Test: `frontend/src/admin/utils/permissionCache.test.js`
- Create: `frontend/src/admin/utils/permissionCache.js`
- Modify: `frontend/src/admin/context/AuthContext.jsx`
- Modify: `frontend/src/admin/components/AdminLayout.jsx:252-256` (remove per-nav effect)

- [ ] **Step 1: Write the failing test for the staleness helper**

Create `frontend/src/admin/utils/permissionCache.test.js`:

```js
import { shouldRefreshPermissions } from './permissionCache';

describe('shouldRefreshPermissions', () => {
  const TTL = 60000;

  it('refreshes when never fetched', () => {
    expect(shouldRefreshPermissions(null, 1_000_000, TTL)).toBe(true);
  });

  it('does not refresh within the TTL window', () => {
    expect(shouldRefreshPermissions(1_000_000, 1_030_000, TTL)).toBe(false);
  });

  it('refreshes once the TTL has elapsed', () => {
    expect(shouldRefreshPermissions(1_000_000, 1_061_000, TTL)).toBe(true);
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd "C:/XAMPP1/htdocs/Airking_Inventory/frontend"
CI=true npm test -- --testPathPattern=permissionCache
```

Expected: FAIL — `permissionCache` module not found.

- [ ] **Step 3: Implement the helper**

Create `frontend/src/admin/utils/permissionCache.js`:

```js
export const PERMISSIONS_CACHE_KEY = 'permissions_cache';
export const PERMISSIONS_TTL_MS = 60000;

/**
 * @param {number|null} lastFetchedAt epoch ms of the last successful fetch (or null)
 * @param {number} now epoch ms now
 * @param {number} ttlMs freshness window
 * @returns {boolean} true if a background refresh should run
 */
export const shouldRefreshPermissions = (lastFetchedAt, now, ttlMs = PERMISSIONS_TTL_MS) => {
  if (lastFetchedAt == null) return true;
  return now - lastFetchedAt >= ttlMs;
};

/** @returns {{ map: object, at: number } | null} */
export const readCachedPermissions = () => {
  try {
    const raw = sessionStorage.getItem(PERMISSIONS_CACHE_KEY);
    const parsed = raw ? JSON.parse(raw) : null;
    if (parsed && typeof parsed.map === 'object') return parsed;
  } catch (_) {}
  return null;
};

export const writeCachedPermissions = (map, at) => {
  try {
    sessionStorage.setItem(PERMISSIONS_CACHE_KEY, JSON.stringify({ map, at }));
  } catch (_) {}
};

export const clearCachedPermissions = () => {
  try { sessionStorage.removeItem(PERMISSIONS_CACHE_KEY); } catch (_) {}
};
```

- [ ] **Step 4: Run it to verify it passes**

```bash
CI=true npm test -- --testPathPattern=permissionCache
```

Expected: PASS (3 tests).

- [ ] **Step 5: Wire SWR + throttle into AuthContext**

In `frontend/src/admin/context/AuthContext.jsx`:

Add the import after line 2:

```js
import {
  shouldRefreshPermissions,
  readCachedPermissions,
  writeCachedPermissions,
  clearCachedPermissions,
  PERMISSIONS_TTL_MS,
} from '../utils/permissionCache';
```

Replace the `permissions` state init (line 16) so it hydrates from cache immediately:

```js
  const [permissions, setPermissions] = useState(() => readCachedPermissions()?.map ?? null);
  const lastPermFetchRef = useRef(readCachedPermissions()?.at ?? null);
```

Add `useRef` to the React import on line 1:

```js
import { createContext, useContext, useState, useCallback, useEffect, useRef } from 'react';
```

Replace the existing `refreshPermissions` callback (lines 50-56) with a throttled,
forceable version that caches results:

```js
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const refreshPermissions = useCallback((opts = {}) => {
    if (!user?.user_id) return;
    const now = Date.now();
    if (!opts.force && !shouldRefreshPermissions(lastPermFetchRef.current, now, PERMISSIONS_TTL_MS)) {
      return;
    }
    lastPermFetchRef.current = now;
    permissionsAPI.getMine()
      .then((res) => {
        const map = res?.data ?? {};
        setPermissions(map);
        writeCachedPermissions(map, now);
      })
      .catch(() => { /* keep cached permissions on transient failure */ });
  }, [user?.user_id]);
```

In `clearSession` (lines 27-30), also clear the cache:

```js
  const clearSession = useCallback(() => {
    updateUser(null);
    clearCachedPermissions();
    lastPermFetchRef.current = null;
    setPermissions(null);
    removeToken();
  }, [updateUser]);
```

Replace the mount effect (lines 58-64) so it forces a fresh fetch on login/user change
and adds a focus listener:

```js
  useEffect(() => {
    if (!user?.user_id) {
      clearCachedPermissions();
      lastPermFetchRef.current = null;
      setPermissions(null);
      return;
    }
    refreshPermissions({ force: true });
    const onFocus = () => refreshPermissions();
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [user?.user_id]); // eslint-disable-line react-hooks/exhaustive-deps
```

- [ ] **Step 6: Remove the per-navigation refetch in AdminLayout**

In `frontend/src/admin/components/AdminLayout.jsx`, DELETE this effect (lines 252-256):

```js
  // Re-sync permissions on navigation so mid-session grants/revokes apply
  useEffect(() => {
    if (!_user?.user_id) return;
    refreshPermissions();
  }, [location.pathname]); // eslint-disable-line react-hooks/exhaustive-deps
```

`refreshPermissions` is still destructured from `useAuth()` on line 15; remove it from
that destructure if it is now unused (lint will flag it):

```js
  const { user: _user, clearSession, permissions } = useAuth();
```

- [ ] **Step 7: Verify behavior in the browser**

Reload, open Network tab. Navigate between several pages: confirm `me/permissions` does
NOT fire on each plain navigation (only on login, on focus, and at most once per 60s).
Then, with a second admin session, revoke a permission for the test user and switch
focus back to the test user's tab — the now-forbidden page should redirect via
`RoleRoute` within the refresh window.

Expected: no per-navigation `me/permissions` call; revoke still enforced on focus.

- [ ] **Step 8: Run the frontend suite**

```bash
CI=true npm test
```

Expected: `permissionCache` tests pass; no new failures vs baseline.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/admin/utils/permissionCache.js frontend/src/admin/utils/permissionCache.test.js frontend/src/admin/context/AuthContext.jsx frontend/src/admin/components/AdminLayout.jsx
git commit -m "perf(frontend): cache permissions (SWR + 60s throttle); drop per-nav refetch"
```

---

## Task 8: Add indexes for the hot list queries

Additive, reversible indexes for the columns the slow list endpoints filter/join on.
Apply as a single migration (schema is a SQL dump; apply with `--path`, per project
convention).

**Files:**
- Create: `back-end/database/migrations/2026_06_23_000000_add_performance_indexes.php`

- [ ] **Step 1: EXPLAIN the hot queries first**

Confirm these columns are actually scanned (and not already indexed) before adding.
Run in the dev DB (`airkingV5`) via tinker or a SQL client, e.g.:

```sql
EXPLAIN SELECT * FROM inventory WHERE product_id = 1;
EXPLAIN SELECT * FROM inventory WHERE location_id = 1;
EXPLAIN SELECT * FROM sales_details WHERE sales_id = 1;
SHOW INDEX FROM inventory;
SHOW INDEX FROM sales_details;
SHOW INDEX FROM transfers;
```

Note which of the candidate columns below already have an index (skip those — do not
add duplicates).

- [ ] **Step 2: Write the migration**

Create `back-end/database/migrations/2026_06_23_000000_add_performance_indexes.php`.
Use `Schema::hasIndex` guards so re-runs and pre-existing indexes are safe. Only keep
the `add*` calls for columns Step 1 showed as missing-and-used:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $t) {
            if (!Schema::hasIndex('inventory', 'inventory_product_id_index')) {
                $t->index('product_id');
            }
            if (!Schema::hasIndex('inventory', 'inventory_location_id_index')) {
                $t->index('location_id');
            }
        });

        Schema::table('sales_details', function (Blueprint $t) {
            if (!Schema::hasIndex('sales_details', 'sales_details_sales_id_index')) {
                $t->index('sales_id');
            }
            if (!Schema::hasIndex('sales_details', 'sales_details_product_id_index')) {
                $t->index('product_id');
            }
        });

        Schema::table('transfers', function (Blueprint $t) {
            if (!Schema::hasIndex('transfers', 'transfers_from_location_id_index')) {
                $t->index('from_location_id');
            }
            if (!Schema::hasIndex('transfers', 'transfers_to_location_id_index')) {
                $t->index('to_location_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $t) {
            $t->dropIndex('inventory_product_id_index');
            $t->dropIndex('inventory_location_id_index');
        });
        Schema::table('sales_details', function (Blueprint $t) {
            $t->dropIndex('sales_details_sales_id_index');
            $t->dropIndex('sales_details_product_id_index');
        });
        Schema::table('transfers', function (Blueprint $t) {
            $t->dropIndex('transfers_from_location_id_index');
            $t->dropIndex('transfers_to_location_id_index');
        });
    }
};
```

Before saving: remove any `up()` index call whose column Step 1 showed already indexed,
and the matching `down()` drop, so the migration only touches genuinely new indexes.
Also confirm each table/column name exists (`SHOW COLUMNS FROM transfers;`) — adjust if
a column differs from the candidate name.

- [ ] **Step 3: Apply the migration to the dev DB**

```bash
cd "C:/XAMPP1/htdocs/Airking_Inventory/back-end"
php artisan migrate --path=database/migrations/2026_06_23_000000_add_performance_indexes.php
```

Expected: `DONE`.

- [ ] **Step 4: Verify the indexes exist and are used**

```sql
SHOW INDEX FROM inventory;
EXPLAIN SELECT * FROM inventory WHERE product_id = 1;
```

Expected: the new index names appear; the EXPLAIN `key` column now names an index
(not NULL / full scan).

- [ ] **Step 5: Confirm the backend suite still passes**

```bash
php artisan test
```

Expected: green baseline (indexes do not change behavior).

- [ ] **Step 6: Commit**

```bash
git add back-end/database/migrations/2026_06_23_000000_add_performance_indexes.php
git commit -m "perf(db): index hot FK/filter columns for inventory/sales/transfers lists"
```

---

## Final verification

- [ ] **A — concurrency:** With Apache running, load a data-heavy page and watch the
  Network tab — the per-page request burst runs in parallel (overlapping bars), not
  stacked sequentially.
- [ ] **C — fewer requests:** A plain navigation fires no `me/permissions` call (within
  60s) and one `layout-summary` call instead of three badge polls.
- [ ] **Timing:** Note time-to-data on 2-3 pages before/after; it should drop from
  several seconds to sub-second on a warm server.
- [ ] **Suites green:** `php artisan test` (backend) and `CI=true npm test` (frontend)
  show no new failures vs the documented baseline.
```
