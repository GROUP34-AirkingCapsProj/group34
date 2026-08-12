<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryBatchController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\BrandLookupController;
use App\Http\Controllers\Api\CapacityLookupController;
use App\Http\Controllers\Api\CategoryLookupController;
use App\Http\Controllers\Api\VariantLookupController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SerialController;
use App\Http\Controllers\Api\AnalyticsDashboardController;
// ERD-aligned controllers
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\BudgetAllocationController;
use App\Http\Controllers\Api\PurchaseRequestController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReceivingController;
use App\Http\Controllers\Api\PurchaseReturnController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\DeliveryReceiptController;
use App\Http\Controllers\Api\IssuanceController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\BarcodeAdjustmentController;
use App\Http\Controllers\Api\ProfitLossController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\BarcodeScanController;
use App\Http\Controllers\Api\InventoryScanTransactionController;
use App\Http\Controllers\Api\PendingProductController;
use App\Http\Controllers\Api\InventoryScanEventController;
use App\Http\Controllers\Api\StatusLookupController;
use App\Http\Controllers\Api\TransactionTypeLookupController;
use App\Http\Controllers\Api\UnitLookupController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\AlertSettingsController;
use App\Http\Controllers\Api\AuditTrailController;
use App\Http\Controllers\Api\SystemSessionSettingsController;
use App\Http\Controllers\Api\CompanySettingsController;
use App\Http\Controllers\Api\AccountPolicyController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ForgotPasswordOtpController;
use App\Http\Controllers\Api\WarrantyController;
use App\Http\Controllers\Api\IssuanceScanController;
use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\ArchiveSettingsController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\NavBundleController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\RestockRecommendationController;

/*
|--------------------------------------------------------------------------
| API HEALTH (no auth)
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return response()->json(['message' => 'API is running', 'version' => '1.0']);
});

/*
|--------------------------------------------------------------------------
| AUTH (DO NOT TOUCH)
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:12,1');
Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:12,1');
Route::get('/login/challenge', [AuthController::class, 'loginChallenge'])->middleware('throttle:30,1');
Route::post('/register', [AuthController::class, 'register']);

Route::post('/password-reset/complete', [PasswordResetController::class, 'complete'])->middleware('throttle:12,1');

// OTP-based forgot password (email or SMS delivery)
Route::post('/forgot-password', [ForgotPasswordOtpController::class, 'forgot'])->middleware('throttle:8,1');
Route::post('/verify-reset-otp', [ForgotPasswordOtpController::class, 'verify'])->middleware('throttle:12,1');
Route::post('/reset-password', [ForgotPasswordOtpController::class, 'reset'])->middleware('throttle:8,1');

// Broadcasting auth for private channels — bare auth:sanctum (NOT auditor.readonly /
// location.scope) so auditors can authenticate to the org channel. Channel-level
// authorization is enforced in routes/channels.php via ChannelAccess.
Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::middleware(['auth:sanctum', 'auditor.readonly', 'location.scope', 'extend.sanctum.session'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | AUTH (DO NOT TOUCH)
    |--------------------------------------------------------------------------
    */
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/refresh-token', [AuthController::class, 'refresh']);

/*
    |--------------------------------------------------------------------------
    | DASHBOARD (DO NOT TOUCH)
    |--------------------------------------------------------------------------
    */
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/batch', [BatchController::class, 'index']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/layout-summary', [DashboardController::class, 'layoutSummary']);
    Route::get('/dashboard/low-stock', [DashboardController::class, 'lowStock']);
    Route::get('/dashboard/recent-sales', [DashboardController::class, 'recentSales']);
    Route::get('/dashboard/recent-movements', [DashboardController::class, 'recentMovements']);
    Route::get('/dashboard/stock-movement-trends', [DashboardController::class, 'stockMovementTrends']);
    Route::get('/dashboard/pending-approvals', [DashboardController::class, 'pendingApprovals']);
    Route::get('/dashboard/charts', [DashboardController::class, 'filteredCharts']);
    Route::get('/dashboard/filter-options', [DashboardController::class, 'filterOptions']);

    /*
    |--------------------------------------------------------------------------
    | ALERT SETTINGS (Mark All Read)
    |--------------------------------------------------------------------------
    */
    Route::get('/alerts/settings', [AlertSettingsController::class, 'index']);
    Route::post('/alerts/mark-all-read', [AlertSettingsController::class, 'markAllRead']);

    Route::get('/system/session-settings', [SystemSessionSettingsController::class, 'show']);
    Route::middleware('role:admin')->put('/system/session-settings', [SystemSessionSettingsController::class, 'update']);
    Route::get('/system/company-settings', [CompanySettingsController::class, 'show']);
    Route::middleware('role:admin')->put('/system/company-settings', [CompanySettingsController::class, 'update']);
    Route::middleware('role:admin')->get('/system/account-policy', [AccountPolicyController::class, 'show']);
    Route::middleware('role:admin')->put('/system/account-policy', [AccountPolicyController::class, 'update']);
    Route::middleware(['role:admin', 'throttle:30,1'])->post('/password-reset/issue', [PasswordResetController::class, 'issue']);
    Route::middleware(['role:admin', 'throttle:60,1'])->get('/password-reset/active', [PasswordResetController::class, 'active']);
    Route::middleware(['role:admin', 'throttle:60,1'])->post('/password-reset/active/{passwordResetCode}/revoke', [PasswordResetController::class, 'revoke']);

    /*
    |--------------------------------------------------------------------------
    | USER MANAGEMENT (ADMIN ONLY)
    |--------------------------------------------------------------------------
    */
    // Specific paths declared before the {user} wildcard.
    Route::middleware('permission:user_account_management,read')->group(function () {
        Route::get('/users/archived', [UserController::class, 'archived']);
        Route::get('/users/by-location/{locationId}', [UserController::class, 'byLocation']);
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show']);
    });
    Route::middleware('permission:user_account_management,create')->post('/users', [UserController::class, 'store']);
    Route::middleware('permission:user_account_management,update')->group(function () {
        Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update']);
        Route::put('/users/{user}/change-password', [UserController::class, 'changePassword']);
        Route::put('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::post('/users/{id}/restore', [UserController::class, 'restore']);
    });
    Route::middleware('permission:user_account_management,archive')->group(function () {
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::delete('/users/{id}/force', [UserController::class, 'forceDestroy']);
    });

    // Current user's own effective permissions (any authenticated user)
    Route::get('/me/permissions', [PermissionController::class, 'mine']);

    // Self-service account settings (any authenticated user, own account only)
    Route::get('/me/profile', [AccountController::class, 'show']);
    Route::put('/me/profile', [AccountController::class, 'update']);
    Route::post('/me/avatar', [AccountController::class, 'uploadAvatar']);
    Route::delete('/me/avatar', [AccountController::class, 'deleteAvatar']);
    Route::put('/me/email', [AccountController::class, 'updateEmail']);
    Route::put('/me/password', [AccountController::class, 'updatePassword']);

    Route::get('/nav-bundles', [NavBundleController::class, 'index']);

    /* PERMISSION MANAGEMENT (ADMIN ONLY) */
    Route::middleware('role:admin')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::get('/role-permissions', [PermissionController::class, 'roleDefaults']);
        Route::put('/role-permissions', [PermissionController::class, 'updateRoleDefaults']);
        Route::get('/users/{userId}/permissions', [PermissionController::class, 'userPermissions']);
        Route::put('/users/{userId}/permissions', [PermissionController::class, 'updateUserPermissions']);
    });
    // Permission Audit is read-only log viewing, so it is delegable via its own
    // permission (unlike the editor endpoints above, which stay admin-only to
    // avoid privilege escalation). Admin is always allowed via the resolver bypass.
    Route::middleware('permission:permission_audit,read')
        ->get('/permission-audit-logs', [PermissionController::class, 'auditLogs']);

    /*
    |--------------------------------------------------------------------------
    | ERD: ROLES (roles)
    |--------------------------------------------------------------------------
    */
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/{id}', [RoleController::class, 'show']);
    Route::middleware('permission:role_assignment,create')->post('/roles', [RoleController::class, 'store']);
    Route::middleware('permission:role_assignment,update')->put('/roles/{id}', [RoleController::class, 'update']);
    Route::middleware('permission:role_assignment,archive')->delete('/roles/{id}', [RoleController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: STATUS (status_lookup)
    |--------------------------------------------------------------------------
    */
    Route::get('/status-lookup', [StatusLookupController::class, 'index']);
    Route::get('/status-lookup/{id}', [StatusLookupController::class, 'show']);
    Route::middleware('role:admin')->group(function () {
        Route::post('/status-lookup', [StatusLookupController::class, 'store']);
        Route::put('/status-lookup/{id}', [StatusLookupController::class, 'update']);
        Route::delete('/status-lookup/{id}', [StatusLookupController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: TRANSACTION TYPE (transaction_type_lookup)
    |--------------------------------------------------------------------------
    */
    Route::get('/transaction-type-lookup', [TransactionTypeLookupController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | INVENTORY SCAN EVENTS (audit trail)
    |--------------------------------------------------------------------------
    */
    Route::get('/scan-events', [InventoryScanEventController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | ARCHIVE MANAGEMENT — unified endpoints
    |--------------------------------------------------------------------------
    */
    Route::prefix('archive')->group(function () {
        Route::middleware('permission:archive_viewing,read')->get('/summary',                    [ArchiveController::class, 'summary']);
        Route::middleware('permission:archive_viewing,read')->get('/{module}',                   [ArchiveController::class, 'index']);
        Route::middleware('permission:data_archiving,update')->post('/{module}/{id}/restore',     [ArchiveController::class, 'restore']);
        Route::middleware('permission:data_archiving,archive')->delete('/{module}/{id}/force',   [ArchiveController::class, 'forceDestroy']);
        Route::middleware('permission:data_archiving,update')->post('/{module}/restore-all',    [ArchiveController::class, 'restoreAll']);
    });

    /*
    |--------------------------------------------------------------------------
    | ARCHIVE SETTINGS — configuration, scheduler, run history
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')
         ->prefix('archive-settings')
         ->group(function () {
             Route::get('/',                  [ArchiveSettingsController::class, 'index']);
             Route::put('/module/{module}',   [ArchiveSettingsController::class, 'updateModule']);
             Route::put('/schedule',          [ArchiveSettingsController::class, 'updateSchedule']);
             Route::post('/preview',          [ArchiveSettingsController::class, 'preview']);
             Route::post('/run-now',          [ArchiveSettingsController::class, 'runNow']);
             Route::post('/run-cleanup',      [ArchiveSettingsController::class, 'runCleanup']);
             Route::get('/logs',              [ArchiveSettingsController::class, 'logs']);
         });

    /*
    |--------------------------------------------------------------------------
    | ERD: CATEGORY (category_lookup)
    |--------------------------------------------------------------------------
    */
    Route::get('/categories/performance', [CategoryLookupController::class, 'performance']);
    Route::get('/categories/archived', [CategoryLookupController::class, 'archived']);
    Route::get('/categories', [CategoryLookupController::class, 'index']);
    Route::get('/categories/{category}', [CategoryLookupController::class, 'show']);
    Route::middleware('permission:category_management,create')->post('/categories', [CategoryLookupController::class, 'store']);
    Route::middleware('permission:category_management,update')->put('/categories/{category}', [CategoryLookupController::class, 'update']);
    Route::middleware('permission:category_management,archive')->delete('/categories/{category}', [CategoryLookupController::class, 'destroy']);
    Route::middleware('permission:category_management,archive')->post('/categories/{category}/restore', [CategoryLookupController::class, 'restore']);
    Route::middleware('permission:category_management,archive')->delete('/categories/{category}/force', [CategoryLookupController::class, 'forceDestroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: BRAND (brand_lookup)
    |--------------------------------------------------------------------------
    */
    Route::get('/brands/archived', [BrandLookupController::class, 'archived']);
    Route::get('/brands', [BrandLookupController::class, 'index']);
    Route::get('/brands/{brand}', [BrandLookupController::class, 'show']);
    Route::middleware('permission:brand_management,create')->post('/brands', [BrandLookupController::class, 'store']);
    Route::middleware('permission:brand_management,update')->put('/brands/{brand}', [BrandLookupController::class, 'update']);
    Route::middleware('permission:brand_management,archive')->delete('/brands/{brand}', [BrandLookupController::class, 'destroy']);
    Route::middleware('permission:brand_management,archive')->post('/brands/{brand}/restore', [BrandLookupController::class, 'restore']);
    Route::middleware('permission:brand_management,archive')->delete('/brands/{brand}/force', [BrandLookupController::class, 'forceDestroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: UNIT LOOKUP (unit_lookup)
    |--------------------------------------------------------------------------
    */
    // All five item-master lookups (units, categories, brands, capacities,
    // variants) leave their GETs ungated for any
    // authenticated user: they are classification vocabularies feeding
    // pickers used app-wide — ProductRegisterModal, BarcodeScan,
    // OrderBuilder, InventoryManagement, BudgetManagementPage,
    // SetManagementPage — not sensitive records. In particular,
    // ProductRegisterModal (rendered from BarcodeScan.jsx, gated on
    // barcode_inventory_processing.create — admin, branch_manager,
    // warehouse_personnel) uses strict capacity/variant dropdowns, while
    // lookup_management.read only grants admin, inventory_analyst, auditor.
    // Those role sets overlap only on admin, so gating these reads would
    // leave warehouse/branch users with empty dropdowns and unable to
    // register an appliance. The unified Item Lookups admin page itself is
    // still gated at the nav/route level on lookup_management.read — do NOT
    // re-add a permission: middleware to any of these six GET routes "for
    // consistency"; that re-breaks registration for two of the three roles
    // that need it.
    //
    // Writes are NOT all implemented on the same key: capacities, variants,
    // and units gate create/update/archive on
    // permission:lookup_management, while categories and brands deliberately
    // keep their own long-standing category_management / brand_management
    // keys so nobody's effective access changed when this page shipped.
    // PermissionSeeder::matrix() grants category_management and
    // brand_management the exact same role sets as lookup_management for
    // create/update/archive, so all six lookups behave identically from a
    // user's perspective even though two are wired through different keys —
    // see LookupRouteAccessTest for the test that pins that equivalence.
    Route::get('/units/archived', [UnitLookupController::class, 'archived']);
    Route::get('/units', [UnitLookupController::class, 'index']);
    Route::get('/units/{id}', [UnitLookupController::class, 'show']);
    Route::middleware('permission:lookup_management,create')
        ->post('/units', [UnitLookupController::class, 'store']);
    Route::middleware('permission:lookup_management,update')
        ->put('/units/{id}', [UnitLookupController::class, 'update']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/units/{id}', [UnitLookupController::class, 'destroy']);
    Route::middleware('permission:lookup_management,archive')
        ->post('/units/{id}/restore', [UnitLookupController::class, 'restore']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/units/{id}/force', [UnitLookupController::class, 'forceDestroy']);

    /* ---------------------------------------------------------------
     | Capacities (Item Lookups page - Appliance tab)
     | 'archived' precedes '{id}' so it is not swallowed by the wildcard.
     | Reads are deliberately ungated — see the comment above the
     | unit_lookup routes for why (ProductRegisterModal / BarcodeScan role
     | mismatch).
     * ------------------------------------------------------------- */
    Route::get('/capacities/archived', [CapacityLookupController::class, 'archived']);
    Route::get('/capacities', [CapacityLookupController::class, 'index']);
    Route::get('/capacities/{id}', [CapacityLookupController::class, 'show']);
    Route::middleware('permission:lookup_management,create')
        ->post('/capacities', [CapacityLookupController::class, 'store']);
    Route::middleware('permission:lookup_management,update')
        ->put('/capacities/{id}', [CapacityLookupController::class, 'update']);
    Route::middleware('permission:lookup_management,update')
        ->post('/capacities/{id}/merge', [CapacityLookupController::class, 'merge']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/capacities/{id}', [CapacityLookupController::class, 'destroy']);
    Route::middleware('permission:lookup_management,archive')
        ->post('/capacities/{id}/restore', [CapacityLookupController::class, 'restore']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/capacities/{id}/force', [CapacityLookupController::class, 'forceDestroy']);

    /* ---------------------------------------------------------------
     | Variants (Item Lookups page - Appliance tab)
     | 'archived' precedes '{id}' so it is not swallowed by the wildcard.
     | Reads are deliberately ungated — see the comment above the
     | unit_lookup routes for why (ProductRegisterModal / BarcodeScan role
     | mismatch).
     * ------------------------------------------------------------- */
    Route::get('/variants/archived', [VariantLookupController::class, 'archived']);
    Route::get('/variants', [VariantLookupController::class, 'index']);
    Route::get('/variants/{id}', [VariantLookupController::class, 'show']);
    Route::middleware('permission:lookup_management,create')
        ->post('/variants', [VariantLookupController::class, 'store']);
    Route::middleware('permission:lookup_management,update')
        ->put('/variants/{id}', [VariantLookupController::class, 'update']);
    Route::middleware('permission:lookup_management,update')
        ->post('/variants/{id}/merge', [VariantLookupController::class, 'merge']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/variants/{id}', [VariantLookupController::class, 'destroy']);
    Route::middleware('permission:lookup_management,archive')
        ->post('/variants/{id}/restore', [VariantLookupController::class, 'restore']);
    Route::middleware('permission:lookup_management,archive')
        ->delete('/variants/{id}/force', [VariantLookupController::class, 'forceDestroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: LOCATION (location) — unified physical places
    |--------------------------------------------------------------------------
    */
    Route::get('/locations/archived', [LocationController::class, 'archived']);
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/locations/{location}', [LocationController::class, 'show']);
    Route::middleware('permission:location_based_inventory,create')->post('/locations', [LocationController::class, 'store']);
    Route::middleware('permission:location_based_inventory,update')->put('/locations/{location}', [LocationController::class, 'update']);
    Route::middleware('permission:location_based_inventory,archive')->delete('/locations/{location}', [LocationController::class, 'destroy']);
    Route::middleware('permission:location_based_inventory,archive')->post('/locations/{id}/restore', [LocationController::class, 'restore']);
    Route::middleware('permission:location_based_inventory,archive')->delete('/locations/{id}/force', [LocationController::class, 'forceDestroy']);

    /*
    |--------------------------------------------------------------------------
    | BACKWARD COMPAT: /branches & /warehouses → proxy to /locations
    |--------------------------------------------------------------------------
    */
    Route::get('/branches', function (Illuminate\Http\Request $request) {
        $request->merge(['location_type' => 'branch_office']);
        return app()->call([app(LocationController::class), 'index']);
    });
    Route::get('/warehouses', function (Illuminate\Http\Request $request) {
        $request->merge(['location_type' => 'warehouse']);
        return app()->call([app(LocationController::class), 'index']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: PRODUCT (Product table)
    |--------------------------------------------------------------------------
    */
    Route::get('/products/archived', [ProductController::class, 'archived']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/export', [ProductController::class, 'export']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::middleware('permission:item_master_management,create')->post('/products', [ProductController::class, 'store']);
    Route::middleware('permission:item_master_management,update')->put('/products/{product}', [ProductController::class, 'update']);
    Route::middleware('permission:item_master_management,archive')->delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::middleware('permission:item_master_management,archive')->post('/products/{id}/restore', [ProductController::class, 'restore']);
    Route::middleware('permission:item_master_management,archive')->delete('/products/{id}/force', [ProductController::class, 'forceDestroy']);

    // Legacy alias: /items → products
    Route::get('/items', [ProductController::class, 'index']);
    Route::get('/items/{product}', [ProductController::class, 'show']);
    Route::middleware('permission:item_master_management,create')->post('/items', [ProductController::class, 'store']);
    Route::middleware('permission:item_master_management,update')->put('/items/{product}', [ProductController::class, 'update']);
    Route::middleware('permission:item_master_management,archive')->delete('/items/{product}', [ProductController::class, 'destroy']);

    Route::get('/consumable-supply/catalog', [PendingProductController::class, 'catalog']);
    Route::get('/supply-types', [PendingProductController::class, 'supplyTypesIndex']);
    Route::middleware('permission:item_master_management,create')->post('/pending-products', [PendingProductController::class, 'store']);
    // List: procurement/audit visibility; approve: same as create submitters who run Item Master workflow
    Route::middleware('permission:item_master_management,read')->get('/pending-products', [PendingProductController::class, 'index']);
    Route::middleware('permission:item_master_management,approve')->post('/pending-products/{pendingProduct}/approve', [PendingProductController::class, 'approve']);

    /*
    |--------------------------------------------------------------------------
    | ERD: ITEM_SERIAL (item_serial)
    |--------------------------------------------------------------------------
    */
    Route::get('/serials', [SerialController::class, 'index']);
    Route::get('/serials/{serial}', [SerialController::class, 'show']);
    Route::post('/serials/scan', [SerialController::class, 'scan']);
    Route::middleware('permission:barcode_assignment,create')->post('/serials', [SerialController::class, 'store']);
    Route::middleware('permission:barcode_assignment,update')->put('/serials/{serial}', [SerialController::class, 'update']);
    Route::middleware('permission:barcode_assignment,archive')->delete('/serials/{serial}', [SerialController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: SUPPLIER (supplier)
    |--------------------------------------------------------------------------
    */
    Route::get('/suppliers/archived', [SupplierController::class, 'archived']);
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
    Route::get('/suppliers/{id}/products', [SupplierController::class, 'products']);
    Route::middleware('permission:supplier_management,create')->post('/suppliers', [SupplierController::class, 'store']);
    Route::middleware('permission:supplier_management,update')->put('/suppliers/{id}', [SupplierController::class, 'update']);
    Route::middleware('permission:item_supplier_mapping,create')->post('/suppliers/{id}/products', [SupplierController::class, 'addProduct']);
    Route::middleware('permission:item_supplier_mapping,update')->put('/suppliers/{id}/products/{supplierProdId}', [SupplierController::class, 'updateProduct']);
    Route::middleware('permission:item_supplier_mapping,update')->delete('/suppliers/{id}/products/{productId}', [SupplierController::class, 'removeProduct']);
    Route::middleware('permission:supplier_management,archive')->delete('/suppliers/{id}', [SupplierController::class, 'destroy']);
    Route::middleware('permission:supplier_management,archive')->post('/suppliers/{id}/restore', [SupplierController::class, 'restore']);
    Route::middleware('permission:supplier_management,archive')->delete('/suppliers/{id}/force', [SupplierController::class, 'forceDestroy']);

    /*
    |--------------------------------------------------------------------------
    | Customer Management
    |--------------------------------------------------------------------------
    */
    Route::get('/customers/export',          [CustomerController::class, 'export']);
    Route::get('/customers/search',          [CustomerController::class, 'search']);
    Route::get('/customers/archived',        [CustomerController::class, 'archived']);
    Route::get('/customers/{id}/history',    [CustomerController::class, 'history']);
    Route::get('/customers',                 [CustomerController::class, 'index']);
    Route::get('/customers/{id}',            [CustomerController::class, 'show']);
    Route::post('/customers',                [CustomerController::class, 'store']);
    Route::put('/customers/{id}',            [CustomerController::class, 'update']);
    Route::delete('/customers/{id}',         [CustomerController::class, 'destroy']);
    Route::post('/customers/{id}/restore',   [CustomerController::class, 'restore']);
    Route::middleware('role:admin')->delete('/customers/{id}/force', [CustomerController::class, 'forceDestroy']);

    /*
    |--------------------------------------------------------------------------
    | Warranty Management
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:warranty_records,read')->group(function () {
        Route::get('/warranties/export',             [WarrantyController::class, 'export']);
        Route::get('/warranties/lookup/{serial}',    [WarrantyController::class, 'lookupBySerial']);
        Route::get('/warranties',                    [WarrantyController::class, 'index']);
        Route::get('/warranties/{id}',               [WarrantyController::class, 'show']);
    });
    Route::middleware('permission:warranty_records,update')->put('/warranties/{id}/claim', [WarrantyController::class, 'markClaimed']);

    /*
    |--------------------------------------------------------------------------
    | Warranty Claims (repair / replacement)
    |--------------------------------------------------------------------------
    */
    Route::prefix('warranty-claims')->group(function () {
        Route::middleware('permission:warranty_claims,read')->group(function () {
            Route::get('/',               [\App\Http\Controllers\Api\WarrantyClaimController::class, 'index']);
            Route::get('/{claimId}',      [\App\Http\Controllers\Api\WarrantyClaimController::class, 'show']);
            Route::get('/{claimId}/slip', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'downloadSlip']);
            Route::get('/{claimId}/receipt/pdf', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'receiptPdf']);
        });
        Route::middleware('permission:warranty_claims,create')->group(function () {
            Route::post('/init',           [\App\Http\Controllers\Api\WarrantyClaimController::class, 'init']);
            Route::post('/{claimId}/order/custom', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'addCustomOrderLine']);
            Route::post('/{claimId}/order/set', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'addSetOrder']);
            Route::post('/{claimId}/item', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'addItem']);
        });
        Route::middleware('permission:warranty_claims,update')->group(function () {
            Route::put('/{claimId}/order/{detailId}', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'updateOrderLine']);
            Route::delete('/{claimId}/order/{detailId}', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'removeOrderLine']);
            Route::delete('/{claimId}/item/{detailId}', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'removeItem']);
        });
        Route::middleware('permission:warranty_claims,approve')->post('/{claimId}/complete', [\App\Http\Controllers\Api\WarrantyClaimController::class, 'complete']);
    });

    /*
    |--------------------------------------------------------------------------
    | Issuance Scan (barcode-driven issuance flow)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:inventory_issuance,create')
         ->prefix('issuance-scan')
         ->group(function () {
             Route::post('/init',
                 [IssuanceScanController::class, 'initSession']);
             Route::get('/active-draft',
                 [IssuanceScanController::class, 'activeDraft']);
             Route::post('/scan',
                 [IssuanceScanController::class, 'scanItem']);
             Route::delete('/item/{detail_id}',
                 [IssuanceScanController::class, 'removeItem']);
             Route::post('/complete/{issuance_id}',
                 [IssuanceScanController::class, 'completeIssuance']);
             Route::post('/{issuance_id}/revert-to-draft',
                 [IssuanceScanController::class, 'revertToDraft']);
             Route::get('/session/{issuance_id}',
                 [IssuanceScanController::class, 'getSessionStatus']);
         });

    /*
    |--------------------------------------------------------------------------
    | Product Sets (BOQ templates)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:product_set_management,read')->group(function () {
        Route::get('/product-sets', [\App\Http\Controllers\Api\ProductSetController::class, 'index']);
    });
    // Must be registered before /product-sets/{id} below, or "archived" is
    // swallowed as an {id}.
    Route::middleware('permission:product_set_management,archive')
         ->get('/product-sets/archived', [\App\Http\Controllers\Api\ProductSetController::class, 'archived']);
    Route::middleware('permission:product_set_management,read')
         ->get('/product-sets/{id}', [\App\Http\Controllers\Api\ProductSetController::class, 'show']);
    Route::middleware('permission:product_set_management,create')
         ->post('/product-sets', [\App\Http\Controllers\Api\ProductSetController::class, 'store']);
    Route::middleware('permission:product_set_management,update')
         ->put('/product-sets/{id}', [\App\Http\Controllers\Api\ProductSetController::class, 'update']);
    Route::middleware('permission:product_set_management,archive')->group(function () {
        Route::delete('/product-sets/{id}', [\App\Http\Controllers\Api\ProductSetController::class, 'destroy']);
        Route::post('/product-sets/{id}/restore', [\App\Http\Controllers\Api\ProductSetController::class, 'restore']);
    });

    /*
    |--------------------------------------------------------------------------
    | Incident Reports (FIFO-violation overrides)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:incident_reports,read')
         ->get('/incident-reports', [\App\Http\Controllers\Api\IncidentReportController::class, 'index']);
    // Declared BEFORE /incident-reports/{id}/photo so `lookup` is never
    // captured as an {id}.
    Route::middleware('permission:incident_reports,create')
         ->get('/incident-reports/lookup', [\App\Http\Controllers\Api\IncidentReportController::class, 'lookup']);
    Route::middleware('permission:incident_reports,create')
         ->post('/incident-reports', [\App\Http\Controllers\Api\IncidentReportController::class, 'store']);
    Route::middleware(['permission:incident_reports,create', 'throttle:30,1'])
         ->post('/incident-reports/photo', [\App\Http\Controllers\Api\IncidentReportController::class, 'uploadPhoto']);
    // Evidence photos live on the private `local` disk; this is the only
    // route allowed to read them back, gated the same way index() is.
    // auth:sanctum + Bearer token, not ?token=, so the frontend fetches with
    // an Authorization header and hands the browser a blob — same pattern as
    // the Goods Received Note downloads below.
    Route::middleware(['auth:sanctum', 'permission:incident_reports,read'])
         ->get('/incident-reports/{id}/photo', [\App\Http\Controllers\Api\IncidentReportController::class, 'showPhoto']);
    /*
    |--------------------------------------------------------------------------
    | Inspection (before the admin decides) and the decision itself
    |--------------------------------------------------------------------------
    | The inspector attaches evidence and records an outcome for each physical
    | item, then submits; the admin confirms, rejects, or dismisses. `inspect`
    | and `resolve` are deliberately different permissions — the client's form
    | carries two signature lines so that one person cannot both examine the
    | goods and approve their write-off.
    */
    // The read side of the inspection: one report with its items, its evidence
    // and the inspector's findings. Declared BEFORE the {id}/... routes for
    // readability only — it cannot shadow them, being a different depth.
    // `read`, not `inspect`: the admin who decides holds resolve + read and
    // must be able to see what was submitted.
    Route::middleware('permission:incident_reports,read')
         ->get('/incident-reports/{id}', [\App\Http\Controllers\Api\IncidentInspectionController::class, 'show'])
         ->whereNumber('id');
    Route::middleware('permission:incident_reports,inspect')
         ->post('/incident-reports/{id}/evidence', [\App\Http\Controllers\Api\IncidentInspectionController::class, 'storeEvidence']);
    Route::middleware('permission:incident_reports,inspect')
         ->delete('/incident-reports/{id}/evidence/{evidenceId}', [\App\Http\Controllers\Api\IncidentInspectionController::class, 'destroyEvidence']);
    // Read-side: the admin reviewing a submission needs to see the photos, and
    // holds `read`, not `inspect`. Private `local` disk behind auth:sanctum,
    // exactly like the filing photo above.
    Route::middleware(['auth:sanctum', 'permission:incident_reports,read'])
         ->get('/incident-reports/{id}/evidence/{evidenceId}/photo', [\App\Http\Controllers\Api\IncidentInspectionController::class, 'showEvidencePhoto']);
    Route::middleware('permission:incident_reports,inspect')
         ->post('/incident-reports/{id}/items/{itemId}/outcome', [\App\Http\Controllers\Api\IncidentInspectionController::class, 'setOutcome']);
    Route::middleware('permission:incident_reports,inspect')
         ->post('/incident-reports/{id}/inspection', [\App\Http\Controllers\Api\IncidentInspectionController::class, 'submit']);
    Route::middleware('permission:incident_reports,resolve')
         ->post('/incident-reports/{id}/decide', [\App\Http\Controllers\Api\IncidentInspectionController::class, 'decide']);

    /*
    |--------------------------------------------------------------------------
    | The incident-items register (replaces /stock-holds)
    |--------------------------------------------------------------------------
    | Read-only: what is quarantined, scrapped, returned or missing. The old
    | worklist's Dispose action is gone — the inspection replaced it, and
    | `incident_reports.dispose` with it.
    */
    Route::middleware('permission:incident_reports,read')
         ->get('/incident-items', [\App\Http\Controllers\Api\IncidentInspectionController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | ERD: PURCHASE_ORDER (purchase_order)
    |--------------------------------------------------------------------------
    */
    Route::get('/purchase-orders/archived', [PurchaseOrderController::class, 'archived']);
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    // Specific routes before {id} wildcard
    Route::middleware('permission:purchase_order_creation,read')->get('/purchase-orders/export', [PurchaseOrderController::class, 'export']);
    Route::middleware('permission:purchase_order_creation,read')->get('/purchase-orders/products-by-supplier/{supplier_id}', [PurchaseOrderController::class, 'productsBySupplier']);
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::middleware('permission:purchase_order_creation,read')->get('/purchase-orders/{id}/pdf', [PurchaseOrderController::class, 'pdf']);
    Route::get('/purchase-orders/{id}/history', [PurchaseOrderController::class, 'history']);
    Route::middleware('permission:purchase_order_creation,create')->post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::middleware('permission:purchase_order_creation,update')->put('/purchase-orders/{id}', [PurchaseOrderController::class, 'update']);
    Route::middleware('permission:purchase_order_creation,archive')->delete('/purchase-orders/{id}', [PurchaseOrderController::class, 'destroy']);
    Route::middleware('permission:purchase_order_creation,archive')->post('/purchase-orders/{id}/restore', [PurchaseOrderController::class, 'restore']);
    Route::middleware('permission:purchase_order_creation,archive')->delete('/purchase-orders/{id}/force', [PurchaseOrderController::class, 'forceDestroy']);

    Route::middleware('permission:purchase_order_verification,verify')->group(function () {
        Route::patch('/purchase-orders/{id}/verify', [PurchaseOrderController::class, 'verify']);
        Route::patch('/purchase-orders/{id}/reject', [PurchaseOrderController::class, 'reject']);
    });

    /*
    |--------------------------------------------------------------------------
    | BUDGET ALLOCATIONS
    | Read: admin + inventory_analyst (IA needs to see budget on PR create form)
    | Write: admin only
    |--------------------------------------------------------------------------
    */
    Route::get('/budget-allocations/archived', [BudgetAllocationController::class, 'archived']);
    Route::middleware('role:admin,inventory_analyst,auditor')->group(function () {
        Route::get('/budget-allocations', [BudgetAllocationController::class, 'index']);
        Route::get('/budget-allocations/{id}', [BudgetAllocationController::class, 'show']);
        Route::get('/budget-allocations/{id}/available', [BudgetAllocationController::class, 'available']);
        Route::get('/budget-allocations/{id}/transactions', [BudgetAllocationController::class, 'transactions']);
    });
    // Budget CRUD is now permission-driven so admin can delegate it (admin always
    // passes via the resolver bypass). Defaults grant these to admin only.
    Route::middleware('permission:budget_verification,create')->post('/budget-allocations', [BudgetAllocationController::class, 'store']);
    Route::middleware('permission:budget_verification,update')->put('/budget-allocations/{id}', [BudgetAllocationController::class, 'update']);
    Route::middleware('permission:budget_verification,archive')->group(function () {
        Route::delete('/budget-allocations/{id}', [BudgetAllocationController::class, 'destroy']);
        Route::post('/budget-allocations/{id}/restore', [BudgetAllocationController::class, 'restore']);
        Route::delete('/budget-allocations/{id}/force', [BudgetAllocationController::class, 'forceDestroy']);
    });
    Route::middleware('permission:budget_verification,verify')->group(function () {
        Route::patch('/budget-allocations/{id}/verify', [BudgetAllocationController::class, 'verify']);
        Route::patch('/budget-allocations/{id}/reject', [BudgetAllocationController::class, 'reject']);
    });

    /*
    |--------------------------------------------------------------------------
    | PURCHASE REQUESTS (PR → PO workflow)
    |--------------------------------------------------------------------------
    */

    // Export and archive (before {id} wildcard)
    Route::middleware('permission:purchase_request_creation,read')->group(function () {
        Route::get('/purchase-requests/archived', [PurchaseRequestController::class, 'archived']);
        Route::get('/purchase-requests/export', [PurchaseRequestController::class, 'export']);
        Route::get('/purchase-requests/low-stock', [PurchaseRequestController::class, 'lowStock']);
    });

    Route::middleware('permission:purchase_request_creation,read')->get('/purchase-requests/{id}', [PurchaseRequestController::class, 'show']);
    Route::middleware('permission:purchase_request_creation,read')
        ->get('/purchase-requests/{id}/history', [PurchaseRequestController::class, 'history']);

    // PR approval — gated by the DB permission system (purchase_request_approval, approve).
    // Default grants SA only; admins can grant others via the Role Permissions editor / overrides.
    Route::middleware('permission:purchase_request_approval,approve')->group(function () {
        Route::get('/purchase-requests/pending/list', [PurchaseRequestController::class, 'pending']);
        Route::patch('/purchase-requests/{id}/approve', [PurchaseRequestController::class, 'approve']);
        Route::patch('/purchase-requests/{id}/reject', [PurchaseRequestController::class, 'reject']);
    });

    // PR creation / listing / conversion
    Route::middleware('permission:purchase_request_creation,read')->group(function () {
        Route::get('/purchase-requests', [PurchaseRequestController::class, 'index']);
        Route::get('/purchase-requests/{id}/convert', [PurchaseRequestController::class, 'convert']);
    });
    Route::middleware('permission:purchase_request_creation,create')->group(function () {
        Route::post('/purchase-requests', [PurchaseRequestController::class, 'store']);
        Route::post('/purchase-requests/{id}/convert', [PurchaseRequestController::class, 'convertStore']);
    });
    Route::middleware('permission:purchase_request_creation,archive')->group(function () {
        Route::delete('/purchase-requests/{id}', [PurchaseRequestController::class, 'destroy']);
        Route::post('/purchase-requests/{id}/restore', [PurchaseRequestController::class, 'restore']);
        Route::delete('/purchase-requests/{id}/force', [PurchaseRequestController::class, 'forceDestroy']);
    });

    /*
    |----------------------------------------------------------------------
    | PR Recommendation analytics (restock engine)
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:po_recommendations,read')->group(function () {
        Route::get('/restock/recommendations', [RestockRecommendationController::class, 'index']);
        Route::get('/restock/suggestions', [RestockRecommendationController::class, 'suggestions']);
    });
    Route::middleware('permission:po_recommendations,create')->group(function () {
        Route::post('/restock/recalculate', [RestockRecommendationController::class, 'recalculate']);
    });
    Route::middleware('permission:po_recommendations,update')->group(function () {
        Route::post('/restock/suggestions/{id}/apply', [RestockRecommendationController::class, 'applySuggestion']);
        Route::post('/restock/suggestions/{id}/dismiss', [RestockRecommendationController::class, 'dismissSuggestion']);
    });

    /*
    |----------------------------------------------------------------------
    | Analytics Dashboard (admin / inventory analyst / auditor)
    |----------------------------------------------------------------------
    | Spec: docs/superpowers/specs/2026-08-02-analytics-dashboard-backend-design.md
    */
    Route::middleware('permission:analytics_dashboard,read')->group(function () {
        Route::get('/analytics/stock-level',          [AnalyticsDashboardController::class, 'stockLevel']);
        Route::get('/analytics/movement',             [AnalyticsDashboardController::class, 'movement']);
        Route::get('/analytics/predictions',          [AnalyticsDashboardController::class, 'predictions']);
        Route::get('/analytics/profit-loss',          [AnalyticsDashboardController::class, 'profitLoss']);
        Route::get('/analytics/location-performance', [AnalyticsDashboardController::class, 'locationPerformance']);
        Route::get('/analytics/filters',              [AnalyticsDashboardController::class, 'filters']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: RECEIVING (RECEIVING)
    |--------------------------------------------------------------------------
    */
    Route::get('/receivings', [ReceivingController::class, 'index']);
    Route::get('/receivings/po-scan-status/{poId}', [ReceivingController::class, 'poScanStatus']);
    Route::get('/receivings/po-log', [ReceivingController::class, 'poLog']);
    Route::get('/receivings/{id}', [ReceivingController::class, 'show']);
    Route::middleware('permission:delivery_receiving,create')->post('/receivings', [ReceivingController::class, 'store']);
    Route::middleware('permission:delivery_receiving,update')->put('/receivings/{id}', [ReceivingController::class, 'update']);
    Route::middleware('permission:delivery_receiving,archive')->delete('/receivings/{id}', [ReceivingController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: PURCHASE_RETURN (purchase_return)
    |--------------------------------------------------------------------------
    */
    Route::get('/purchase-returns', [PurchaseReturnController::class, 'index']);
    Route::get('/purchase-returns/{id}', [PurchaseReturnController::class, 'show']);
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::post('/purchase-returns', [PurchaseReturnController::class, 'store']);
        Route::put('/purchase-returns/{id}', [PurchaseReturnController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/purchase-returns/{id}', [PurchaseReturnController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: SALES (sales)
    |--------------------------------------------------------------------------
    */
    Route::get('/sales', [SaleController::class, 'index']);
    Route::get('/sales/{id}', [SaleController::class, 'show']);
    Route::middleware('permission:sales_recording,create')->post('/sales', [SaleController::class, 'store']);
    Route::middleware('permission:sales_recording,update')->put('/sales/{id}', [SaleController::class, 'update']);
    Route::middleware('permission:sales_recording,archive')->delete('/sales/{id}', [SaleController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: DELIVERY_RECEIPT (delivery_receipt)
    |--------------------------------------------------------------------------
    */
    Route::get('/delivery-receipts', [DeliveryReceiptController::class, 'index']);
    Route::get('/delivery-receipts/{id}', [DeliveryReceiptController::class, 'show']);
    Route::middleware('permission:delivery_receipt_generation,create')->post('/delivery-receipts', [DeliveryReceiptController::class, 'store']);
    Route::middleware('permission:delivery_receipt_generation,update')->put('/delivery-receipts/{id}', [DeliveryReceiptController::class, 'update']);
    Route::middleware('permission:delivery_receipt_generation,archive')->delete('/delivery-receipts/{id}', [DeliveryReceiptController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: ISSUANCE (issuance)
    |--------------------------------------------------------------------------
    */
    Route::get('/issuances/archived', [IssuanceController::class, 'archived']);
    Route::get('/issuances', [IssuanceController::class, 'index']);
    Route::middleware('permission:inventory_issuance,read')
         ->get('/issuances/{id}/quote', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'quote']);
    Route::middleware('permission:inventory_issuance,read')
         ->get('/issuances/{id}/pick-plan', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'pickPlan']);
    // Gated on 'create', NOT 'read': confirming a quote reserves stock and moves
    // the order Draft -> Pending — a write with side effects, part of the counter
    // operator's build/confirm/pick flow (warehouse_personnel, branch_manager,
    // admin). Gating on 'read' would let a read-only auditor reserve stock.
    Route::middleware('permission:inventory_issuance,create')
         ->post('/issuances/{id}/confirm-quote', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'confirmQuote']);
    Route::middleware('permission:inventory_issuance,create')->group(function () {
        Route::post('/issuances/{id}/lines/set',        [\App\Http\Controllers\Api\IssuanceOrderController::class, 'addSetLine']);
        Route::post('/issuances/{id}/lines/custom',     [\App\Http\Controllers\Api\IssuanceOrderController::class, 'addCustomLine']);
        Route::put('/issuances/{id}/lines/{lineId}',    [\App\Http\Controllers\Api\IssuanceOrderController::class, 'updateLine']);
        Route::delete('/issuances/{id}/lines/{lineId}', [\App\Http\Controllers\Api\IssuanceOrderController::class, 'removeLine']);
    });
    Route::middleware('permission:inventory_issuance,create')
         ->post('/issuances/{id}/cancel', [IssuanceController::class, 'cancel']);
    Route::get('/issuances/{id}', [IssuanceController::class, 'show']);
    Route::middleware('permission:inventory_issuance,create')->post('/issuances', [IssuanceController::class, 'store']);
    Route::middleware('permission:inventory_issuance,update')->put('/issuances/{id}', [IssuanceController::class, 'update']);
    Route::middleware('permission:inventory_issuance,archive')->delete('/issuances/{id}', [IssuanceController::class, 'destroy']);
    Route::middleware('permission:inventory_issuance,archive')->post('/issuances/{id}/restore', [IssuanceController::class, 'restore']);
    Route::middleware('permission:inventory_issuance,archive')->delete('/issuances/{id}/force', [IssuanceController::class, 'forceDestroy']);

    /*
    |--------------------------------------------------------------------------
    | ERD: TRANSFER (transfer)
    |--------------------------------------------------------------------------
    */
    Route::get('/transfers/archived', [TransferController::class, 'archived']);
    Route::get('/transfers', [TransferController::class, 'index']);
    Route::get('/transfers/{id}', [TransferController::class, 'show']);
    Route::middleware('permission:inventory_transfers,create')->post('/transfers', [TransferController::class, 'store']);
    Route::get('/transfers/{id}/pick-list', [TransferController::class, 'pickList']);
    Route::middleware('permission:inventory_transfers,read')
        ->get('/transfers/{id}/source-availability', [TransferController::class, 'sourceAvailability']);
    Route::middleware('permission:inventory_transfers,approve')->group(function () {
        Route::post('/transfers/{id}/verify', [TransferController::class, 'verify']);
        Route::patch('/transfers/{id}/reject', [TransferController::class, 'reject']);
    });
    // Requester (or admin/IA) edits/cancels a still-Pending request; ownership enforced in-method.
    Route::middleware('permission:inventory_transfers,update')->group(function () {
        Route::patch('/transfers/{id}/request', [TransferController::class, 'updateRequest']);
        Route::patch('/transfers/{id}/cancel', [TransferController::class, 'cancel']);
    });
    Route::get('/transfers/{id}/scan-status', [TransferController::class, 'scanStatus']);
    Route::get('/transfers/{id}/receive-form', [TransferController::class, 'receiveForm']);
    Route::get('/transfers/{id}/receipt', [TransferController::class, 'receipt']);
    Route::middleware('permission:inventory_transfers,create')->group(function () {
        Route::post('/transfers/{id}/scan', [TransferController::class, 'scan']);
        Route::delete('/transfers/{id}/scan/{scanId}', [TransferController::class, 'removeScan']);
        Route::post('/transfers/{id}/confirm-dispatch', [TransferController::class, 'confirmDispatch']);
    });
    Route::middleware('permission:inventory_transfers,update')->group(function () {
        Route::post('/transfers/{id}/receive-scan', [TransferController::class, 'receiveScan']);
        Route::delete('/transfers/{id}/receive-scan/{scanId}', [TransferController::class, 'removeReceiveScan']);
        Route::post('/transfers/{id}/receive-confirm', [TransferController::class, 'receiveStore']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::put('/transfers/{id}', [TransferController::class, 'update']);
        Route::post('/transfers/{id}/receive', [TransferController::class, 'receive']);
        Route::post('/transfers/{id}/tracking', [TransferController::class, 'addTracking']);
        Route::delete('/transfers/{id}', [TransferController::class, 'destroy']);
        Route::post('/transfers/{id}/restore', [TransferController::class, 'restore']);
        Route::delete('/transfers/{id}/force', [TransferController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: ADJUSTMENT (adjustment)
    |--------------------------------------------------------------------------
    */
    Route::get('/adjustments/archived', [AdjustmentController::class, 'archived']);
    Route::get('/adjustments', [AdjustmentController::class, 'index']);

    // Barcode Adjustment Module — specific routes BEFORE the {id} wildcard
    Route::middleware('permission:inventory_adjustments,read')->group(function () {
        Route::get('/adjustments/receiving-history/{po_id}',
            [BarcodeAdjustmentController::class, 'getReceivingHistory']);
        Route::get('/adjustments/lookup-barcode',
            [BarcodeAdjustmentController::class, 'lookupBarcode']);
        Route::get('/adjustments/correction-history',
            [BarcodeAdjustmentController::class, 'getAdjustmentHistory']);
        Route::get('/adjustments/corrections/{correction_id}',
            [BarcodeAdjustmentController::class, 'getScanCorrectionDetail']);
    });
    Route::middleware('permission:inventory_adjustments,create')->group(function () {
        Route::post('/adjustments/preview',
            [BarcodeAdjustmentController::class, 'previewCorrection']);
        Route::post('/adjustments/submit',
            [BarcodeAdjustmentController::class, 'submitCorrection']);
    });

    Route::get('/adjustments/{id}', [AdjustmentController::class, 'show']);
    Route::middleware('permission:inventory_adjustments,create')->post('/adjustments', [AdjustmentController::class, 'store']);
    Route::middleware('permission:inventory_adjustments,update')->put('/adjustments/{id}', [AdjustmentController::class, 'update']);
    Route::middleware('permission:inventory_adjustments,archive')->delete('/adjustments/{id}', [AdjustmentController::class, 'destroy']);
    Route::middleware('permission:inventory_adjustments,archive')->post('/adjustments/{id}/restore', [AdjustmentController::class, 'restore']);
    Route::middleware('permission:inventory_adjustments,archive')->delete('/adjustments/{id}/force', [AdjustmentController::class, 'forceDestroy']);

    /*
    |--------------------------------------------------------------------------
    | COMPLIANCE REVIEW
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:exception_detection,read')->group(function () {
        Route::get('/compliance/logs',
            [BarcodeAdjustmentController::class, 'getComplianceLogs']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: PROFIT_LOSS (profit_loss)
    |--------------------------------------------------------------------------
    */
    Route::get('/profit-loss', [ProfitLossController::class, 'index']);
    Route::get('/profit-loss/{id}', [ProfitLossController::class, 'show']);
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::post('/profit-loss', [ProfitLossController::class, 'store']);
        Route::put('/profit-loss/{id}', [ProfitLossController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/profit-loss/{id}', [ProfitLossController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: AUDIT_LOG (audit_log)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:audit_trail_viewing,read')->group(function () {
        Route::get('/audit-log', function (Illuminate\Http\Request $request) {
            $query = \App\Models\AuditLog::with('user')->orderBy('created_at', 'desc');
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->has('action')) {
                $query->where('action', $request->action);
            }
            return $query->paginate($request->input('per_page', 50));
        });

        Route::get('/audit-trail', [AuditTrailController::class, 'legacyIndex']);

        Route::get('/audit-trail/user/{userId}', function ($userId) {
            return \App\Models\AuditTrail::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->paginate(50);
        });
    });

    /*
    |--------------------------------------------------------------------------
    | AUDIT TRAIL v2 (structured, filterable, with labels)
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:audit_trail_viewing,read')->group(function () {
        Route::get('/audit-trail/v2', [AuditTrailController::class, 'index']);
        Route::get('/audit-trail/v2/filter-options', [AuditTrailController::class, 'filterOptions']);
        Route::get('/audit-trail/v2/{audit_id}', [AuditTrailController::class, 'show']);
        Route::get('/login-sessions', [\App\Http\Controllers\Api\LoginSessionController::class, 'index']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: ACTIVITY_LOG (activity_log)
    |--------------------------------------------------------------------------
    */
    Route::get('/activity-log', [ActivityLogController::class, 'index']);
    Route::get('/activity-log/{id}', [ActivityLogController::class, 'show']);
    Route::post('/activity-log', [ActivityLogController::class, 'store']);
    Route::middleware('permission:audit_trail_viewing,archive')->delete('/activity-log/{id}', [ActivityLogController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | INVENTORY (legacy scan/adjust endpoints via InventoryController)
    |--------------------------------------------------------------------------
    */
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/{inventory}/history', [InventoryController::class, 'stockHistory']);
    Route::get('/inventory/{inventory}/batches', [InventoryController::class, 'batches']);
    Route::get('/inventory-batches', [InventoryBatchController::class, 'index']);
    Route::patch('/inventory-batches/{batch}', [InventoryBatchController::class, 'update']);
    Route::get('/po-inventory-groups', [\App\Http\Controllers\Api\PoInventoryController::class, 'index']);
    Route::get('/po-inventory-groups/{po}', [\App\Http\Controllers\Api\PoInventoryController::class, 'show']);
    Route::patch('/po-inventory-groups/{po}', [\App\Http\Controllers\Api\PoInventoryController::class, 'updateLocation']);
    Route::get('/inventory/{inventory}', [InventoryController::class, 'show']);
    Route::get('/inventory/location/{location}', [InventoryController::class, 'byLocation']);
    Route::get('/inventory/product/{product}', [InventoryController::class, 'byItem']);

    /*
    |--------------------------------------------------------------------------
    | INVENTORY OPERATION (barcode scan)
    | DEMO SLICE: gated by the DB permission system (feature barcode_inventory_processing,
    | action create) instead of a hardcoded role list. Default grants SA/BM/WP (same as
    | before); admins can now also grant IA (or anyone) via the Role Permissions editor
    | or a per-user override, and it takes effect here with no code change.
    |--------------------------------------------------------------------------
    */
    Route::middleware('permission:barcode_inventory_processing,create')->group(function () {
        Route::post('/inventory/scan-barcode', [InventoryController::class, 'scanBarcode']);
        Route::post('/inventory/scan-transaction', [InventoryScanTransactionController::class, 'store']);
        Route::post('/barcode/scan', [BarcodeScanController::class, 'scanLookup']);
        Route::get('/barcode-scans', [BarcodeScanController::class, 'index']);
        Route::post('/barcode-scans', [BarcodeScanController::class, 'store']);
    });

    /*
    |--------------------------------------------------------------------------
    | REPORTS & ANALYTICS
    |--------------------------------------------------------------------------
    */
    Route::prefix('reports')->group(function () {
        Route::get('/inventory-valuation', function () {
            $sub = '(SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory WHERE inventory.product_id = products.product_id)';
            $products = \App\Models\Product::with(['category', 'applianceDetail.brand'])
                ->selectRaw("products.*, {$sub} AS total_qty")
                ->get()
                ->map(fn($p) => [
                    'product_id'   => $p->product_id,
                    'product_name' => $p->product_name,
                    'category'     => $p->category?->category_name,
                    'brand'        => $p->applianceDetail?->brand?->brand_name,
                    'quantity'     => (int) ($p->total_qty ?? 0),
                    'cost_price'   => $p->cost_price,
                    'total_value'  => ($p->total_qty ?? 0) * $p->cost_price,
                ]);
            return response()->json(['success' => true, 'data' => $products]);
        });
        Route::get('/sales-summary', function (Illuminate\Http\Request $request) {
            $query = \App\Models\Sale::with('customer');
            if ($request->has('date_from')) {
                $query->where('sale_date', '>=', $request->date_from);
            }
            if ($request->has('date_to')) {
                $query->where('sale_date', '<=', $request->date_to);
            }
            return response()->json(['success' => true, 'data' => $query->paginate(50)]);
        });
        Route::get('/movement-history', function () {
            return response()->json(['success' => true, 'data' => \App\Models\Transfer::with(['fromLocation', 'toLocation'])->orderBy('created_at', 'desc')->paginate(50)]);
        });
        Route::get('/low-stock-report', function () {
            $sub = '(SELECT COALESCE(SUM(quantity_on_hand),0) FROM inventory WHERE inventory.product_id = products.product_id)';
            $products = \App\Models\Product::with(['category', 'applianceDetail.brand'])
                ->selectRaw("products.*, {$sub} AS total_qty")
                ->whereRaw("{$sub} <= recommended_stocks")
                ->get();
            return response()->json(['success' => true, 'data' => $products]);
        });
        Route::get('/adjustments-summary', function (Illuminate\Http\Request $request) {
            $query = \App\Models\Adjustment::with(['location', 'createdBy']);
            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            return response()->json(['success' => true, 'data' => $query->orderBy('created_at', 'desc')->paginate(50)]);
        });
        Route::get('/receivings-summary', function (Illuminate\Http\Request $request) {
            $query = \App\Models\Receiving::with(['purchaseOrder.supplier', 'location', 'receivedBy']);
            if ($request->has('location_id')) {
                $query->where('location_id', $request->location_id);
            }
            return response()->json(['success' => true, 'data' => $query->orderBy('created_at', 'desc')->paginate(50)]);
        });
        Route::get('/profit-loss-summary', function (Illuminate\Http\Request $request) {
            $query = \App\Models\ProfitLoss::with(['product', 'model', 'recordedBy']);
            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }
            return response()->json([
                'success' => true,
                'data'    => $query->orderBy('created_at', 'desc')->paginate(50),
                'summary' => ['total_loss' => \App\Models\ProfitLoss::sum('total_loss_amount')],
            ]);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Download endpoints — token passed as ?token= query param (browser download)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth.query.token', 'permission:delivery_receipt_generation,read'])
     ->get('/issuance-scan/delivery-receipt/{issuance_id}/pdf',
         [IssuanceScanController::class, 'downloadDeliveryReceiptPdf']);

Route::middleware(['auth.query.token', 'permission:inventory_transfers,read'])
     ->get('/transfers/{id}/receipt/pdf', [TransferController::class, 'receiptPdf']);

Route::middleware(['auth.query.token', 'permission:inventory_adjustments,read'])
     ->get('/adjustments/{id}/pdf', [AdjustmentController::class, 'pdf']);

// sendBeacon (tab-close/refresh) can't set an Authorization header, so the
// issuance wizard's abandon-revert beacon authenticates via ?token= like the
// download routes. Same controller method as the Bearer POST route above.
Route::middleware(['auth.query.token', 'permission:inventory_issuance,create'])
     ->post('/issuance-scan/{issuance_id}/revert-to-draft-beacon',
         [IssuanceScanController::class, 'revertToDraft']);

// Goods Received Note downloads. These use standard Bearer auth (auth:sanctum),
// NOT a ?token= query param — the frontend fetches the file with the Authorization
// header and hands the browser a blob, so the token never appears in the URL
// (avoids the "insecure" credential-in-URL warning over plain HTTP).
Route::middleware(['auth:sanctum', 'permission:delivery_receiving,read'])
     ->get('/receivings/{id}/receipt/pdf', [ReceivingController::class, 'receiptPdf']);

Route::middleware(['auth:sanctum', 'permission:delivery_receiving,read'])
     ->get('/receivings/{id}/receipt/csv', [ReceivingController::class, 'receiptCsv']);

// Consolidated Goods Received Note for a whole PO (all sessions/warehouses)
Route::middleware(['auth:sanctum', 'permission:delivery_receiving,read'])
     ->get('/purchase-orders/{id}/receiving-receipt/pdf', [ReceivingController::class, 'poReceiptPdf']);
Route::middleware(['auth:sanctum', 'permission:delivery_receiving,read'])
     ->get('/purchase-orders/{id}/receiving-receipt/csv', [ReceivingController::class, 'poReceiptCsv']);
