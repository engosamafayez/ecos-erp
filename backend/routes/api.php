<?php

declare(strict_types=1);

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\CompanyContextController;
use App\Http\Controllers\Infrastructure\HealthController;
use App\Http\Controllers\MediaController;
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
use Modules\Organization\Brands\Presentation\Http\Controllers\BrandController;
use Modules\Organization\Branches\Presentation\Http\Controllers\BranchController;
use Modules\Organization\BusinessAccounts\Presentation\Http\Controllers\BusinessAccountController;
use Modules\Organization\Companies\Presentation\Http\Controllers\CompanyController;
use Modules\Organization\Teams\Presentation\Http\Controllers\TeamController;
use Modules\Commerce\Fulfillments\Presentation\Http\Controllers\FulfillmentController;
use Modules\Commerce\StockSync\Presentation\Http\Controllers\StockSyncController;
use Modules\Purchasing\GoodsReceipts\Presentation\Http\Controllers\GoodsReceiptController;
use Modules\Purchasing\PurchaseMaterials\Presentation\Http\Controllers\PurchaseMaterialController;
use Modules\Purchasing\PurchaseOrders\Presentation\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Suppliers\Presentation\Http\Controllers\SupplierController;
use Modules\Purchasing\Suppliers\Presentation\Http\Controllers\SupplierAnalyticsController;
use Modules\Purchasing\Suppliers\Presentation\Http\Controllers\SupplierDocumentController;
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
use Modules\Inventory\WasteInvestigations\Presentation\Http\Controllers\WasteInvestigationController;
use Modules\Inventory\WarehouseLiabilities\Presentation\Http\Controllers\WarehouseLiabilityController;
use Modules\Core\UserPreferences\Presentation\Http\Controllers\UserPreferenceController;
use Modules\Operations\DemandAnalysis\Presentation\Http\Controllers\DemandAnalysisController;
use Modules\Core\DemandAnalysis\Presentation\Http\Controllers\DemandAnalysisController as ProductDemandAnalysisController;
use Modules\POS\Presentation\Http\Controllers\SessionController as PosSessionController;
use Modules\POS\Presentation\Http\Controllers\ShiftController as PosShiftController;
use Modules\POS\Presentation\Http\Controllers\CartController as PosCartController;
use Modules\POS\Presentation\Http\Controllers\CartLineController as PosCartLineController;
use Modules\POS\Presentation\Http\Controllers\SaleController as PosSaleController;
use Modules\POS\Presentation\Http\Controllers\ReturnController as PosReturnController;
use Modules\POS\Presentation\Http\Controllers\ExchangeController as PosExchangeController;
use Modules\POS\Presentation\Http\Controllers\ReceiptController as PosReceiptController;
use Modules\POS\Presentation\Http\Controllers\TerminalController as PosTerminalController;
use Modules\CostManagement\Presentation\Http\Controllers\CostManagementDashboardController;
use Modules\CostManagement\Presentation\Http\Controllers\MaterialCostController;
use Modules\CostManagement\Presentation\Http\Controllers\PricingReviewController;
use Modules\Purchasing\SupplierReturns\Presentation\Http\Controllers\SupplierReturnController;
use Modules\Purchasing\SupplierInvoices\Presentation\Http\Controllers\SupplierInvoiceController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationWaveController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationDashboardController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparedPoolController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationStationController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationWorkerController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationAnalyticsController;
use Modules\Operations\Loading\Presentation\Http\Controllers\LoadingDashboardController;
use Modules\Operations\Loading\Presentation\Http\Controllers\LoadingSessionController;
use Modules\Operations\Loading\Presentation\Http\Controllers\VehicleAssignmentController;
use Modules\Operations\Loading\Presentation\Http\Controllers\DriverAssignmentController;
use Modules\Operations\Loading\Presentation\Http\Controllers\AllocationController;
use Modules\Operations\Loading\Presentation\Http\Controllers\LoadingExceptionController;
use Modules\Operations\Loading\Presentation\Http\Controllers\VehicleInventoryController;

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
    Route::get('admin/dashboard', AdminDashboardController::class);
    Route::get('context/company', CompanyContextController::class);
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('branches', BranchController::class);
    Route::apiResource('brands', BrandController::class);
    Route::apiResource('business-accounts', BusinessAccountController::class);
    Route::apiResource('teams', TeamController::class);
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
| Media — File uploads (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('media/upload', [MediaController::class, 'upload']);
});

/*
|--------------------------------------------------------------------------
| Inventory — Products, Stock Ledger (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('products/stats', [ProductController::class, 'stats']);
    Route::get('products/next-sku', [ProductController::class, 'nextSku']);
    Route::post('products/import', [ProductController::class, 'import']);
    Route::apiResource('products', ProductController::class);
    Route::patch('products/{product}', [ProductController::class, 'patch']);
    Route::get('products/{product}/cost-history', [InventoryLayerController::class, 'costHistory']);
    Route::get('stock-movements', [StockMovementController::class, 'index']);
    Route::post('stock-movements', [StockMovementController::class, 'store']);
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
    Route::post('inventory-counts/{inventoryCount}/approve',  [InventoryCountController::class, 'approve'])
         ->middleware('permission:inventory.count.approve');
    Route::post('inventory-counts/{inventoryCount}/cancel',   [InventoryCountController::class, 'cancel']);
    Route::get('inventory-counts/{inventoryCount}/report',    [InventoryCountController::class, 'report']);
    // Line attachments
    Route::post('inventory-counts/{inventoryCount}/lines/{line}/attachments',                     [InventoryCountController::class, 'storeAttachment']);
    Route::delete('inventory-counts/{inventoryCount}/lines/{line}/attachments/{attachment}',      [InventoryCountController::class, 'destroyAttachment']);
});

/*
|--------------------------------------------------------------------------
| Inventory — Waste Investigations (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('inventory/waste-investigations/report',                       [WasteInvestigationController::class, 'report']);
    Route::get('inventory/waste-investigations',                              [WasteInvestigationController::class, 'index']);
    Route::get('inventory/waste-investigations/{id}',                         [WasteInvestigationController::class, 'show']);
    Route::post('inventory/waste-investigations/{id}/resolve',                [WasteInvestigationController::class, 'resolve']);
    Route::post('inventory/waste-investigations/{id}/attachments',            [WasteInvestigationController::class, 'storeAttachment']);
    Route::delete('inventory/waste-investigations/{id}/attachments/{attachmentId}', [WasteInvestigationController::class, 'destroyAttachment']);
});

/*
|--------------------------------------------------------------------------
| Inventory — Warehouse Liabilities (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('inventory/warehouse-liabilities/report',       [WarehouseLiabilityController::class, 'report']);
    Route::get('inventory/warehouse-liabilities',              [WarehouseLiabilityController::class, 'index']);
    Route::get('inventory/warehouse-liabilities/{id}',         [WarehouseLiabilityController::class, 'show']);
    Route::post('inventory/warehouse-liabilities/{id}/approve', [WarehouseLiabilityController::class, 'approve']);
    Route::post('inventory/warehouse-liabilities/{id}/reject',  [WarehouseLiabilityController::class, 'reject']);
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
    Route::get('suppliers/stats', [SupplierAnalyticsController::class, 'summaryStats']);
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('suppliers/{supplier}/analytics', [SupplierAnalyticsController::class, 'analytics']);
    Route::get('suppliers/{supplier}/inventory-breakdown', [SupplierAnalyticsController::class, 'inventoryBreakdown']);
    Route::get('suppliers/{supplier}/health', [SupplierAnalyticsController::class, 'health']);
    Route::get('suppliers/{supplier}/price-history', [SupplierAnalyticsController::class, 'priceHistory']);
    Route::get('suppliers/{supplier}/timeline', [SupplierAnalyticsController::class, 'timeline']);
    Route::get('suppliers/{supplier}/documents', [SupplierDocumentController::class, 'index']);
    Route::post('suppliers/{supplier}/documents', [SupplierDocumentController::class, 'store']);
    Route::delete('suppliers/{supplier}/documents/{document}', [SupplierDocumentController::class, 'destroy']);
    Route::get('suppliers/{supplier}/documents/{document}/download', [SupplierDocumentController::class, 'download']);
    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit']);
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
    Route::apiResource('goods-receipts', GoodsReceiptController::class);
    Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post']);

    // Purchase Materials
    Route::middleware('permission:purchasing.materials.view')
        ->get('purchase-materials/stats', [PurchaseMaterialController::class, 'stats']);
    Route::middleware('permission:purchasing.materials.view')
        ->get('purchase-materials/procurement-panel/{product}', [PurchaseMaterialController::class, 'procurementPanel']);
    Route::middleware('permission:purchasing.materials.view')
        ->get('purchase-materials', [PurchaseMaterialController::class, 'index']);
    Route::middleware('permission:purchasing.materials.view')
        ->get('purchase-materials/{purchaseMaterial}', [PurchaseMaterialController::class, 'show']);
    Route::middleware('permission:purchasing.materials.create')
        ->post('purchase-materials', [PurchaseMaterialController::class, 'store']);
    Route::middleware('permission:purchasing.materials.update')
        ->put('purchase-materials/{purchaseMaterial}', [PurchaseMaterialController::class, 'update']);
    Route::middleware('permission:purchasing.materials.delete')
        ->delete('purchase-materials/{purchaseMaterial}', [PurchaseMaterialController::class, 'destroy']);
    Route::middleware('permission:purchasing.materials.submit')
        ->post('purchase-materials/{purchaseMaterial}/submit', [PurchaseMaterialController::class, 'submit']);
    Route::middleware('permission:purchasing.materials.approve')
        ->post('purchase-materials/{purchaseMaterial}/approve', [PurchaseMaterialController::class, 'approve']);
    Route::middleware('permission:purchasing.materials.review')
        ->post('purchase-materials/{purchaseMaterial}/reject', [PurchaseMaterialController::class, 'reject']);
    Route::middleware('permission:purchasing.materials.review')
        ->post('purchase-materials/{purchaseMaterial}/hold', [PurchaseMaterialController::class, 'hold']);
    Route::middleware('permission:purchasing.materials.cancel')
        ->post('purchase-materials/{purchaseMaterial}/cancel', [PurchaseMaterialController::class, 'cancel']);
    Route::middleware('permission:purchasing.materials.review')
        ->post('purchase-materials/{purchaseMaterial}/assign-buyer', [PurchaseMaterialController::class, 'assignBuyer']);
    Route::middleware('permission:purchasing.materials.select_supplier')
        ->post('purchase-materials/{purchaseMaterial}/lines/{line}/select-supplier', [PurchaseMaterialController::class, 'selectLineSupplier']);
});

/*
|--------------------------------------------------------------------------
| Purchasing — Supplier Returns (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('supplier-returns/stats', [SupplierReturnController::class, 'stats']);
    Route::apiResource('supplier-returns', SupplierReturnController::class);
    Route::post('supplier-returns/{supplierReturn}/submit',        [SupplierReturnController::class, 'submit']);
    Route::post('supplier-returns/{supplierReturn}/approve',       [SupplierReturnController::class, 'approve']);
    Route::post('supplier-returns/{supplierReturn}/reject',        [SupplierReturnController::class, 'reject']);
    Route::post('supplier-returns/{supplierReturn}/mark-sent',     [SupplierReturnController::class, 'markSent']);
    Route::post('supplier-returns/{supplierReturn}/credit-pending',[SupplierReturnController::class, 'creditPending']);
    Route::post('supplier-returns/{supplierReturn}/complete',      [SupplierReturnController::class, 'complete']);
    Route::post('supplier-returns/{supplierReturn}/cancel',        [SupplierReturnController::class, 'cancel']);
});

/*
|--------------------------------------------------------------------------
| Purchasing — Supplier Invoices (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('supplier-invoices/stats', [SupplierInvoiceController::class, 'stats']);
    Route::apiResource('supplier-invoices', SupplierInvoiceController::class);
    Route::post('supplier-invoices/{supplierInvoice}/validate', [SupplierInvoiceController::class, 'validate']);
    Route::post('supplier-invoices/{supplierInvoice}/post',     [SupplierInvoiceController::class, 'post']);
    Route::post('supplier-invoices/{supplierInvoice}/cancel',   [SupplierInvoiceController::class, 'cancel']);
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
    // Shared product-level demand analysis — consumed by procurement, inventory, preparation OS
    Route::get('demand-analysis/{product}', [ProductDemandAnalysisController::class, 'show']);
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
    // Terminals
    Route::get('terminals', [PosTerminalController::class, 'index']);

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
| Cost Management — Dashboard, Price Review, Material Cost History (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('cost-management')->group(function (): void {
    // Dashboard KPIs
    Route::get('dashboard', [CostManagementDashboardController::class, 'index']);

    // Price Review Center
    Route::get('pricing-reviews',                              [PricingReviewController::class, 'index']);
    Route::get('pricing-reviews/{id}/detail',                  [PricingReviewController::class, 'detail']);
    Route::post('pricing-reviews/{id}/approve',                [PricingReviewController::class, 'approve']);
    Route::post('pricing-reviews/{id}/snooze',                 [PricingReviewController::class, 'snooze']);
    Route::post('pricing-reviews/{id}/assign',                 [PricingReviewController::class, 'assign']);
    Route::post('pricing-reviews/bulk-approve',                [PricingReviewController::class, 'bulkApprove']);
    Route::patch('pricing-reviews/{id}/inline',                [PricingReviewController::class, 'inline']);
    Route::post('pricing-reviews/bulk-policy',                 [PricingReviewController::class, 'bulkPolicy']);

    // Material Cost History (global and per-material)
    Route::get('cost-history',                                 [MaterialCostController::class, 'globalHistory']);
    Route::get('materials/{productId}/cost-history',           [MaterialCostController::class, 'history']);
    Route::patch('materials/{productId}/cost',                 [MaterialCostController::class, 'update']);
});

/*
|--------------------------------------------------------------------------
| Operations — Preparation OS (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('preparation')->group(function (): void {
    Route::get('dashboard',  [PreparationDashboardController::class, 'index']);
    Route::get('analytics',  [PreparationAnalyticsController::class, 'index']);

    Route::get('waves',                                  [PreparationWaveController::class, 'index']);
    Route::post('waves',                                 [PreparationWaveController::class, 'store']);
    Route::get('waves/{waveId}',                         [PreparationWaveController::class, 'show']);
    Route::post('waves/{waveId}/generate-demand',        [PreparationWaveController::class, 'generateDemand']);
    Route::post('waves/{waveId}/analyze-materials',      [PreparationWaveController::class, 'analyzeMaterials']);
    Route::post('waves/{waveId}/start',                  [PreparationWaveController::class, 'start']);
    Route::patch('waves/{waveId}/items/{itemId}/complete', [PreparationWaveController::class, 'completeItem']);
    Route::post('waves/{waveId}/complete',               [PreparationWaveController::class, 'complete']);
    Route::post('waves/{waveId}/cancel',                 [PreparationWaveController::class, 'cancel']);
    Route::post('waves/{waveId}/recalculate',            [PreparationWaveController::class, 'recalculate']);
    Route::get('waves/{waveId}/product-queue',           [PreparationWaveController::class, 'productQueue']);
    Route::post('waves/{waveId}/approve',                [PreparationWaveController::class, 'approve']);
    Route::post('waves/{waveId}/workers',                [PreparationWaveController::class, 'assignWorker']);
    Route::delete('waves/{waveId}/workers/{userId}',     [PreparationWaveController::class, 'releaseWorker']);
    Route::post('waves/{waveId}/resolve-shortage',       [PreparationWaveController::class, 'resolveShortage']);
    Route::get('waves/{waveId}/timeline',                [PreparationWaveController::class, 'timeline']);
    Route::get('waves/{waveId}/documents',               [PreparationWaveController::class, 'documents']);

    Route::get('pool',                         [PreparedPoolController::class, 'index']);
    Route::patch('pool/{poolId}/quality',      [PreparedPoolController::class, 'updateQuality']);
    Route::get('workers',  [PreparationWorkerController::class, 'index']);
    Route::get('stations', [PreparationStationController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Operations — Loading & Allocation OS (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('loading')->group(function (): void {
    // Dashboard
    Route::get('dashboard', [LoadingDashboardController::class, 'index']);

    // Loading Sessions
    Route::get('sessions',                               [LoadingSessionController::class, 'index']);
    Route::post('sessions',                              [LoadingSessionController::class, 'store']);
    Route::get('sessions/{sessionId}',                   [LoadingSessionController::class, 'show']);
    Route::post('sessions/{sessionId}/open',             [LoadingSessionController::class, 'open']);
    Route::post('sessions/{sessionId}/start-loading',    [LoadingSessionController::class, 'startLoading']);
    Route::post('sessions/{sessionId}/complete-loading', [LoadingSessionController::class, 'completeLoading']);
    Route::post('sessions/{sessionId}/cancel',           [LoadingSessionController::class, 'cancel']);
    Route::post('sessions/{sessionId}/close',            [LoadingSessionController::class, 'close']);

    // Vehicle Assignments (within a session)
    Route::get('sessions/{sessionId}/assignments',                                               [VehicleAssignmentController::class, 'index']);
    Route::post('sessions/{sessionId}/assignments',                                              [VehicleAssignmentController::class, 'store']);
    Route::get('sessions/{sessionId}/assignments/{assignmentId}',                                [VehicleAssignmentController::class, 'show']);
    Route::post('sessions/{sessionId}/assignments/{assignmentId}/load-product',                  [VehicleAssignmentController::class, 'loadProduct']);
    Route::post('sessions/{sessionId}/assignments/{assignmentId}/dispatch',                      [VehicleAssignmentController::class, 'dispatch']);

    // Driver Assignments
    Route::post('sessions/{sessionId}/assignments/{assignmentId}/driver',                        [DriverAssignmentController::class, 'store']);
    Route::get('sessions/{sessionId}/assignments/{assignmentId}/driver',                         [DriverAssignmentController::class, 'show']);

    // Allocation
    Route::get('sessions/{sessionId}/assignments/{assignmentId}/allocation',                     [AllocationController::class, 'index']);
    Route::post('sessions/{sessionId}/start-allocation',                                         [AllocationController::class, 'startAllocation']);
    Route::post('sessions/{sessionId}/complete-allocation',                                      [AllocationController::class, 'completeAllocation']);
    Route::post('sessions/{sessionId}/assignments/{assignmentId}/allocation/override',           [AllocationController::class, 'override']);

    // Vehicle Inventory
    Route::get('sessions/{sessionId}/assignments/{assignmentId}/inventory',                      [VehicleInventoryController::class, 'show']);

    // Exceptions
    Route::get('sessions/{sessionId}/exceptions',                                                [LoadingExceptionController::class, 'index']);
    Route::post('sessions/{sessionId}/exceptions',                                               [LoadingExceptionController::class, 'store']);
    Route::post('sessions/{sessionId}/exceptions/{exceptionId}/resolve',                         [LoadingExceptionController::class, 'resolve']);
});

/*
|--------------------------------------------------------------------------
| Webhooks — WooCommerce (public, no auth)
|--------------------------------------------------------------------------
*/
Route::post('webhooks/woocommerce/{channel}/orders',    [WooCommerceWebhookController::class, 'handleOrder']);
Route::post('webhooks/woocommerce/{channel}/products',  [WooCommerceWebhookController::class, 'handleProduct']);
Route::post('webhooks/woocommerce/{channel}/customers', [WooCommerceWebhookController::class, 'handleCustomer']);
