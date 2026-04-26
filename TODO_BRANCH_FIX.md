# Fix `branches` table not found error

## Goal
Update all PHP code that references the old `branches` table to use the unified `locations` table instead.

## Steps
- [x] 1. Rewrite `back-end/app/Models/Branch.php` to proxy to `locations` table
- [x] 2. Update `back-end/app/Http/Controllers/Api/BatchController.php`
- [x] 3. Rewrite `back-end/app/Http/Controllers/Api/BranchController.php` to use `Location`
- [x] 4. Update `back-end/app/Models/Warehouse.php` relationship
- [x] 5. Update `back-end/app/Models/Transaction.php` relationship
- [x] 6. Update `back-end/app/Http/Controllers/Api/WarehouseController.php` validation
- [x] 7. Update `back-end/app/Http/Controllers/Api/TransactionController.php` validation
- [x] 8. Update `back-end/database/seeders/DatabaseSeeder.php`
- [x] 9. Update `back-end/database/seeders/RolesAndBranchesSeeder.php`
- [x] 10. Update `back-end/app/Http/Middleware/CheckBranch.php`

## Status: COMPLETE ✅

