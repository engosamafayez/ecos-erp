<?php

declare(strict_types=1);

use App\Http\Controllers\Infrastructure\HealthController;
use Illuminate\Support\Facades\Route;
use Modules\IAM\Presentation\Http\Controllers\AuthController;
use Modules\Inventory\Products\Presentation\Http\Controllers\ProductController;
use Modules\Inventory\StockLedger\Presentation\Http\Controllers\StockMovementController;
use Modules\Commerce\Channels\Presentation\Http\Controllers\ChannelController;
use Modules\Commerce\OrderImport\Presentation\Http\Controllers\OrderImportController;
use Modules\Commerce\Orders\Presentation\Http\Controllers\OrderController;
use Modules\Commerce\Connectors\Presentation\Http\Controllers\ConnectorController;
use Modules\Commerce\ProductImport\Presentation\Http\Controllers\ProductImportController;
use Modules\Commerce\ProductMappings\Presentation\Http\Controllers\ProductMappingController;
use Modules\Sales\Customers\Presentation\Http\Controllers\CustomerController;
use Modules\MasterData\Categories\Presentation\Http\Controllers\CategoryController;
use Modules\MasterData\Units\Presentation\Http\Controllers\UnitController;
use Modules\MasterData\Warehouses\Presentation\Http\Controllers\WarehouseController;
use Modules\Organization\Branches\Presentation\Http\Controllers\BranchController;
use Modules\Organization\Companies\Presentation\Http\Controllers\CompanyController;
use Modules\Commerce\Fulfillments\Presentation\Http\Controllers\FulfillmentController;
use Modules\Commerce\StockSync\Presentation\Http\Controllers\StockSyncController;
use Modules\Purchasing\GoodsReceipts\Presentation\Http\Controllers\GoodsReceiptController;
use Modules\Purchasing\PurchaseOrders\Presentation\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Suppliers\Presentation\Http\Controllers\SupplierController;
use Modules\Purchasing\Suppliers\Presentation\Http\Controllers\SupplierAnalyticsController;
use Modules\Manufacturing\BillsOfMaterials\Presentation\Http\Controllers\BomController;
use Modules\Commerce\Synchronization\Presentation\Http\Controllers\SynchronizationController;
use Modules\Commerce\Synchronization\Presentation\Http\Controllers\WooCommerceWebhookController;
use Modules\Inventory\ReceiptLayers\Presentation\Http\Controllers\InventoryLayerController;
use Modules\Inventory\CountSessions\Presentation\Http\Controllers\InventoryCountController;
use Modules\Inventory\InventoryControl\Presentation\Http\Controllers\InventoryDashboardController;
use Modules\Inventory\InventoryControl\Presentation\Http\Controllers\AbcClassificationController;
use Modules\Inventory\InventoryControl\Presentation\Http\Controllers\VarianceAnalyticsController;
use Modules\Inventory\InventoryControl\Presentation\Http\Controllers\WarehousePerformanceController;
use Modules\Inventory\InventoryControl\Presentation\Http\Controllers\CycleCountPlanController;
use Modules\Core\UserPreferences\Presentation\Http\Controllers\UserPreferenceController;
use Modules\Operations\DemandAnalysis\Presentation\Http\Controllers\DemandAnalysisController;
use Modules\POS\Presentation\Http\Controllers\SessionController as PosSessionController;
use Modules\POS\Presentation\Http\Controllers\ShiftController as PosShiftController;
use Modules\POS\Presentation\Http\Controllers\CartController as PosCartController;
use Modules\POS\Presentation\Http\Controllers\CartLineController as PosCartLineController;
use Modules\POS\Presentation\Http\Controllers\SaleController as PosSaleController;
use Modules\POS\Presentation\Http\Controllers\ReturnController as PosReturnController;
use Modules\POS\Presentation\Http\Controllers\ExchangeController as PosExchangeController;
use Modules\POS\Presentation\Http\Controllers\ReceiptController as PosReceiptController;

/*
|--------------------------------------------------------------------------
| Infrastructure — Health check (public, no auth)
|
| Returns real DB + Redis + queue connectivity status plus build metadata.
| Used by docker-compose healthcheck and monitoring systems.
|--------------------------------------------------------------------------
*/
Route::get('/health', HealthController::class);

/*
|--------------------------------------------------------------------------
| IAM — Authentication routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Organization — Companies (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('branches', BranchController::class);
});

/*
|--------------------------------------------------------------------------
| Master Data — Warehouses, Categories, Units (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('warehouses', WarehouseController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('units', UnitController::class);
});

/*
|--------------------------------------------------------------------------
| Inventory — Products, Stock Ledger (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('products', ProductController::class);
    Route::get('products/{product}/cost-history', [InventoryLayerController::class, 'costHistory']);
    Route::get('stock-movements', [StockMovementController::class, 'index']);
    Route::get('stock-movements/{stockMovement}', [StockMovementController::class, 'show']);
    Route::get('inventory/layers', [InventoryLayerController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Inventory — Count Sessions (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('inventory-counts', InventoryCountController::class);
    Route::post('inventory-counts/{inventoryCount}/start',    [InventoryCountController::class, 'start']);
    Route::post('inventory-counts/{inventoryCount}/complete', [InventoryCountController::class, 'complete']);
    Route::post('inventory-counts/{inventoryCount}/approve',  [InventoryCountController::class, 'approve']);
    Route::post('inventory-counts/{inventoryCount}/cancel',   [InventoryCountController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| Sales — Customers (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('customers', CustomerController::class);
});

/*
|--------------------------------------------------------------------------
| Commerce — Channels (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('channels', ChannelController::class);
    Route::post('channels/{channel}/test-connection', [ConnectorController::class, 'testConnection']);
    Route::post('channels/{channel}/import-products', [ProductImportController::class, 'importProducts']);
    Route::post('channels/{channel}/import-orders', [OrderImportController::class, 'importOrders']);
    Route::apiResource('product-mappings', ProductMappingController::class);
    Route::apiResource('orders', OrderController::class);
    Route::post('orders/{order}/prepare', [OrderController::class, 'prepare']);
    Route::apiResource('fulfillments', FulfillmentController::class);
    Route::post('fulfillments/{fulfillment}/fulfill', [FulfillmentController::class, 'fulfill']);
    Route::post('fulfillments/{fulfillment}/cancel', [FulfillmentController::class, 'cancel']);
    Route::get('stock-sync-logs', [StockSyncController::class, 'index']);
    Route::post('channels/{channel}/sync-stock', [StockSyncController::class, 'syncStock']);
});

/*
|--------------------------------------------------------------------------
| Purchasing — Suppliers (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('suppliers/{supplier}/analytics', [SupplierAnalyticsController::class, 'analytics']);
    Route::get('suppliers/{supplier}/inventory-breakdown', [SupplierAnalyticsController::class, 'inventoryBreakdown']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::apiResource('goods-receipts', GoodsReceiptController::class);
    Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post']);
});

/*
|--------------------------------------------------------------------------
| Manufacturing — Bills of Materials (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('boms', BomController::class);
});

/*
|--------------------------------------------------------------------------
| Commerce — Synchronization Logs (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('sync-logs', [SynchronizationController::class, 'index']);
    Route::post('sync-logs/{syncLog}/retry', [SynchronizationController::class, 'retry']);
});

/*
|--------------------------------------------------------------------------
| Inventory Control — Dashboard, ABC, Variance, Warehouse Performance (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('inventory/dashboard',             [InventoryDashboardController::class, 'index']);
    Route::get('inventory/abc-classifications',   [AbcClassificationController::class, 'index']);
    Route::post('inventory/abc-classifications/recalculate', [AbcClassificationController::class, 'recalculate']);
    Route::get('inventory/variance-analytics',    [VarianceAnalyticsController::class, 'index']);
    Route::get('inventory/warehouse-performance', [WarehousePerformanceController::class, 'index']);
    Route::get('inventory/cycle-count-plans',     [CycleCountPlanController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Operations — Demand Analysis (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('operations/demand-analysis', [DemandAnalysisController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Core — User Preferences (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('me')->group(function (): void {
    // Full category replacement and retrieval
    Route::get('preferences', [UserPreferenceController::class, 'index']);
    Route::delete('preferences', [UserPreferenceController::class, 'resetAll']);

    Route::get('preferences/{category}', [UserPreferenceController::class, 'show'])
        ->where('category', '[a-z][a-z0-9._-]{0,149}');

    Route::put('preferences/{category}', [UserPreferenceController::class, 'upsert'])
        ->where('category', '[a-z][a-z0-9._-]{0,149}');

    Route::delete('preferences/{category}', [UserPreferenceController::class, 'resetCategory'])
        ->where('category', '[a-z][a-z0-9._-]{0,149}');
});

/*
|--------------------------------------------------------------------------
| POS — Point of Sale (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('pos')->group(function (): void {
    // Sessions
    Route::post('sessions',           [PosSessionController::class, 'store']);
    Route::get('sessions/{session}',  [PosSessionController::class, 'show']);
    Route::delete('sessions/{session}', [PosSessionController::class, 'destroy']);

    // Shifts
    Route::post('shifts',                    [PosShiftController::class, 'store']);
    Route::get('shifts/{shift}',             [PosShiftController::class, 'show']);
    Route::delete('shifts/{shift}',          [PosShiftController::class, 'destroy']);
    Route::put('shifts/{shift}/approve',     [PosShiftController::class, 'approve']);
    Route::put('shifts/{shift}/reject',      [PosShiftController::class, 'reject']);

    // Carts
    Route::post('carts',             [PosCartController::class, 'store']);
    Route::get('carts/{cart}',       [PosCartController::class, 'show']);
    Route::post('carts/{cart}/hold',         [PosCartController::class, 'hold']);
    Route::delete('carts/{cart}/hold',       [PosCartController::class, 'resume']);
    Route::put('carts/{cart}/customer',      [PosCartController::class, 'setCustomer']);
    Route::delete('carts/{cart}',            [PosCartController::class, 'destroy']);

    // Cart lines
    Route::post('carts/{cart}/lines',              [PosCartLineController::class, 'store']);
    Route::delete('carts/{cart}/lines/{line}',     [PosCartLineController::class, 'destroy']);

    // Sales
    Route::post('sales',          [PosSaleController::class, 'store']);
    Route::get('sales/{sale}',    [PosSaleController::class, 'show']);

    // Returns
    Route::post('returns', [PosReturnController::class, 'store']);

    // Exchanges
    Route::post('exchanges', [PosExchangeController::class, 'store']);

    // Receipts
    Route::get('receipts/{receipt}',              [PosReceiptController::class, 'show']);
    Route::post('receipts/{receipt}/reprint',     [PosReceiptController::class, 'reprint']);
    Route::delete('receipts/{receipt}',           [PosReceiptController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Webhooks — WooCommerce (public, no auth)
|--------------------------------------------------------------------------
*/
Route::post('webhooks/woocommerce/{channel}/orders',    [WooCommerceWebhookController::class, 'handleOrder']);
Route::post('webhooks/woocommerce/{channel}/products',  [WooCommerceWebhookController::class, 'handleProduct']);
Route::post('webhooks/woocommerce/{channel}/customers', [WooCommerceWebhookController::class, 'handleCustomer']);
