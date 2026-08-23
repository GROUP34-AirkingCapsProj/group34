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
Edit `C:\xampp\php\php.ini` (or wherever XAMPP is installed) and set:
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

`.env` is not stored in git, so create/edit it on each device.

> **Order matters.** `config:cache` freezes whatever `.env` holds at the moment
> you run it. Finish every value in step 3a **before** running step 3b, or you
> will ship a cached config that still points at localhost with debug logging on.
> If you change `.env` afterwards, re-run `php artisan config:cache`.

#### 3a. Set these values first

| Key | Value | Why it matters |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | A stack trace on a 500 leaks file paths, SQL, and env values |
| `APP_URL` | the real scheme + host, e.g. `https://airking.local` | Drives `URL::forceScheme()`, mail links, and signed download URLs. Leaving it `http://localhost` silently disables HTTPS URL generation |
| `LOG_LEVEL` | `warning` | `debug` writes request payloads to `storage/logs` |
| `SESSION_DRIVER` | `file` | |
| `CACHE_STORE` | `file` | |
| `SESSION_SECURE_COOKIE` | `true` *(only once HTTPS works)* | Stops the session cookie going out over plain HTTP |
| `DB_USERNAME` / `DB_PASSWORD` | a non-root user with a real password | `root` with an empty password + phpMyAdmin on the LAN is full database access for anyone on the Wi-Fi |
| `MAIL_MAILER` + SMTP creds | real SMTP | **Not optional.** Both forgot-password and account creation depend on it. With `MAIL_MAILER=log`, reset OTPs and new-account temporary passwords are written in plaintext to `storage/logs/laravel.log` and never delivered |
| `REVERB_APP_KEY` / `REVERB_APP_SECRET` | freshly generated | The defaults are published in `.env.example`; anyone can use them to publish to your channels |
| `REVERB_SCHEME` | `https` *(when the site is HTTPS)* | A `ws://` socket opened from an `https://` page is blocked as mixed content — the notification bell fails silently |

Also set `CORS_ALLOWED_ORIGINS` if the frontend is served from a different
origin than the API. Wildcard patterns for shared hosts (`*.vercel.app`,
`*.netlify.app`, `*.trycloudflare.com`) were deliberately removed from
`config/cors.php` — with `supports_credentials` on, they let anyone who deploys
to those domains make authenticated calls to your API. List exact origins here.

### Email delivery (Brevo SMTP)

Password reset codes are delivered over Brevo's SMTP relay. In the production
`.env` set `MAIL_USERNAME` to the Brevo SMTP login and `MAIL_PASSWORD` to the
Brevo SMTP key (SMTP & API > SMTP keys) — the SMTP key is not the Brevo account
password.

`MAIL_FROM_ADDRESS` must be an address verified under Brevo > Senders, Domains &
Dedicated IPs. Until `airking.com` is owned and DNS-verified there (SPF + DKIM),
use a single verified personal address as the sender; Brevo rejects unverified
senders outright. Once the domain is verified, switch `MAIL_FROM_ADDRESS` to
`noreply@airking.com`.

Port 587 with `MAIL_SCHEME=smtp` uses STARTTLS. On Laravel 11 the transport is
selected by `MAIL_SCHEME`, not `MAIL_ENCRYPTION`; keep `MAIL_SCHEME=smtp` set.

Reset mail is addressed to each user's `recovery_email`, falling back to their
login `email` when no recovery address is on file.

**Account creation also depends on mail delivery.** Creating a user generates a
temporary password and emails it to their `recovery_email` — the admin never
sees it, so a broken `MAIL_*` config blocks onboarding entirely, not just
password resets. Creation still succeeds when the send fails: the response
carries `credentials_emailed: false` and the admin gets a "Resend" action on the
user row, which mints a fresh password and tries again. Resend is deliberately
limited to accounts that have never completed first login
(`must_change_password` still true); everyone else uses forgot-password.

#### 3b. Then run these, in order (from `back-end/`)

```
composer install --optimize-autoloader --no-dev
php artisan storage:link
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> **`migrate --force` cannot bootstrap an empty database.** The oldest migration
> assumes a `locations` table already exists, and nothing creates it. A new
> server must first be seeded from a database dump, *then* migrated. Keep a dump
> available outside the repo — they are gitignored because they carry real
> users, password hashes, and API tokens.
>
> Migrating an already-seeded database is safe and idempotent: verified against
> a copy of `airkingv5.sql` (336 products, 12 users) — 17 pending migrations
> applied cleanly, data intact, second run reported "Nothing to migrate."

#### 3c. Verify before handing over

```
php artisan about              # Environment: production, Debug Mode: OFF
php artisan schedule:list      # must list 4 jobs (see Scheduled tasks below)
```

> **These are production-only steps.** Do not run `composer install --no-dev` on
> a machine you still code on — it removes PHPUnit, Mockery, Pail and Pint, so
> the test suite stops working. Undo with a plain `composer install`.
> While developing, run `php artisan config:clear` instead of `config:cache`.

### 4. Serve everything through Apache on port 80

The app is served as a **single origin** on port 80 (no CORS): Apache serves the
React build at `/` and reverse-proxies `/api` + `/storage` to Laravel, which runs
natively at the site root on an internal loopback port (`127.0.0.1:8081`).

> **Why the internal port?** Serving Laravel under `Alias /api` makes it strip the
> `/api` prefix, so its `api/*` routes stop matching (you get 404s). Running Laravel
> at root on `:8081` and proxying to it keeps the full path intact — and keeps
> mod_php's multi-request concurrency (unlike `php artisan serve`).

The full vhost lives in the repo at **`apache/airking.conf`**. To enable it:

1. Enable the proxy and headers modules in `C:\xampp\apache\conf\httpd.conf` — uncomment:
   ```
   LoadModule proxy_http_module modules/mod_proxy_http.so
   LoadModule headers_module    modules/mod_headers.so
   ```
   (`mod_proxy`, `mod_rewrite`, `mod_alias` are already on in XAMPP.)

   > `mod_headers` is required. The vhost uses `RequestHeader set
   > X-Forwarded-Proto` to tell Laravel the original scheme — `mod_proxy_http`
   > forwards the client IP and host but **not** the scheme. Without it every
   > request looks like plain HTTP from 127.0.0.1, so the audit trail records
   > the proxy instead of the operator, login rate limiting buckets all clients
   > together, and HTTPS pages get `http://` links.
2. Include the repo vhost from `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:
   ```
   Include "C:/xampp/htdocs/Airking_Inventory/apache/airking.conf"
   ```
3. Adjust the paths inside `apache/airking.conf` only if XAMPP or the project
   is not at `C:\xampp\...`. Change the internal port (8081) if it is taken.
4. Test and restart:
   ```
   C:\xampp\apache\bin\httpd.exe -t     # must say "Syntax OK"
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
path at the top of the script if XAMPP is not at `C:\xampp`.

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
| `analytics:restock` | daily 06:00 | Recomputes restock recommendations |

Schedules are defined in **`routes/console.php`**. They used to live in
`app/Console/Kernel.php`, which Laravel 12 never calls — `Application::configure()`
binds the console kernel to the framework's own class, so an `App\Console\Kernel`
subclass is never resolved and its `schedule()` silently never ran. Confirm with
`php artisan schedule:list`: it must print **four** jobs.

To run them, one Windows Task Scheduler entry every minute is enough — Laravel
decides what is actually due:

```
C:\xampp\php\php.exe C:\xampp\htdocs\Airking_Inventory\back-end\artisan schedule:run
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
