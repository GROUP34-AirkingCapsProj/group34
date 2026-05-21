<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\CategoryLookupController;
use App\Http\Controllers\Api\BrandLookupController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SerialController;
// ERD-aligned controllers
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\ModelLookupController;
use App\Http\Controllers\Api\SubcategoryLookupController;
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
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\WarrantyController;
use App\Http\Controllers\Api\IssuanceScanController;
use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\ArchiveSettingsController;

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
    Route::middleware(['role:admin', 'throttle:30,1'])->post('/password-reset/issue', [PasswordResetController::class, 'issue']);
    Route::middleware(['role:admin', 'throttle:60,1'])->get('/password-reset/active', [PasswordResetController::class, 'active']);
    Route::middleware(['role:admin', 'throttle:60,1'])->post('/password-reset/active/{passwordResetCode}/revoke', [PasswordResetController::class, 'revoke']);

    /*
    |--------------------------------------------------------------------------
    | USER MANAGEMENT (ADMIN ONLY)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::put('/users/{user}/change-password', [UserController::class, 'changePassword']);
        Route::put('/users/{user}/status', [UserController::class, 'updateStatus']);
        Route::get('/users/by-location/{locationId}', [UserController::class, 'byLocation']);
        Route::get('/users/archived', [UserController::class, 'archived']);
        Route::post('/users/{id}/restore', [UserController::class, 'restore']);
        Route::delete('/users/{id}/force', [UserController::class, 'forceDestroy']);
        Route::apiResource('users', UserController::class);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: ROLES (roles)
    |--------------------------------------------------------------------------
    */
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/{id}', [RoleController::class, 'show']);
    Route::middleware('role:admin')->group(function () {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{id}', [RoleController::class, 'update']);
        Route::delete('/roles/{id}', [RoleController::class, 'destroy']);
    });

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
    Route::prefix('archive')->middleware('role:admin,branch_manager')->group(function () {
        Route::get('/summary',                    [ArchiveController::class, 'summary']);
        Route::get('/{module}',                   [ArchiveController::class, 'index']);
        Route::post('/{module}/{id}/restore',     [ArchiveController::class, 'restore']);
        Route::middleware('role:admin')->delete('/{module}/{id}/force',   [ArchiveController::class, 'forceDestroy']);
        Route::middleware('role:admin')->post('/{module}/restore-all',    [ArchiveController::class, 'restoreAll']);
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
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::post('/categories', [CategoryLookupController::class, 'store']);
        Route::put('/categories/{category}', [CategoryLookupController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryLookupController::class, 'destroy']);
        Route::post('/categories/{category}/restore', [CategoryLookupController::class, 'restore']);
        Route::delete('/categories/{category}/force', [CategoryLookupController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: SUBCATEGORY (subcategory_lookup)
    |--------------------------------------------------------------------------
    */
    Route::get('/subcategories', [SubcategoryLookupController::class, 'index']);
    Route::get('/subcategories/{id}', [SubcategoryLookupController::class, 'show']);
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::post('/subcategories', [SubcategoryLookupController::class, 'store']);
        Route::put('/subcategories/{id}', [SubcategoryLookupController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/subcategories/{id}', [SubcategoryLookupController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: BRAND (brand_lookup)
    |--------------------------------------------------------------------------
    */
    Route::get('/brands/archived', [BrandLookupController::class, 'archived']);
    Route::get('/brands', [BrandLookupController::class, 'index']);
    Route::get('/brands/{brand}', [BrandLookupController::class, 'show']);
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::post('/brands', [BrandLookupController::class, 'store']);
        Route::put('/brands/{brand}', [BrandLookupController::class, 'update']);
        Route::delete('/brands/{brand}', [BrandLookupController::class, 'destroy']);
        Route::post('/brands/{brand}/restore', [BrandLookupController::class, 'restore']);
        Route::delete('/brands/{brand}/force', [BrandLookupController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: MODEL LOOKUP (model_lookup)
    |--------------------------------------------------------------------------
    */
    Route::get('/models/archived', [ModelLookupController::class, 'archived']);
    Route::get('/models', [ModelLookupController::class, 'index']);
    Route::get('/models/{id}', [ModelLookupController::class, 'show']);
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::post('/models', [ModelLookupController::class, 'store']);
        Route::put('/models/{id}', [ModelLookupController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/models/{id}', [ModelLookupController::class, 'destroy']);
        Route::post('/models/{id}/restore', [ModelLookupController::class, 'restore']);
        Route::delete('/models/{id}/force', [ModelLookupController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: UNIT LOOKUP (unit_lookup)
    |--------------------------------------------------------------------------
    */
    Route::get('/units/archived', [UnitLookupController::class, 'archived']);
    Route::get('/units', [UnitLookupController::class, 'index']);
    Route::get('/units/{id}', [UnitLookupController::class, 'show']);
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::post('/units', [UnitLookupController::class, 'store']);
        Route::put('/units/{id}', [UnitLookupController::class, 'update']);
        Route::delete('/units/{id}', [UnitLookupController::class, 'destroy']);
        Route::post('/units/{id}/restore', [UnitLookupController::class, 'restore']);
        Route::delete('/units/{id}/force', [UnitLookupController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: LOCATION (location) — unified physical places
    |--------------------------------------------------------------------------
    */
    Route::get('/locations/archived', [LocationController::class, 'archived']);
    Route::get('/locations', [LocationController::class, 'index']);
    Route::get('/locations/{location}', [LocationController::class, 'show']);
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::post('/locations', [LocationController::class, 'store']);
        Route::put('/locations/{location}', [LocationController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/locations/{location}', [LocationController::class, 'destroy']);
        Route::post('/locations/{id}/restore', [LocationController::class, 'restore']);
        Route::delete('/locations/{id}/force', [LocationController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | BACKWARD COMPAT: /branches & /warehouses → proxy to /locations
    |--------------------------------------------------------------------------
    */
    Route::get('/branches', function (Illuminate\Http\Request $request) {
        $request->merge(['location_type' => 'branch_office']);
        return app()->call([LocationController::class, 'index']);
    });
    Route::get('/warehouses', function (Illuminate\Http\Request $request) {
        $request->merge(['location_type' => 'warehouse']);
        return app()->call([LocationController::class, 'index']);
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
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/{id}/restore', [ProductController::class, 'restore']);
        Route::delete('/products/{id}/force', [ProductController::class, 'forceDestroy']);
    });

    // Legacy alias: /items → products
    Route::get('/items', [ProductController::class, 'index']);
    Route::get('/items/{product}', [ProductController::class, 'show']);
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::post('/items', [ProductController::class, 'store']);
        Route::put('/items/{product}', [ProductController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/items/{product}', [ProductController::class, 'destroy']);
    });

    Route::get('/consumable-supply/catalog', [PendingProductController::class, 'catalog']);
    Route::get('/supply-types', [PendingProductController::class, 'supplyTypesIndex']);
    Route::middleware('role:admin,branch_manager,warehouse_personnel,inventory_analyst')->group(function () {
        Route::post('/pending-products', [PendingProductController::class, 'store']);
    });
    // List: procurement/audit visibility; approve: same as create submitters who run Item Master workflow
    Route::middleware('role:admin,branch_manager,inventory_analyst,auditor')->group(function () {
        Route::get('/pending-products', [PendingProductController::class, 'index']);
    });
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::post('/pending-products/{pendingProduct}/approve', [PendingProductController::class, 'approve']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: ITEM_SERIAL (item_serial)
    |--------------------------------------------------------------------------
    */
    Route::get('/serials', [SerialController::class, 'index']);
    Route::get('/serials/{serial}', [SerialController::class, 'show']);
    Route::post('/serials/scan', [SerialController::class, 'scan']);
    Route::middleware('role:admin,branch_manager,warehouse_personnel')->group(function () {
        Route::post('/serials', [SerialController::class, 'store']);
        Route::put('/serials/{serial}', [SerialController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/serials/{serial}', [SerialController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: SUPPLIER (supplier)
    |--------------------------------------------------------------------------
    */
    Route::get('/suppliers/archived', [SupplierController::class, 'archived']);
    Route::get('/suppliers', [SupplierController::class, 'index']);
    Route::get('/suppliers/{id}', [SupplierController::class, 'show']);
    Route::get('/suppliers/{id}/products', [SupplierController::class, 'products']);
    Route::middleware('role:admin,inventory_analyst,branch_manager')->group(function () {
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::put('/suppliers/{id}', [SupplierController::class, 'update']);
        Route::post('/suppliers/{id}/products', [SupplierController::class, 'addProduct']);
        Route::put('/suppliers/{id}/products/{supplierProdId}', [SupplierController::class, 'updateProduct']);
        Route::delete('/suppliers/{id}/products/{productId}', [SupplierController::class, 'removeProduct']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy']);
        Route::post('/suppliers/{id}/restore', [SupplierController::class, 'restore']);
        Route::delete('/suppliers/{id}/force', [SupplierController::class, 'forceDestroy']);
    });

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
    Route::get('/warranties/export',             [WarrantyController::class, 'export']);
    Route::get('/warranties/lookup/{serial}',    [WarrantyController::class, 'lookupBySerial']);
    Route::get('/warranties',                    [WarrantyController::class, 'index']);
    Route::get('/warranties/{id}',               [WarrantyController::class, 'show']);
    Route::put('/warranties/{id}/claim',         [WarrantyController::class, 'markClaimed']);

    /*
    |--------------------------------------------------------------------------
    | Issuance Scan (barcode-driven issuance flow)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin,branch_manager,warehouse_personnel')
         ->prefix('issuance-scan')
         ->group(function () {
             Route::post('/init',
                 [IssuanceScanController::class, 'initSession']);
             Route::post('/scan',
                 [IssuanceScanController::class, 'scanItem']);
             Route::delete('/item/{detail_id}',
                 [IssuanceScanController::class, 'removeItem']);
             Route::post('/complete/{issuance_id}',
                 [IssuanceScanController::class, 'completeIssuance']);
             Route::get('/session/{issuance_id}',
                 [IssuanceScanController::class, 'getSessionStatus']);
         });

    /*
    |--------------------------------------------------------------------------
    | ERD: PURCHASE_ORDER (purchase_order)
    |--------------------------------------------------------------------------
    */
    Route::get('/purchase-orders/archived', [PurchaseOrderController::class, 'archived']);
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    // Specific routes before {id} wildcard
    Route::middleware('role:admin,inventory_analyst,branch_manager')->group(function () {
        Route::get('/purchase-orders/export', [PurchaseOrderController::class, 'export']);
        Route::get('/purchase-orders/products-by-supplier/{supplier_id}', [PurchaseOrderController::class, 'productsBySupplier']);
    });
    Route::get('/purchase-orders/{id}', [PurchaseOrderController::class, 'show']);
    Route::middleware('role:admin,inventory_analyst,branch_manager')->group(function () {
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    });
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::put('/purchase-orders/{id}', [PurchaseOrderController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/purchase-orders/{id}', [PurchaseOrderController::class, 'destroy']);
        Route::post('/purchase-orders/{id}/restore', [PurchaseOrderController::class, 'restore']);
        Route::delete('/purchase-orders/{id}/force', [PurchaseOrderController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | BUDGET ALLOCATIONS
    | Read: admin + inventory_analyst (IA needs to see budget on PR create form)
    | Write: admin only
    |--------------------------------------------------------------------------
    */
    Route::get('/budget-allocations/archived', [BudgetAllocationController::class, 'archived']);
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::get('/budget-allocations', [BudgetAllocationController::class, 'index']);
        Route::get('/budget-allocations/{id}', [BudgetAllocationController::class, 'show']);
        Route::get('/budget-allocations/{id}/available', [BudgetAllocationController::class, 'available']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::post('/budget-allocations', [BudgetAllocationController::class, 'store']);
        Route::put('/budget-allocations/{id}', [BudgetAllocationController::class, 'update']);
        Route::delete('/budget-allocations/{id}', [BudgetAllocationController::class, 'destroy']);
        Route::post('/budget-allocations/{id}/restore', [BudgetAllocationController::class, 'restore']);
        Route::delete('/budget-allocations/{id}/force', [BudgetAllocationController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | PURCHASE REQUESTS (PR → PO workflow)
    |--------------------------------------------------------------------------
    */

    // Export and archive (before {id} wildcard)
    Route::get('/purchase-requests/archived', [PurchaseRequestController::class, 'archived']);
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::get('/purchase-requests/export', [PurchaseRequestController::class, 'export']);
    });

    // Any authenticated user can view a single PR
    Route::get('/purchase-requests/{id}', [PurchaseRequestController::class, 'show']);

    // Admin actions
    Route::middleware('role:admin')->group(function () {
        Route::get('/purchase-requests/pending/list', [PurchaseRequestController::class, 'pending']);
        Route::patch('/purchase-requests/{id}/approve', [PurchaseRequestController::class, 'approve']);
        Route::patch('/purchase-requests/{id}/reject', [PurchaseRequestController::class, 'reject']);
    });

    // IA actions
    Route::middleware('role:admin,inventory_analyst')->group(function () {
        Route::get('/purchase-requests', [PurchaseRequestController::class, 'index']);
        Route::post('/purchase-requests', [PurchaseRequestController::class, 'store']);
        Route::get('/purchase-requests/{id}/convert', [PurchaseRequestController::class, 'convert']);
        Route::post('/purchase-requests/{id}/convert', [PurchaseRequestController::class, 'convertStore']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::post('/purchase-requests/{id}/restore', [PurchaseRequestController::class, 'restore']);
        Route::delete('/purchase-requests/{id}/force', [PurchaseRequestController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: RECEIVING (RECEIVING)
    |--------------------------------------------------------------------------
    */
    Route::get('/receivings', [ReceivingController::class, 'index']);
    Route::get('/receivings/po-scan-status/{poId}', [ReceivingController::class, 'poScanStatus']);
    Route::get('/receivings/{id}', [ReceivingController::class, 'show']);
    Route::middleware('role:admin,warehouse_personnel,branch_manager')->group(function () {
        Route::post('/receivings', [ReceivingController::class, 'store']);
    });
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::put('/receivings/{id}', [ReceivingController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/receivings/{id}', [ReceivingController::class, 'destroy']);
    });

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
    Route::middleware('role:admin,branch_manager,inventory_analyst')->group(function () {
        Route::post('/sales', [SaleController::class, 'store']);
    });
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::put('/sales/{id}', [SaleController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/sales/{id}', [SaleController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: DELIVERY_RECEIPT (delivery_receipt)
    |--------------------------------------------------------------------------
    */
    Route::get('/delivery-receipts', [DeliveryReceiptController::class, 'index']);
    Route::get('/delivery-receipts/{id}', [DeliveryReceiptController::class, 'show']);
    Route::middleware('role:admin,branch_manager')->group(function () {
        Route::post('/delivery-receipts', [DeliveryReceiptController::class, 'store']);
        Route::put('/delivery-receipts/{id}', [DeliveryReceiptController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/delivery-receipts/{id}', [DeliveryReceiptController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: ISSUANCE (issuance)
    |--------------------------------------------------------------------------
    */
    Route::get('/issuances/archived', [IssuanceController::class, 'archived']);
    Route::get('/issuances', [IssuanceController::class, 'index']);
    Route::get('/issuances/{id}', [IssuanceController::class, 'show']);
    Route::middleware('role:admin,warehouse_personnel')->group(function () {
        Route::post('/issuances', [IssuanceController::class, 'store']);
        Route::put('/issuances/{id}', [IssuanceController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/issuances/{id}', [IssuanceController::class, 'destroy']);
        Route::post('/issuances/{id}/restore', [IssuanceController::class, 'restore']);
        Route::delete('/issuances/{id}/force', [IssuanceController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: TRANSFER (transfer)
    |--------------------------------------------------------------------------
    */
    Route::get('/transfers/archived', [TransferController::class, 'archived']);
    Route::get('/transfers', [TransferController::class, 'index']);
    Route::get('/transfers/{id}', [TransferController::class, 'show']);
    Route::middleware('role:admin,branch_manager,warehouse_personnel')->group(function () {
        Route::post('/transfers', [TransferController::class, 'store']);
        Route::put('/transfers/{id}', [TransferController::class, 'update']);
        Route::post('/transfers/{id}/receive', [TransferController::class, 'receive']);
        Route::post('/transfers/{id}/tracking', [TransferController::class, 'addTracking']);
    });
    Route::middleware('role:admin')->group(function () {
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
    Route::middleware('role:admin,warehouse_personnel,branch_manager')->group(function () {
        Route::get('/adjustments/receiving-history/{po_id}',
            [BarcodeAdjustmentController::class, 'getReceivingHistory']);
        Route::get('/adjustments/lookup-barcode',
            [BarcodeAdjustmentController::class, 'lookupBarcode']);
        Route::get('/adjustments/correction-history',
            [BarcodeAdjustmentController::class, 'getAdjustmentHistory']);
        Route::get('/adjustments/corrections/{correction_id}',
            [BarcodeAdjustmentController::class, 'getScanCorrectionDetail']);
        Route::post('/adjustments/preview',
            [BarcodeAdjustmentController::class, 'previewCorrection']);
        Route::post('/adjustments/submit',
            [BarcodeAdjustmentController::class, 'submitCorrection']);
    });

    Route::get('/adjustments/{id}', [AdjustmentController::class, 'show']);
    Route::middleware('role:admin,warehouse_personnel')->group(function () {
        Route::post('/adjustments', [AdjustmentController::class, 'store']);
        Route::put('/adjustments/{id}', [AdjustmentController::class, 'update']);
    });
    Route::middleware('role:admin')->group(function () {
        Route::delete('/adjustments/{id}', [AdjustmentController::class, 'destroy']);
        Route::post('/adjustments/{id}/restore', [AdjustmentController::class, 'restore']);
        Route::delete('/adjustments/{id}/force', [AdjustmentController::class, 'forceDestroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | COMPLIANCE REVIEW
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin,auditor,branch_manager')->group(function () {
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
    Route::middleware('role:admin,auditor')->group(function () {
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

        Route::get('/audit-trail', function (\Illuminate\Http\Request $request) {
            return \App\Models\AuditTrail::with('user')
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 50));
        });

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
    Route::middleware('role:admin,auditor,branch_manager')->group(function () {
        Route::get('/audit-trail/v2', [AuditTrailController::class, 'index']);
        Route::get('/audit-trail/v2/filter-options', [AuditTrailController::class, 'filterOptions']);
        Route::get('/audit-trail/v2/{audit_id}', [AuditTrailController::class, 'show']);
    });

    /*
    |--------------------------------------------------------------------------
    | ERD: ACTIVITY_LOG (activity_log)
    |--------------------------------------------------------------------------
    */
    Route::get('/activity-log', [ActivityLogController::class, 'index']);
    Route::get('/activity-log/{id}', [ActivityLogController::class, 'show']);
    Route::post('/activity-log', [ActivityLogController::class, 'store']);
    Route::middleware('role:admin')->group(function () {
        Route::delete('/activity-log/{id}', [ActivityLogController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | INVENTORY (legacy scan/adjust endpoints via InventoryController)
    |--------------------------------------------------------------------------
    */
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/{inventory}/history', [InventoryController::class, 'stockHistory']);
    Route::get('/inventory/{inventory}', [InventoryController::class, 'show']);
    Route::get('/inventory/location/{location}', [InventoryController::class, 'byLocation']);
    Route::get('/inventory/product/{product}', [InventoryController::class, 'byItem']);

    /*
    |--------------------------------------------------------------------------
    | INVENTORY OPERATION (barcode scan — admin, branch manager, warehouse only)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin,branch_manager,warehouse_personnel')->group(function () {
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
Route::middleware(['auth.query.token', 'role:admin,branch_manager,warehouse_personnel'])
     ->get('/issuance-scan/delivery-receipt/{issuance_id}/csv',
         [IssuanceScanController::class, 'downloadDeliveryReceipt']);
