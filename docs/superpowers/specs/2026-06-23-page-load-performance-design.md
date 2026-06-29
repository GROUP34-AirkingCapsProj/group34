# Page-Load Performance — Design Spec

**Date:** 2026-06-23
**Branch:** backupV3
**Status:** Approved approach (A + C), pending spec review

## 1. Problem

Navigating between admin pages takes several seconds before data appears. The lag
is felt on every page change, not just one slow screen.

### Root cause

Two compounding issues:

1. **The backend cannot serve requests concurrently.** The app runs on
   `php artisan serve` (confirmed; `back-end/composer.json` scripts `dev` and
   `serve-lan`, port 8000). That dev server is **single-threaded** — it processes
   one HTTP request at a time. On Windows each request also re-bootstraps the full
   Laravel framework (~hundreds of ms).

2. **Each page navigation fires several requests at once**, which then queue behind
   each other on that single thread:
   - `GET /api/me/permissions` — fired on **every** `location.pathname` change
     (`frontend/src/admin/components/AdminLayout.jsx:253-256`).
   - `GET /api/alerts/settings`, `GET /api/dashboard/pending-approvals`,
     `GET /api/dashboard/stats` — fired on AdminLayout mount (3 calls).
   - The page component's own data call(s).

   That is ~4–5 concurrent requests per visit. On a single-threaded server they run
   **serially**, each paying the Laravel boot cost → multi-second waits.

The browser Network tab would show requests stalled/queued waiting their turn; we are
treating the server-concurrency limit as the confirmed primary cause and the
request-count-per-page as the confirmed secondary cause.

## 2. Goals / Non-Goals

**Goals**
- Page navigations feel near-instant on the LAN/dev setup.
- Backend can process the per-page burst of requests in parallel.
- Reduce redundant requests so we are not paying for work we do not need.
- Keep the existing mid-session permission revoke/grant behavior (revoked pages still
  auto-redirect) — just stop blocking every navigation on it.

**Non-Goals**
- Laravel Octane / FrankenPHP (Approach B) — deferred; not needed for a dev/LAN app.
- Rewriting controllers or the RBAC model.
- Production hardening beyond what makes dev/LAN fast.

## 3. Approach A — Concurrent backend + caching

Run the API through **XAMPP Apache + mod_php** instead of `php artisan serve`, and
cut per-request boot cost with OPcache and Laravel's config/route caches.

### A1. Apache virtual host

XAMPP is installed at `C:\XAMPP1`; `httpd-vhosts.conf` and `back-end/public` both
exist. Add a vhost pointing at the Laravel public dir:

- File: `C:\XAMPP1\apache\conf\extra\httpd-vhosts.conf`
- Add a `<VirtualHost *:80>` (or a dedicated port, e.g. `:8080`, to avoid clashing
  with other XAMPP sites) with:
  - `DocumentRoot "C:/XAMPP1/htdocs/Airking_Inventory/back-end/public"`
  - `ServerName airking.local`
  - a `<Directory>` block granting `AllowOverride All` + `Require all granted` so
    Laravel's `public/.htaccess` front-controller rewrite works.
- Map the hostname in `C:\Windows\System32\drivers\etc\hosts`:
  `127.0.0.1  airking.local`
- For LAN/phone access keep using the machine's LAN IP — Apache binds all interfaces
  by default, so phones hit `http://<LAN-IP>/api` with no extra worker needed (this
  replaces the `serve-lan` workaround).

Decision to confirm during implementation: **port 80 vs a dedicated port.** Port 80 is
simplest (`http://airking.local/api`); a dedicated port avoids interfering with any
other Apache vhost already in use. Default to port 80 unless a conflict is found.

### A2. Frontend API base URL

`frontend/src/admin/services/api.js` `getApiBaseUrl()` currently hard-codes port 8000
(`apiPort` default `'8000'`). Update the resolution so it targets the Apache host/port:
- Loopback → `http://airking.local/api` (or `http://127.0.0.1/api`).
- Private LAN host → `http://<hostname>/api` (drop the `:8000` when Apache is on 80).
- Keep `REACT_APP_API_URL` / `REACT_APP_API_PORT` overrides working so the change is
  configurable, not hard-coded. Set the new default in `frontend/.env`.

### A3. OPcache

Enable in `C:\XAMPP1\php\php.ini`:
- `opcache.enable=1`, `opcache.enable_cli=0`
- `opcache.memory_consumption=128`, `opcache.max_accelerated_files=20000`
- `opcache.validate_timestamps=1` for dev (so edited PHP is picked up without a
  restart). Document that production would use `validate_timestamps=0`.

### A4. Laravel caches

Run once after config is stable (and re-run after `.env`/route changes):
- `php artisan config:cache`
- `php artisan route:cache`

Document the caveat: while iterating on `.env` or routes, run `php artisan
config:clear` / `route:clear` first. (The test script already clears config.)

### A5. Verification for A

- `Get-Date` before/after `curl http://airking.local/api/me/permissions` (warm) → tens
  of ms, not hundreds.
- Browser Network tab on a page load: the per-page burst runs in **parallel** (bars
  overlap) instead of stacking sequentially.

## 4. Approach C — Fewer / cheaper requests per page

### C1. Stop refetching permissions on every navigation

Today `AdminLayout.jsx:253-256` calls `refreshPermissions()` on every
`location.pathname` change. Permissions live in `AuthContext` (`permissions` state) and
rarely change within a session. Replace per-navigation refetch with a
**stale-while-revalidate + throttle** strategy in `AuthContext.jsx`:

- Cache the resolved permission map in `sessionStorage` and hydrate `permissions` from
  it on load so the sidebar/`can()` are populated immediately (no blocking wait).
- Refresh in the background only when **stale**: track `lastFetchedAt`; refetch if
  older than a threshold (e.g. 60s), on `window` focus, and once on mount. Remove the
  unconditional `location.pathname` effect from `AdminLayout`.
- Preserve the mid-session revoke behavior: because permissions still refresh on focus
  and on the interval, `RoleRoute` still redirects off a newly-revoked page within the
  refresh window (documented as "up to ~60s" instead of "instant"). This is an
  intentional, acceptable trade for removing a blocking call from every navigation.

### C2. Collapse the three layout polls into one request

AdminLayout fires 3 separate calls on mount for badge counts:
`/alerts/settings` (unread), `/dashboard/pending-approvals`, `/dashboard/stats`
(awaiting IST). Add a single lightweight endpoint that returns just these counts:

- Backend: `GET /api/layout-summary` (new method on `DashboardController` or a small
  new controller), returning
  `{ unread_count, pending_approvals, awaiting_fulfillment, awaiting_receipt }`.
  It must run only the count queries needed — not the full dashboard payload (the
  current code reaches into `dashboardAPI.getStats()` which computes far more than two
  numbers).
- Route: add under the existing `auth:sanctum` group in `back-end/routes/api.php`.
- Frontend: add `layoutAPI.getSummary()` to `services/api.js`; in `AdminLayout` replace
  the three `useEffect` fetches with one call (keep the 60s `setInterval` refresh and
  the `approval-notifications-updated` / `storage` event listeners).

This turns 3 serialized requests per page into 1.

### C3. Add missing DB indexes for hot list queries

The list endpoints filter/join/aggregate on FK columns. Add indexes via a **single new
migration** (schema is otherwise a SQL dump — see project conventions; apply with
`php artisan migrate --path=...`). Audit `EXPLAIN` first, then add indexes for the
columns the hot endpoints actually use. Candidate columns (confirm each is missing and
actually used before adding):
- `inventory(product_id)`, `inventory(location_id)` — inventory list + `withSum`
  stock aggregation (`Product::withSum('inventories ...')`).
- `sales_details(sales_id)`, `sales_details(product_id)` — batch `transactions` join.
- `item_serial(product_id)`, `item_serial(serial_id)` already PK.
- Any `*_location_id` columns used by the `ScopesToUserLocation` `forUser` scope
  (e.g. transfers `from_location_id` / `to_location_id`).

Indexes are additive and reversible (`down()` drops them); no data change.

### C4. Verification for C

- Network tab on a navigation: **no** `/me/permissions` call on a plain page change
  within the throttle window; exactly **one** layout-summary call replacing the prior
  three.
- Revoke a permission for a test user mid-session; confirm the page redirects within
  the refresh window (focus the tab to force it).
- `EXPLAIN` on the inventory/list queries shows index usage (not full table scans);
  endpoint latency drops on a warm server.

## 5. Order of work

1. **A first** (biggest win, lowest code risk): vhost → OPcache → frontend base URL →
   config/route cache → verify parallelism.
2. **C2** (layout-summary endpoint) — removes 2 requests/page.
3. **C1** (permission caching) — removes 1 request/page, keep revoke behavior.
4. **C3** (indexes) — after measuring which endpoints are still slow on the warm
   server.

Each step is independently verifiable, so we can measure the gain after each.

## 6. Risks & mitigations

- **Apache `.htaccess` rewrite not active** → 404s on API routes. Mitigate: ensure
  `AllowOverride All` and that `mod_rewrite` is enabled in `httpd.conf`.
- **CORS / base-URL mismatch** after the port change → "Cannot reach the server".
  Mitigate: update `getApiBaseUrl()` + `frontend/.env`, verify Sanctum/CORS config
  allows the new origin, test loopback and LAN.
- **Config cache hides `.env` edits** during further dev. Mitigate: document
  `config:clear`; the `test` script already clears config.
- **Permission revoke latency** grows from "instant" to "within refresh window."
  Accepted and documented.
- **Wrong/over-broad indexes** add write cost. Mitigate: `EXPLAIN`-driven, only add
  indexes proven missing and used; all reversible.

## 7. Out of scope / follow-ups

- Laravel Octane (Approach B) for production-grade latency.
- Building a production React build pipeline served by Apache (dev server is fine for
  now).
- Broader query/N+1 refactors beyond indexing the confirmed hot paths.
