# Location Consolidation — Run Instructions

## What Changed
Branch and Warehouse are now unified under **Location**. The `locations` table is the single source of truth.

## To Run the System

### Step 1: Start Backend (Laravel)
```bash
cd c:\XAMPP1\htdocs\Airking_Inventory\back-end

# OPTION A: If you already have data and want to KEEP it
php artisan migrate --seed

# OPTION B: If you want a FRESH database (wipes all data)
php artisan migrate:fresh --seed

# Start the server
php artisan serve
```

### Step 2: Start Frontend (React)
```bash
cd c:\XAMPP1\htdocs\Airking_Inventory\frontend

# Development mode (recommended for testing)
npm start

# Or serve the production build (already compiled)
npx serve -s build
```

### Step 3: Open in Browser
- React frontend: `http://localhost:3000` (or the URL shown in terminal)
- Laravel API: `http://localhost:8000`

### Step 4: Login
Use the default admin credentials from the seeder.

---

## What the Migration Does
The file `back-end/database/migrations/2025_01_15_000000_update_locations_unified.php`:
1. Adds new columns to `locations` table (`code`, `contact_number`, `manager`, `capacity`, `opening_date`, `is_active`)
2. Copies existing **branches** into `locations` with `location_type = 'branch'`
3. Copies existing **warehouses** into `locations` with `location_type = 'warehouse'`
4. Updates `users.branch_id` → `users.location_id`
5. Updates `inventory.warehouse_id` references

---

## Troubleshooting
| Issue | Fix |
|-------|-----|
| Database errors about missing `branches`/`warehouses` tables | The `airking_clean.sql` schema already removed them. Use `php artisan migrate:fresh --seed` to get the new schema. |
| Old `/admin/branches` page still shows | Hard-refresh browser with `Ctrl+F5` |
| Can't see Location Management page | Check sidebar — it should show "Location Management" instead of "Branch Management" + "Warehouse Management" |
