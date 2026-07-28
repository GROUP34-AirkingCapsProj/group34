# Airking Inventory — New Device Setup

Windows + XAMPP. Copy-paste each block in order.

**Requirements:** XAMPP (MySQL/MariaDB running), PHP 8.2+, Composer, Node 18+, Git.

---

## 1. Clone

```powershell
cd C:\XAMPP1\htdocs
git clone https://github.com/GROUP34-AirkingCapsProj/group34.git Airking_Inventory
cd Airking_Inventory
git checkout backupV3
```

---

## 2. Create the database and import the dump

This project is **dump-based, not migration-built** — you cannot `php artisan migrate` from
empty. The dump `airkingv5.sql` is committed at the repo root.

Start MySQL in the XAMPP Control Panel first, then:

```powershell
& C:\XAMPP1\mysql\bin\mysql.exe -u root -e "CREATE DATABASE airkingV5 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cmd /c "C:\XAMPP1\mysql\bin\mysql.exe -u root airkingV5 < airkingv5.sql"
```

> The second line uses `cmd /c` because PowerShell 5.1 has no `<` input redirection.
> **phpMyAdmin alternative:** create database `airkingV5` (utf8mb4_unicode_ci) → Import →
> choose `airkingv5.sql` → Go.

---

## 3. Backend

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\back-end
copy .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan config:clear
```

`php artisan migrate` is **required** — the dump stops at `2026_07_27`, so two newer
migrations still need to run:

- `2026_07_28_000001_add_external_reference_to_issuances_table`
- `2026_07_28_120000_normalize_scan_receiving_numbers`

Skip it and issue-out breaks on a missing `issuances.external_reference` column.

### 3a. Edit `back-end/.env`

**This is the step that breaks installs.** The example ships SQLite with the MySQL lines
commented out. Change all six:

| Line in `.env.example` | Change to |
|---|---|
| `DB_CONNECTION=sqlite` | `DB_CONNECTION=mysql` |
| `# DB_HOST=127.0.0.1` | `DB_HOST=127.0.0.1` *(uncomment)* |
| `# DB_PORT=3306` | `DB_PORT=3306` *(uncomment)* |
| `DB_DATABASE=database/database.sqlite` | `DB_DATABASE=airkingV5` |
| `# DB_USERNAME=root` | `DB_USERNAME=root` *(uncomment)* |
| `# DB_PASSWORD=` | `DB_PASSWORD=` *(uncomment, leave empty for XAMPP)* |

Everything else works as shipped. Leave these alone unless going to LAN or production:

```
APP_URL=http://localhost
CORS_ALLOWED_ORIGINS=          # empty is fine; LAN IPs allowed by default in config/cors.php
REVERB_APP_KEY=airking-local-key   # must match the frontend's REACT_APP_REVERB_APP_KEY
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
```

Run `php artisan config:clear` after **any** `.env` edit or the old values stay cached.

---

## 4. Frontend

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
copy .env.example .env
npm install
```

### 4a. Edit `frontend/.env`

For a single-PC install, **no changes needed** — the defaults are correct:

```
REACT_APP_API_URL=http://127.0.0.1:8000/api
REACT_APP_REVERB_APP_KEY=airking-local-key
REACT_APP_REVERB_PORT=8080
REACT_APP_REVERB_SCHEME=http
```

Only rule: `REACT_APP_REVERB_APP_KEY` must equal `REVERB_APP_KEY` in `back-end/.env`.

CRA reads `.env` **once at startup** — restart `npm run dev` after editing it.

---

## 5. Run

```powershell
cd C:\XAMPP1\htdocs\Airking_Inventory\frontend
npm run dev
```

Starts three panes: `api` :8000, `reverb` :8080, `web` :3000. Browser opens automatically.

| Command | Use |
|---|---|
| `npm run dev` | Normal development (this PC only) |
| `npm run dev:lan` | Other devices on the Wi-Fi — see below |
| `npm run demo` | Production build + serve on :5000. **Much faster** — use for client demos |

`npm run dev` shells out to `php`. If PHP isn't on PATH, the `api` and `reverb` panes die
instantly while `web` keeps running — the app loads but every request fails. Add
`C:\XAMPP1\php` to PATH.

---

## 6. LAN access (phones / other PCs)

Find this PC's IP:

```powershell
(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -like '192.168.*' }).IPAddress
```

Set in `frontend/.env` (replace with your IP):

```
REACT_APP_API_URL=http://192.168.1.50:8000/api
```

Open the firewall (run PowerShell **as Administrator**, once per machine):

```powershell
New-NetFirewallRule -DisplayName "Airking API 8000" -Direction Inbound -LocalPort 8000 -Protocol TCP -Action Allow -Profile Private
New-NetFirewallRule -DisplayName "Airking Reverb 8080" -Direction Inbound -LocalPort 8080 -Protocol TCP -Action Allow -Profile Private
New-NetFirewallRule -DisplayName "Airking Web 3000" -Direction Inbound -LocalPort 3000 -Protocol TCP -Action Allow -Profile Private
```

Then run `npm run dev:lan` and open `http://192.168.1.50:3000` on the other device.

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `SQLSTATE[HY000] [1049] Unknown database` | DB name mismatch — must be `airkingV5`, then `php artisan config:clear` |
| `could not find driver` | Enable `extension=pdo_mysql` in `C:\XAMPP1\php\php.ini`, restart terminal |
| Login works, everything else 500s | Skipped `php artisan migrate` in step 3 |
| Notification bell never fires | `reverb` pane not running, or the two `REVERB_APP_KEY` values differ |
| `.env` change had no effect | Backend: `php artisan config:clear`. Frontend: restart `npm run dev` |
| `php` not recognized | Add `C:\XAMPP1\php` to PATH |
| Site loads but all API calls fail | `api` pane died — scroll up in the terminal for the PHP error |
| Page is slow / laggy | That's the CRA dev server, not the API. Use `npm run demo` |
