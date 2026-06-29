# Page-Load Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every page navigation in the LAN/XAMPP demo load in ≤2s by serving the Laravel API through XAMPP Apache (multi-process) instead of single-threaded `artisan serve`, cutting per-request boot cost, and caching static lookups client-side.

**Architecture:** The slowness is environmental, not SQL. `php artisan serve` processes one request at a time, so each page's ~5-request burst queues single-file, and every request recompiles PHP (opcache off) + re-reads config + rebuilds routes (not cached). Fix: (B) host the API on Apache + enable opcache + `artisan optimize`; (C) cache session-stable lookups in the frontend so pages stop re-fetching them.

**Tech Stack:** Laravel 11/PHP 8.2, XAMPP Apache + MariaDB, React 18 (CRA dev server), fetch-based `api.js`.

> **Note on "tests":** This is infrastructure/config work — there are no unit tests to write. Verification steps (curl, phpinfo, DevTools Network tab) are the equivalent of tests here. Treat an "Expected" line that fails as a failing test: stop and fix before moving on.

> **Note on Apache restarts:** After editing `php.ini` or any Apache `.conf`, validate then restart Apache:
> `C:\XAMPP1\apache\bin\httpd.exe -t` (expect `Syntax OK`) then restart Apache from the XAMPP Control Panel (Stop → Start). Keep MySQL/MariaDB running throughout.

---

## File Structure

**Backend (config / infra):**
- Modify: `back-end/routes/web.php` — replace closure routes so `route:cache` works.
- Modify: `C:\XAMPP1\php\php.ini` — enable opcache.
- Modify: `C:\XAMPP1\apache\conf\httpd.conf` — `Listen 8000`, ensure vhosts include + mod_rewrite.
- Modify: `C:\XAMPP1\apache\conf\extra\httpd-vhosts.conf` — API vhost on :8000.
- Generated (not edited by hand): `back-end/bootstrap/cache/config.php`, `routes-*.php` via `php artisan optimize`.

**Frontend:**
- Modify: `frontend/src/admin/services/api.js` — remove per-request `console.log`; add lookup cache + invalidation.

**Docs:**
- Create: `docs/superpowers/measurements-page-load.md` — before/after numbers for the project scope.

---

## Task 0: Capture the baseline measurement

**Files:**
- Create: `docs/superpowers/measurements-page-load.md`

- [ ] **Step 1: Start the system the current way**

Start the backend the way you do today (e.g. `cd back-end; php artisan serve` or `composer run dev`) and the frontend dev server. Log in.

- [ ] **Step 2: Measure Inventory and Dashboard**

Open Chrome DevTools → **Network** tab → check "Disable cache" OFF (we want realistic). Hard-reload, then navigate to **Inventory**; then to **Dashboard**. For each page record:
- Number of requests fired at mount
- Each request's TTFB ("Waiting for server response")
- Total time until the page is interactive (last request finishes)

You should observe requests stacking single-file (each starts when the previous ends) — that is the serialization we are fixing.

- [ ] **Step 3: Write the baseline into a doc**

Create `docs/superpowers/measurements-page-load.md`:

```markdown
# Page-Load Measurements

## Baseline (artisan serve, opcache off, not optimized) — 2026-06-09

### Inventory
- Requests at mount: <N>
- TTFB per request: <list ms>
- Time to interactive: <X> s

### Dashboard
- Requests at mount: <N>
- TTFB per request: <list ms>
- Time to interactive: <X> s

## After Part B (Apache + opcache + optimize)
_(fill in Task 6)_

## After Part C (lookup cache)
_(fill in Task 8)_
```

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/measurements-page-load.md
git commit -m "docs: page-load baseline measurements"
```

---

## Task 1: Make routes cacheable (remove closure routes)

`route:cache` aborts with "Your application routes cannot be cached because they use closures." `web.php` has two closures. `Route::view` is not a closure and caches fine; the `/test-mail` route is a dev artifact and is removed.

**Files:**
- Modify: `back-end/routes/web.php`

- [ ] **Step 1: Replace the closures**

Replace the entire contents of `back-end/routes/web.php` with:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
```

(The `/test-mail` route and its `SendOTPMail`/`Mail` imports are removed — it was a manual mail-testing artifact, not used by the app. If you still need to test mail, use `php artisan tinker`.)

- [ ] **Step 2: Verify routes now cache (this is the test)**

Run: `cd back-end; php artisan route:cache`
Expected: `Routes cached successfully.` (NOT an error about closures.)

- [ ] **Step 3: Clear the cache again so dev stays flexible until Task 8**

Run: `php artisan route:clear`
Expected: `Route cache cleared.`

- [ ] **Step 4: Commit**

```bash
git add back-end/routes/web.php
git commit -m "refactor: make web routes cacheable (drop closure routes for route:cache)"
```

---

## Task 2: Enable opcache in php.ini

**Files:**
- Modify: `C:\XAMPP1\php\php.ini`

- [ ] **Step 1: Set opcache options**

Open `C:\XAMPP1\php\php.ini`. Find the `[opcache]` section. Ensure the opcache extension is loaded and enabled — set (uncommenting / editing existing lines, or adding them if absent) exactly these values:

```ini
zend_extension=php_opcache.dll
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```

`validate_timestamps=1` + `revalidate_freq=0` means edited PHP files are still picked up immediately — safe for active development.

- [ ] **Step 2: Validate Apache config and restart Apache**

Run: `C:\XAMPP1\apache\bin\httpd.exe -t`
Expected: `Syntax OK`
Then restart Apache from the XAMPP Control Panel (Stop → Start).

- [ ] **Step 3: Verify opcache is active (the test)** — deferred to Task 4

opcache can only be confirmed once Apache is serving the app (Task 3). The verification curl is in Task 4, Step 3. For now, just confirm Apache restarted without errors.

- [ ] **Step 4: No commit**

`php.ini` lives outside the repo (XAMPP install). Note the change in the measurements doc later; nothing to commit here.

---

## Task 3: Serve the API through Apache on port 8000

**Files:**
- Modify: `C:\XAMPP1\apache\conf\httpd.conf`
- Modify: `C:\XAMPP1\apache\conf\extra\httpd-vhosts.conf`

- [ ] **Step 1: Ensure prerequisites in httpd.conf**

In `C:\XAMPP1\apache\conf\httpd.conf`, confirm/enable these (uncomment if commented):

- `LoadModule rewrite_module modules/mod_rewrite.so` — required by Laravel's `public/.htaccess`.
- `Include conf/extra/httpd-vhosts.conf` — so the vhost file below is read.

Then, right below the existing `Listen 80` line, add:

```apache
Listen 8000
```

- [ ] **Step 2: Add the API vhost**

Append to `C:\XAMPP1\apache\conf\extra\httpd-vhosts.conf`:

```apache
# --- Airking Inventory API (Laravel) on port 8000 ---
<VirtualHost *:8000>
    DocumentRoot "C:/XAMPP1/htdocs/Airking_Inventory/back-end/public"
    <Directory "C:/XAMPP1/htdocs/Airking_Inventory/back-end/public">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog "logs/airking-api-error.log"
    CustomLog "logs/airking-api-access.log" common
</VirtualHost>
```

- [ ] **Step 3: Validate and restart**

Run: `C:\XAMPP1\apache\bin\httpd.exe -t`
Expected: `Syntax OK`
Restart Apache from the XAMPP Control Panel. **Stop `php artisan serve`** — Apache now serves the API.

- [ ] **Step 4: Verify the API responds over Apache (the test)**

Run: `curl http://localhost:8000/api`
Expected: a JSON body (e.g. `{"message":...}` or `{"version":...}`) and HTTP 200 — same as the old `artisan serve` health response. If you get 404/500 or an Apache default page, the `DocumentRoot`/`mod_rewrite`/`Include` is wrong — fix before continuing.

- [ ] **Step 5: No commit** (Apache config is outside the repo.)

---

## Task 4: Apply Laravel boot optimizations & confirm opcache

**Files:**
- Generated: `back-end/bootstrap/cache/config.php`, `back-end/bootstrap/cache/routes-*.php`

- [ ] **Step 1: Run optimize**

Run: `cd back-end; php artisan optimize`
Expected output includes `config ....... DONE`, `routes ....... DONE`, `events ....... DONE`.

- [ ] **Step 2: Verify the caches exist (test)**

Run: `dir bootstrap\cache`
Expected: `config.php` AND a `routes-*.php` now exist (alongside `packages.php`/`services.php`).

- [ ] **Step 3: Confirm opcache is active under Apache (test for Task 2)**

Create a temporary probe `back-end/public/_opcache_check.php`:

```php
<?php
header('Content-Type: application/json');
echo json_encode([
    'opcache_loaded' => extension_loaded('Zend OPcache'),
    'opcache_enabled' => function_exists('opcache_get_status') ? (opcache_get_status(false)['opcache_enabled'] ?? false) : false,
]);
```

Run: `curl http://localhost:8000/_opcache_check.php`
Expected: `{"opcache_loaded":true,"opcache_enabled":true}`
If `false`, revisit Task 2 (extension line / php.ini Apache actually loads) and restart Apache.

- [ ] **Step 4: Delete the probe**

Run: `del back-end\public\_opcache_check.php`
Confirm: `curl http://localhost:8000/_opcache_check.php` now returns 404.

- [ ] **Step 5: Record the re-cache reminder**

Append to `docs/superpowers/measurements-page-load.md` under a new `## Operational notes` section:

```markdown
## Operational notes
- Config is cached. After editing back-end/.env, re-run: `php artisan config:cache` (or `php artisan optimize`).
- API is served by XAMPP Apache on :8000 (vhost). `php artisan serve` is no longer used.
```

- [ ] **Step 6: Commit (doc only)**

```bash
git add docs/superpowers/measurements-page-load.md
git commit -m "docs: record optimize/opcache operational notes"
```

---

## Task 5: Remove per-request console.log in api.js

**Files:**
- Modify: `frontend/src/admin/services/api.js` (the two `console.log` calls in `apiRequest`)

- [ ] **Step 1: Delete the request log line**

In `apiRequest`, remove this line (currently ~line 143):

```js
  console.log(`[API] ${method} ${url}`, options.body ? { body: options.body } : '');
```

- [ ] **Step 2: Delete the response log line**

Remove this line (currently ~line 176):

```js
  console.log(`[API] ${method} ${url} → ${response.status}`, response.ok ? 'OK' : { message: data.message, errors: data.errors });
```

- [ ] **Step 3: Verify the app still builds/runs (test)**

With the frontend dev server running, reload a page. Expected: pages load normally and the console no longer prints `[API] ...` lines for every request.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "perf: drop per-request console.log from apiRequest"
```

---

## Task 6: Re-measure after Part B

**Files:**
- Modify: `docs/superpowers/measurements-page-load.md`

- [ ] **Step 1: Measure again**

Repeat Task 0 Step 2 exactly (Inventory + Dashboard) now that the API runs on Apache with opcache + optimize. In the Network tab the page's requests should now run **in parallel** (overlapping bars), not stacked.

- [ ] **Step 2: Fill in the "After Part B" section**

Replace the `_(fill in Task 6)_` placeholder in `docs/superpowers/measurements-page-load.md` with the new request counts, TTFBs, and time-to-interactive for both pages.

- [ ] **Step 3: Check against the target**

If both pages are ≤2s, Part B met the goal. Proceed to Part C for extra headroom (phones / weak LAN). If still >2s, note which requests dominate before continuing — the lookup cache in Part C directly removes redundant lookup requests.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/measurements-page-load.md
git commit -m "docs: page-load measurements after Apache + boot optimization"
```

---

## Task 7: Add a client-side lookup cache to api.js

Cache session-stable lookups so pages stop re-fetching them. Keyed by full endpoint (including query string) so different params cache separately. Cleared on logout.

**Files:**
- Modify: `frontend/src/admin/services/api.js`

- [ ] **Step 1: Add the cache + helpers near the token helpers**

In `api.js`, immediately AFTER the `const TOKEN_KEY = 'token';` line (~line 60), add:

```js
/* In-memory cache for static lookups that don't change mid-session
   (categories, locations, statuses, units, transaction-types, brands).
   Keyed by full endpoint incl. query string; cleared on logout/mutation. */
const _lookupCache = new Map();

export const clearLookupCache = () => {
  _lookupCache.clear();
};
```

- [ ] **Step 2: Clear the cache inside removeToken**

In `removeToken` (~line 119), add the cache clear so a new user never sees the previous session's lookups:

```js
export const removeToken = () => {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(SESSION_TIMEOUT_MINUTES_KEY);
  _lookupCache.clear();
};
```

- [ ] **Step 3: Add the cachedRequest wrapper after apiRequest**

Immediately AFTER the `apiRequest` function definition (after its closing `};`, ~line 196), add:

```js
/** GET wrapper that memoizes the response per-endpoint for static lookups.
    Only use for data that is stable within a session. */
const cachedRequest = async (endpoint, options = {}) => {
  if (_lookupCache.has(endpoint)) {
    return _lookupCache.get(endpoint);
  }
  const data = await apiRequest(endpoint, options);
  _lookupCache.set(endpoint, data);
  return data;
};
```

- [ ] **Step 4: Point the lookup APIs at cachedRequest**

Change ONLY the read (`getAll`) calls below from `apiRequest(...)` to `cachedRequest(...)`. Leave every create/update/delete on `apiRequest`.

In `categoriesAPI.getAll` (~line 842):
```js
  getAll: async () => cachedRequest('/categories'),
```

In `brandsAPI.getAll` (~line 879):
```js
  getAll: async () => cachedRequest('/brands'),
```

In `transactionTypesAPI.getAll` (~line 909):
```js
  getAll: async () => cachedRequest('/transaction-type-lookup'),
```

In `statusAPI.getAll` (~line 893):
```js
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return cachedRequest(query ? `/status-lookup?${query}` : '/status-lookup');
  },
```

In `unitsAPI.getAll` (~line 857):
```js
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return cachedRequest(query ? `/units?${query}` : '/units');
  },
```

In `locationsAPI.getAll` (~line 411):
```js
  getAll: async (params = {}) => {
    const query = new URLSearchParams(params).toString();
    return cachedRequest(query ? `/locations?${query}` : '/locations');
  },
```

- [ ] **Step 5: Invalidate the cache when a lookup is mutated**

So newly created/edited lookups appear without a reload, clear the cache inside the mutating methods of these same APIs. Add `clearLookupCache();` as the first line of the `async` body of every `create`, `update`, `delete`, and `restore` method on: `categoriesAPI`, `brandsAPI`, `unitsAPI`, `locationsAPI`, and `statusAPI`.

Example — `categoriesAPI.create` becomes:

```js
  create: async (data) => {
    clearLookupCache();
    return apiRequest('/categories', { method: 'POST', body: JSON.stringify(data) });
  },
```

Apply the same one-line `clearLookupCache();` prepend to: `categoriesAPI.{create,update,delete,restore}`, `brandsAPI.{create,update,delete,restore}`, `unitsAPI.{create,update,delete,restore}`, `locationsAPI.{create,update,delete,restore}`, `statusAPI.{create,update,delete}`.

- [ ] **Step 6: Verify caching works (the test)**

With the frontend running and DevTools → Network open:
1. Navigate to Inventory, then Dashboard, then back to Inventory.
2. Expected: `/categories`, `/locations`, etc. are requested on the FIRST visit but NOT re-requested on subsequent navigations (no new Network row for them).
3. Create a new category in Category/Brand management, then reopen a page that lists categories. Expected: the new category appears (cache was invalidated by the `create` call).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/admin/services/api.js
git commit -m "perf: cache static lookups client-side, invalidate on logout/mutation"
```

---

## Task 8: Final measurement & acceptance

**Files:**
- Modify: `docs/superpowers/measurements-page-load.md`

- [ ] **Step 1: Re-cache backend (in case .env/config changed during work)**

Run: `cd back-end; php artisan optimize`
Expected: config/routes/events DONE.

- [ ] **Step 2: Measure Inventory + Dashboard one final time**

Repeat the Task 0 Step 2 measurement. Also, if possible, measure once from a **phone on the same Wi-Fi** (open the frontend's LAN URL, navigate the same two pages).

- [ ] **Step 3: Fill in the "After Part C" section and state pass/fail vs ≤2s**

Replace `_(fill in Task 8)_` in `docs/superpowers/measurements-page-load.md` with the final numbers and an explicit line: `Target ≤2s: PASS/FAIL (Inventory <X>s, Dashboard <Y>s)`.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/measurements-page-load.md
git commit -m "docs: final page-load measurements (acceptance vs 2s target)"
```

---

## Acceptance Criteria (from spec)
- Inventory and Dashboard navigation each load in ≤2s on the demo PC (ideally also on a LAN phone) — proven by the before/after numbers in `measurements-page-load.md`.
- API behaves identically after the Apache move: same JSON, auth (Sanctum bearer), and CORS still pass.
- Lookups fetch once per session and refresh after a relevant create/update/delete.

## Rollback notes
- To revert to `artisan serve`: stop Apache's :8000 vhost (or just run `php artisan serve` on a different port), and `php artisan optimize:clear` to drop cached config/routes.
- opcache and the Apache vhost are local-machine config, not in the repo; the only committed changes are `web.php`, `api.js`, and the docs.
