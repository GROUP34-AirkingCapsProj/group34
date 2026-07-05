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

To run it: `npm run demo` (builds + serves on http://localhost:5000),
or serve `frontend/build/` through Apache (recommended — see step 4).

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
php artisan config:cache
php artisan route:cache
php artisan migrate --force   # if using migrations
```

> Re-run `php artisan config:cache` any time you change `.env`.
> During development instead run `php artisan config:clear`.

### 4. Serve the backend through Apache (not `php artisan serve`)
`php artisan serve` handles **one request at a time**. Apache handles many at once
and is faster. Point a XAMPP virtual host (or the docroot) at
`back-end/public`, and serve `frontend/build` as the site root.

---

## Quick setup

Run **`setup-deploy.bat`** in the project root — it runs the build + composer +
artisan caching steps automatically and reminds you about the php.ini OPcache step.

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
