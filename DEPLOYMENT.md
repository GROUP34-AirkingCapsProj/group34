# Deployment & Performance Checklist

This guide makes sure the app loads data in **1–2 seconds** on any device you install it on.

> **Why this matters:** the "slow loading" problem is caused by running the app in
> **development mode**. The fixes below are **per-device** — they live in files that
> do NOT travel with the project (`php.ini`, `.env`, the built frontend). A new
> machine starts fresh, so you must redo them once per device.

---

## The one golden rule

> On any machine the **client** uses:
> serve the **production build** and run the **backend on Apache**.
>
> `npm run dev`, `react-scripts start`, and `php artisan serve` are for **coding only** —
> never for client-facing use.

---

## New-device setup (do once per device)

### 1. Frontend — build the fast, minified version
```
cd frontend
npm install
npm run build
```
This creates `frontend/build/` (pre-compiled + minified). **Do not** run
`react-scripts start` on a client machine.

For a quick local check: `npm run demo` (builds + serves on http://localhost:5000).
For real deployment it is served by Apache on port 80 (see step 4).

### 2. Backend — enable OPcache in this device's php.ini
Edit `C:\XAMPP1\php\php.ini` (or wherever XAMPP is installed) and set:
```
zend_extension=opcache
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```
Then **restart Apache** from the XAMPP Control Panel.

> Without OPcache, PHP recompiles all its code on every request — the single biggest
> per-device speed loss.

### 3. Backend — configure `.env`
`.env` is not stored in git, so create/edit it on each device. Set:
```
APP_ENV=production
APP_DEBUG=false
SESSION_DRIVER=file
CACHE_STORE=file
```
Then run once (from `back-end/`):
```
composer install --optimize-autoloader --no-dev
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan migrate --force   # if using migrations
```

> Re-run `php artisan config:cache` any time you change `.env`.
> During development instead run `php artisan config:clear`.

### 4. Serve everything through Apache on port 80

The app is served as a **single origin** on port 80 (no CORS): Apache serves the
React build at `/` and reverse-proxies `/api` + `/storage` to Laravel, which runs
natively at the site root on an internal loopback port (`127.0.0.1:8081`).

> **Why the internal port?** Serving Laravel under `Alias /api` makes it strip the
> `/api` prefix, so its `api/*` routes stop matching (you get 404s). Running Laravel
> at root on `:8081` and proxying to it keeps the full path intact — and keeps
> mod_php's multi-request concurrency (unlike `php artisan serve`).

The full vhost lives in the repo at **`apache/airking.conf`**. To enable it:

1. Enable the proxy module in `C:\XAMPP1\apache\conf\httpd.conf` — uncomment:
   ```
   LoadModule proxy_http_module modules/mod_proxy_http.so
   ```
   (`mod_proxy`, `mod_rewrite`, `mod_alias` are already on in XAMPP.)
2. Include the repo vhost from `C:\XAMPP1\apache\conf\extra\httpd-vhosts.conf`:
   ```
   Include "C:/XAMPP1/htdocs/Airking_Inventory/apache/airking.conf"
   ```
3. Adjust the paths inside `apache/airking.conf` only if XAMPP or the project
   is not at `C:\XAMPP1\...`. Change the internal port (8081) if it is taken.
4. Test and restart:
   ```
   C:\XAMPP1\apache\bin\httpd.exe -t     # must say "Syntax OK"
   ```
   then restart Apache (XAMPP Control Panel → Apache → Stop, then Start).

Access after restart:
- This PC: **http://localhost/**
- Phone/tablet on same Wi-Fi: **http://<this-PC-IP>/**
- phpMyAdmin: **http://localhost/phpmyadmin** (preserved)
- Realtime (optional): `php artisan reverb:start --host=0.0.0.0 --port=8080`

> **If an Apache restart "doesn't take":** the XAMPP Control Panel sometimes fails to
> cycle the process. Verify with `netstat -ano | findstr :8081` — if 8081 is not
> listed, the new config did not load. Kill `httpd.exe` in Task Manager, then Start
> Apache again.

---

## Quick setup

Run **`setup-deploy.bat`** in the project root. It automates: composer install,
`storage:link`, config/route caching, the frontend build, enabling
`mod_proxy_http`, and including `apache/airking.conf` — then runs `httpd -t`.
It still cannot edit `php.ini` (OPcache) or create `.env` for you, and you must
restart Apache at the end; the script prints exactly what to do. Set the `XAMPP`
path at the top of the script if XAMPP is not at `C:\XAMPP1`.

---

## Scheduled tasks (optional, but worth setting up)

Nothing breaks without these — they are tidy-up jobs, and every screen is
correct whether or not they have ever run. Warranty expiry in particular is
worked out from the end date each time it is displayed, so a machine with no
scheduler still shows Active and Expired correctly.

What the scheduler buys you is a `warranty_records.status_id` that reports and
exports can trust without re-deriving, plus auto-archiving.

| Command | When | What it does |
|---|---|---|
| `warranties:expire` | daily 01:00 | Marks active warranties past their end date as expired |
| `archive:auto` | daily 02:00 | Auto-archives per Archive Settings |
| `archive:cleanup` | weekly Sun 03:00 | Retention cleanup |

To run them, one Windows Task Scheduler entry every minute is enough — Laravel
decides what is actually due:

```
C:\XAMPP1\php\php.exe C:\xampp\htdocs\Airking_Inventory\back-end\artisan schedule:run
```

Any single command can also be run by hand at any time, e.g.
`php artisan warranties:expire`.

---

## When you're back to coding

Development mode is fine while writing code:
```
cd frontend
npm run dev        # dev server + php artisan serve + reverb
```
Just remember to switch back to the production build before showing the client.

---

## If loading is slow again — 30-second diagnosis
1. **Is the frontend the dev server?** If the URL is `:3000` / you ran
   `react-scripts start`, that's the cause. Use the production build.
2. **Is OPcache on?** Run `php -r "var_dump(opcache_get_status()!==false);"` —
   should print `bool(true)`.
3. **Is the API actually slow?** Time it:
   `curl -o NUL -w "%{time_total}s\n" http://<host>/api/products`
   Under ~0.5s = backend is fine, the problem is the frontend build.
4. **Big database + slow lists?** Add indexes on the columns used by product/
   inventory search + joins.
