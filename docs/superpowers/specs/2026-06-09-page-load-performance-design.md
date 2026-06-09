# Page-Load Performance — Design Spec

**Date:** 2026-06-09
**Branch context:** Airking Inventory (Laravel 11 API + React 18 SPA, MariaDB on XAMPP)
**Goal:** Page navigation in the LAN demo environment must complete in **1–2 seconds**, a stated project-scope requirement. Today it takes several seconds on every navigation.

## Problem & Diagnosis

Slowness occurs on **every page navigation** (not just first load), which rules out JS-bundle size and points at the API/data layer. Investigation found the controllers themselves are well written — `InventoryController::index` and `ProductController::index` use proper eager loading and pagination, so SQL is not the bottleneck. The cause is **architectural and environmental**, with four compounding factors:

1. **Single-threaded server.** The backend runs via `php artisan serve` (`composer run dev` / `serve-lan`, `back-end/composer.json:45-56`), PHP's built-in dev server, which processes **one request at a time**.
2. **Request bursts per page.** Each page fires ~5 separate API calls at mount (e.g. `InventoryManagement.jsx:126-218`: batch locations+categories, transaction-types, stock-movement-trends, inventory list, scan-events). Even `Promise.all`-parallel calls queue single-file at the dev server.
3. **Full boot cost per request, unoptimized.** Confirmed: **opcache is OFF** (`opcache.enable` empty), and **config/routes are NOT cached** (`back-end/bootstrap/cache/` contains only `packages.php`/`services.php`). So every request recompiles PHP source, re-reads all config, and rebuilds the route table.
4. **No client-side caching of static lookups.** Categories, locations, statuses, units, transaction-types are re-fetched on every page (`api.js:127`, no cache layer).

Net effect: a page's ~5 requests run in series, each paying full recompile + config + route-build + framework boot. Five serialized requests × ~300ms overhead ≈ ~1.5s before any data is read.

Key insight: **XAMPP already includes Apache** (a multi-process server) and it runs for MariaDB, but PHP is served through the single-threaded `artisan serve` instead. The biggest fix uses a tool already installed.

## Environment / Constraint

Target environment is **Local + LAN demo via XAMPP**, accessed from the host PC and phones/PCs on the same Wi-Fi. The 1–2s target must be met there. Frontend stays on its current dev server to preserve hot-reload; only the API moves to Apache. (Rejected alternative: build the React app and serve everything same-origin from Apache — faster first-load but adds a rebuild step to the dev loop and does not address the navigation problem, which is API-bound.)

## Approach

Chosen: **B (core) then C (fast follow).** B alone is expected to clear the 1–2s bar; C adds headroom for weak-LAN/phone cases.

### Part 0 — Measurement baseline (before any change)
Capture "before" numbers to prove the target is met:
- Chrome DevTools → Network; hard-reload **Inventory** and **Dashboard**.
- Record: number of requests at mount, each request's TTFB ("Waiting"), and total time-to-interactive.
- Expect to observe requests stacking single-file (confirms serialization).
- Re-run the identical measurement after each part to document improvement.

### Part B — Core fix

**B1. Serve the API through XAMPP Apache (eliminates serialization).**
- Add an Apache vhost with `DocumentRoot = C:\XAMPP1\htdocs\Airking_Inventory\back-end\public`, listening on **port 8000** (matches `REACT_APP_API_PORT` default in `api.js:6`, so no frontend change needed).
- Ensure `mod_rewrite` is enabled (Laravel `public/.htaccess` requires it).
- Frontend remains on its current dev server. Two origins → verify `config/cors.php` allows the LAN origins (CORS already works today under `artisan serve`, so config should already permit it; confirm).
- Stop using `artisan serve` for the API; document the new start procedure (start Apache via XAMPP control panel).

**B2. Cut per-request boot cost (benefits every request).**
- Enable **opcache** in the `php.ini` that **Apache** loads (`opcache.enable=1`; verify via `phpinfo()`, not just CLI).
- Run `php artisan optimize` (config + route + event cache).
- Remove the per-request `console.log` in `apiRequest` (`api.js:143` and `:176`).

**Expected result after B:** a page's ~5 requests run in parallel, each cheap → comfortably under 2s.

### Part C — Fast follow: client-side lookup cache
- Add a small in-memory + `localStorage` cache inside `api.js` (a thin `cachedRequest` wrapper or opt-in flag on `apiRequest`) for lookups that do not change mid-session: **categories, locations, statuses, units, transaction-types, brands**.
- Fetch once per session; later pages read instantly from cache.
- Invalidate the cache on logout (clear alongside token in `removeToken`).

## Acceptance Criteria
- Inventory and Dashboard navigation each load in **≤2s** on the demo PC (and ideally on a LAN phone).
- Before/after Network-tab numbers are documented for the project scope.
- API continues to function identically (same responses, auth, CORS) after moving to Apache.

## Risks & Mitigations
- **`route:cache` fails on closure routes.** The `/` route (`routes/web.php:5`) appears to be a closure. Convert it to an invokable/controller action or otherwise make all routes cacheable. (Low effort.)
- **`config:cache` freezes `.env`.** Acceptable for a demo; document that editing `.env` requires re-running `php artisan config:cache`.
- **Apache vs CLI `php.ini` mismatch.** Enable opcache in the ini Apache actually loads; confirm with `phpinfo()`.
- **CORS / base-URL regressions after the move.** Verify LAN access from a phone still resolves the API (`getApiBaseUrl` logic in `api.js`) and that preflight passes.

## Out of Scope
- Rewriting controllers/SQL (already efficient).
- JS bundle-size / first-load optimization (problem is navigation, not first load).
- Production/cloud deployment (target is the LAN demo).
