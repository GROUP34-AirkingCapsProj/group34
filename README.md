# Airking Inventory

Inventory, procurement, and issuance system for Airking — purchase requests and
orders on supplier payment terms, barcode-scanned receiving, FIFO batch picking,
inter-branch transfers, warranty tracking, and role-based dashboards.

**Stack:** Laravel 12 (PHP 8.2+) API · React 19 (CRA) · MySQL/MariaDB · Laravel
Reverb for realtime · Sanctum auth.

---

## Start here

Pick the path that matches what you're doing:

| I want to… | Do this |
|---|---|
| **Run the system on a new PC** (demo, defense, client) | [One-click setup](#one-click-setup) |
| **Set up to write code** | [Developer setup](docs/SETUP.md) |
| **Fix a slow / laggy install** | [DEPLOYMENT.md](DEPLOYMENT.md) |

> **Dev mode is slow.** `npm run dev` runs the CRA dev server — fine for coding,
> too slow for anyone watching. Always demo the production build.

---

## Prerequisites

Install these first. The setup script does **not** install them for you.

- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL/MariaDB)
- PHP 8.2+ — add `C:\xampp\php` to your **PATH**
- [Composer](https://getcomposer.org/)
- [Node.js 18+](https://nodejs.org/)
- [Git](https://git-scm.com/)

Verify:

```powershell
php -v ; composer -V ; node -v ; git --version
```

If `php` is "not recognized", fix PATH before continuing — every path below
fails without it.

---

## One-click setup

For a machine that just needs to **run** the system.

1. Start **MySQL** in the XAMPP Control Panel.
2. Clone:
   ```powershell
   git clone https://github.com/GROUP34-AirkingCapsProj/group34.git
   cd group34
   ```
3. Right-click **`setup-deploy.bat`** → **Run as administrator**.
   (Elevation is required — it edits `php.ini` and `httpd.conf` and restarts Apache.)
4. Wait 5–15 minutes. Don't close the window; it pauses at the end.
5. Open **http://localhost/**

The script installs dependencies, writes a production `.env`, enables OPcache,
creates and imports the database, runs migrations, builds the frontend, and
configures the Apache vhost. It is **idempotent** — safe to re-run; it won't
overwrite an existing database.

It also detects your XAMPP location automatically, including machines with more
than one install, so no path editing is needed.

**Watch the console for lines starting with `!`** — those are warnings, and some
do not stop the run. If you see `SETUP COMPLETE` but the site misbehaves, scroll
back and look for them.

---

## Developer setup

Writing code, not just running it? Follow **[docs/SETUP.md](docs/SETUP.md)**.

It's a copy-paste guide covering the database import, the six `.env` lines you
must change, the dev servers, LAN access from phones, and a troubleshooting
table. Two steps trip everyone up, both documented there:

- `.env.example` ships `DB_CONNECTION=sqlite` with the MySQL lines commented out.
- `php artisan migrate` is still required after importing the dump.

Day-to-day:

```powershell
cd frontend
npm run dev        # api :8000 + reverb :8080 + web :3000, one terminal
```

| Command | Purpose |
|---|---|
| `npm run dev` | Development on this PC |
| `npm run dev:lan` | Phones / other devices on the same Wi-Fi |
| `npm run demo` | Production build served on :5000 — **use for demos** |

---

## The database

**This project is dump-based, not migration-built.** You cannot create the schema
by running `php artisan migrate` on an empty database — it will fail.

- **`airkingv5.sql`** — the current dump. 73 tables, migration history through
  `2026_07_27`. **This is the one to import.**
- `airking_cleans.sql` — an older snapshot kept for reference. 47 tables,
  stops at `2026_05_10`, missing the set/picking/incident tables. **Do not import
  this**; it produces a system that installs cleanly and then 500s on Issue Out.

After importing, always run:

```powershell
cd back-end
php artisan migrate
```

Some migrations postdate the dump, so this is required, not optional.

When adding a migration, either refresh `airkingv5.sql` or rely on `migrate` to
carry the gap — but don't let the two drift far apart.

---

## Layout

```
back-end/     Laravel 12 API — controllers, services, migrations, tests
frontend/     React 19 (CRA) — admin pages, components, styles
docs/         SETUP.md, plans, specs, QA notes
apache/       airking.conf — production vhost
airkingv5.sql Current database dump
setup-deploy.bat  One-click production installer
```

---

## Tests

```powershell
cd back-end
php artisan test
```

Tests run against a separate `airkingV5_test` database (set in
`back-end/phpunit.xml`), which must exist and hold the same schema. Nearly all
suites use `DatabaseTransactions` rather than `RefreshDatabase` — the schema is
a dump, so a blanket rebuild does not work here. Prefer `DatabaseTransactions`
in new tests.

---

## Common problems

| Symptom | Fix |
|---|---|
| `Unknown database` | Wrong DB name, or MySQL started from a different XAMPP install |
| `could not find driver` | Enable `extension=pdo_mysql` in `php.ini`, restart the terminal |
| Login works, other pages 500 | You skipped `php artisan migrate` |
| `.env` edit did nothing | Backend: `php artisan config:clear`. Frontend: restart `npm run dev` |
| Notification bell silent | Reverb not running, or the two `REVERB_APP_KEY` values differ |
| App feels slow | You're on the dev server — use `npm run demo` |

More detail in [docs/SETUP.md](docs/SETUP.md#troubleshooting).
