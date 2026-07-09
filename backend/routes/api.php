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
use Modules\Sales\Customers\Presentation\Http\Controllers\CustomerAddressController;
use Modules\Sales\ShippingPricing\Presentation\Http\Controllers\ShippingPricingController;
use Modules\MasterData\Categories\Presentation\Http\Controllers\CategoryController;
use Modules\MasterData\Units\Presentation\Http\Controllers\UnitController;
use Modules\MasterData\Warehouses\Presentation\Http\Controllers\WarehouseController;
use Modules\Organization\Brands\Presentation\Http\Controllers\BrandController;
use Modules\Organization\Brands\Presentation\Http\Controllers\BrandDeliveryController;
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
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationSessionController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationDashboardController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparedPoolController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationStationController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationWorkerController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationAnalyticsController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\PreparationEnterpriseController;
use Modules\Operations\Preparation\Presentation\Http\Controllers\WarehouseAssignmentController;
use Modules\Operations\Loading\Presentation\Http\Controllers\LoadingDashboardController;
use Modules\Operations\Loading\Presentation\Http\Controllers\LoadingSessionController;
use Modules\Operations\Loading\Presentation\Http\Controllers\VehicleAssignmentController;
use Modules\Operations\Loading\Presentation\Http\Controllers\DriverAssignmentController;
use Modules\Operations\Loading\Presentation\Http\Controllers\AllocationController;
use Modules\Operations\Loading\Presentation\Http\Controllers\LoadingExceptionController;
use Modules\Operations\Loading\Presentation\Http\Controllers\VehicleInventoryController;
use Modules\Operations\Fulfillment\Presentation\Http\Controllers\FulfillmentController as OrderFulfillmentController;
use Modules\Operations\Fulfillment\Presentation\Http\Controllers\BulkFulfillmentController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\BrandConfigurationController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\BrandCoverageController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\CompanyConfigurationController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\DeliveryGeographyController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\DeliveryZoneController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\BrandShippingRuleController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\DeliveryWindowController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\PreparationPolicyController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\MasterGeographyController;
use Modules\Admin\Configuration\Presentation\Http\Controllers\MasterZoneController;

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
    Route::middleware(['throttle:10,1'])->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Organization — Companies (protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::get('admin/dashboard', AdminDashboardController::class);
    Route::get('context/company', CompanyContextController::class);
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('branches', BranchController::class);
    Route::apiResource('brands', BrandController::class);
    Route::prefix('brands/{brand}')->group(function (): void {
        Route::get('delivery-geography', [BrandDeliveryController::class, 'geography']);
        Route::get('delivery-windows', [BrandDeliveryController::class, 'windows']);
        Route::get('configuration-health', [BrandDeliveryController::class, 'health']);
    });
    Route::apiResource('business-accounts', BusinessAccountController::class);
    Route::apiResource('teams', TeamController::class);
});

/*
|--------------------------------------------------------------------------
| Master Data — Warehouses, Categories, Units (protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::apiResource('warehouses', WarehouseController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('units', UnitController::class);
});

/*
|--------------------------------------------------------------------------
| Media — File uploads (protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::post('media/upload', [MediaController::class, 'upload']);
});

/*
|--------------------------------------------------------------------------
| Inventory — Products, Stock Ledger (protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::get('products/stats', [ProductController::class, 'stats']);
    Route::get('products/next-sku', [ProductController::class, 'nextSku']);
    Route::middleware(['throttle:10,1'])->group(function (): void {
        Route::post('products/import', [ProductController::class, 'import']);
    });
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::get('customers/search-by-phone', [CustomerController::class, 'searchByPhone']);
    Route::apiResource('customers', CustomerController::class);
    Route::apiResource('customers.addresses', CustomerAddressController::class)->shallow();
});

/*
|--------------------------------------------------------------------------
| Sales — Shipping Pricing (protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::post('shipping-pricing/calculate', [ShippingPricingController::class, 'calculate']);
    Route::apiResource('shipping-pricing', ShippingPricingController::class);
});

/*
|--------------------------------------------------------------------------
| Commerce — Channels (protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::apiResource('channels', ChannelController::class);
    Route::post('channels/{channel}/test-connection', [ConnectorController::class, 'testConnection']);
    Route::middleware(['throttle:10,1'])->group(function (): void {
        Route::post('channels/{channel}/import-products', [ProductImportController::class, 'importProducts']);
        Route::post('channels/{channel}/import-orders', [OrderImportController::class, 'importOrders']);
    });
    Route::apiResource('product-mappings', ProductMappingController::class);
    Route::post('orders/manual', [OrderController::class, 'storeManual']);
    Route::get('orders/pricing/product/{productId}', [OrderController::class, 'productPricing']);
    Route::patch('orders/{order}/quick-update', [OrderController::class, 'quickUpdate']);
    Route::get('orders/{order}/snapshot', [OrderController::class, 'financialSnapshot']);
    Route::apiResource('orders', OrderController::class);
    Route::post('orders/{order}/prepare', [OrderController::class, 'prepare']);
    // CR-PREP-001: Warehouse assignment
    Route::post('orders/{order}/assign-warehouse',   [WarehouseAssignmentController::class, 'assignWarehouse']);
    Route::post('orders/{order}/override-warehouse', [WarehouseAssignmentController::class, 'overrideWarehouse']);
    Route::get('orders/{order}/assignment-history',  [WarehouseAssignmentController::class, 'assignmentHistory']);
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::apiResource('boms', BomController::class);
});

/*
|--------------------------------------------------------------------------
| Commerce — Synchronization Logs (protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
    Route::get('sync-logs', [SynchronizationController::class, 'index']);
    Route::post('sync-logs/{syncLog}/retry', [SynchronizationController::class, 'retry']);
});

/*
|--------------------------------------------------------------------------
| Inventory Control — Dashboard, ABC, Variance, Warehouse Performance (protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
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
Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
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
    Route::get('pricing-reviews/badge',                        [PricingReviewController::class, 'badge']);
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
    Route::get('waves/{waveId}/product-queue',                       [PreparationWaveController::class, 'productQueue']);
    Route::get('waves/{waveId}/items/{itemId}/workspace',            [PreparationWaveController::class, 'productWorkspace']);
    Route::post('waves/{waveId}/issues',                             [PreparationWaveController::class, 'reportIssue']);
    Route::post('waves/{waveId}/approve',                            [PreparationWaveController::class, 'approve']);
    Route::post('waves/{waveId}/workers',                            [PreparationWaveController::class, 'assignWorker']);
    Route::delete('waves/{waveId}/workers/{userId}',                 [PreparationWaveController::class, 'releaseWorker']);
    Route::post('waves/{waveId}/resolve-shortage',                   [PreparationWaveController::class, 'resolveShortage']);
    Route::get('waves/{waveId}/timeline',                            [PreparationWaveController::class, 'timeline']);
    Route::get('waves/{waveId}/documents',                           [PreparationWaveController::class, 'documents']);

    // Enterprise Preparation — Phases 6, 8, 9, 13, 14 (TASK-PREPARATION-INTEGRATION-001)
    Route::get('enterprise/queue',         [PreparationEnterpriseController::class, 'queue']);
    Route::get('enterprise/capacity',      [PreparationEnterpriseController::class, 'capacity']);
    Route::get('enterprise/optimization',  [PreparationEnterpriseController::class, 'optimization']);
    Route::get('enterprise/dashboard',     [PreparationEnterpriseController::class, 'dashboard']);
    Route::get('enterprise/ai-context',    [PreparationEnterpriseController::class, 'aiContext']);

    // CR-PREP-001: Today's Preparation (must come before {sessionId} route)
    Route::get('sessions/today',                             [PreparationSessionController::class, 'today']);

    Route::get('sessions',                               [PreparationSessionController::class, 'index']);
    Route::post('sessions',                              [PreparationSessionController::class, 'store']);
    Route::get('sessions/{sessionId}',                   [PreparationSessionController::class, 'show']);
    Route::post('sessions/{sessionId}/start',            [PreparationSessionController::class, 'start']);
    Route::post('sessions/{sessionId}/plan',             [PreparationSessionController::class, 'plan']);
    Route::post('sessions/{sessionId}/approve',          [PreparationSessionController::class, 'approve']);
    Route::post('sessions/{sessionId}/close',            [PreparationSessionController::class, 'close']);
    Route::post('sessions/{sessionId}/complete',         [PreparationSessionController::class, 'complete']);
    Route::post('sessions/{sessionId}/cancel',           [PreparationSessionController::class, 'cancel']);
    Route::post('sessions/{sessionId}/waves',            [PreparationSessionController::class, 'addWave']);
    Route::get('sessions/{sessionId}/consolidation',     [PreparationSessionController::class, 'consolidation']);
    // CR-PREP-001: Freeze + Session Orders + Session Products
    Route::post('sessions/{sessionId}/freeze',                        [PreparationSessionController::class, 'freeze']);
    Route::get('sessions/{sessionId}/orders',                         [PreparationSessionController::class, 'sessionOrders']);
    Route::post('sessions/{sessionId}/attach-order',                  [PreparationSessionController::class, 'attachOrder']);
    Route::delete('sessions/{sessionId}/orders/{sessionOrderId}',     [PreparationSessionController::class, 'detachOrder']);
    Route::get('sessions/{sessionId}/products',                       [PreparationSessionController::class, 'sessionProducts']);

    // CR-PREP-001: Warehouse Assignment Policies
    Route::get('warehouse-assignment-policies',           [WarehouseAssignmentController::class, 'indexPolicies']);
    Route::post('warehouse-assignment-policies',          [WarehouseAssignmentController::class, 'storePolicy']);
    Route::put('warehouse-assignment-policies/{id}',      [WarehouseAssignmentController::class, 'updatePolicy']);
    Route::delete('warehouse-assignment-policies/{id}',   [WarehouseAssignmentController::class, 'destroyPolicy']);

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
| Admin — Configuration OS (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('configuration')->group(function (): void {
    // Company-level settings
    Route::get('company',                    [CompanyConfigurationController::class, 'index']);
    Route::get('company/audit',              [CompanyConfigurationController::class, 'audit']);
    Route::get('company/{group}',            [CompanyConfigurationController::class, 'showGroup']);
    Route::put('company/{group}',            [CompanyConfigurationController::class, 'updateGroup']);

    // Brand-level policy groups
    Route::get('brands/{brandId}/policies',               [BrandConfigurationController::class, 'index']);
    Route::get('brands/{brandId}/policies/{group}',       [BrandConfigurationController::class, 'show']);
    Route::put('brands/{brandId}/policies/{group}',       [BrandConfigurationController::class, 'update']);
    Route::get('brands/{brandId}/audit',                  [BrandConfigurationController::class, 'audit']);

    // Delivery Geography (Governorates)
    Route::get('brands/{brandId}/geographies',            [DeliveryGeographyController::class, 'index']);
    Route::post('brands/{brandId}/geographies',           [DeliveryGeographyController::class, 'store']);
    Route::put('brands/{brandId}/geographies/{id}',       [DeliveryGeographyController::class, 'update']);
    Route::delete('brands/{brandId}/geographies/{id}',    [DeliveryGeographyController::class, 'destroy']);

    // Delivery Zones within Governorates
    Route::get('brands/{brandId}/geographies/{geoId}/zones',          [DeliveryZoneController::class, 'index']);
    Route::post('brands/{brandId}/geographies/{geoId}/zones',         [DeliveryZoneController::class, 'store']);
    Route::put('brands/{brandId}/geographies/{geoId}/zones/{id}',     [DeliveryZoneController::class, 'update']);
    Route::delete('brands/{brandId}/geographies/{geoId}/zones/{id}',  [DeliveryZoneController::class, 'destroy']);

    // Brand Shipping Rules (per delivery zone)
    Route::get('brands/{brandId}/shipping-rules',         [BrandShippingRuleController::class, 'index']);
    Route::post('brands/{brandId}/shipping-rules',        [BrandShippingRuleController::class, 'store']);
    Route::put('brands/{brandId}/shipping-rules/{id}',    [BrandShippingRuleController::class, 'update']);
    Route::delete('brands/{brandId}/shipping-rules/{id}', [BrandShippingRuleController::class, 'destroy']);

    // Delivery Windows
    Route::get('brands/{brandId}/delivery-windows',                   [DeliveryWindowController::class, 'index']);
    Route::post('brands/{brandId}/delivery-windows',                  [DeliveryWindowController::class, 'store']);
    Route::put('brands/{brandId}/delivery-windows/{id}',              [DeliveryWindowController::class, 'update']);
    Route::delete('brands/{brandId}/delivery-windows/{id}',           [DeliveryWindowController::class, 'destroy']);
    Route::post('brands/{brandId}/delivery-windows/seed-defaults',    [DeliveryWindowController::class, 'seedDefaults']);
    Route::patch('brands/{brandId}/delivery-windows/reorder',         [DeliveryWindowController::class, 'reorder']);

    // Preparation Policies (Configuration OS facade over Preparation OS)
    Route::get('brands/{brandId}/preparation-policies',           [PreparationPolicyController::class, 'index']);
    Route::post('brands/{brandId}/preparation-policies',          [PreparationPolicyController::class, 'store']);
    Route::put('brands/{brandId}/preparation-policies/{id}',      [PreparationPolicyController::class, 'update']);

    // Brand Coverage
    Route::get('brands/{brandId}/coverage',                        [BrandCoverageController::class, 'coverage']);
    Route::get('brands/{brandId}/coverage-stats',                  [BrandCoverageController::class, 'stats']);
    Route::get('brands/{brandId}/health-score',                    [BrandCoverageController::class, 'healthScore']);
    Route::post('brands/{brandId}/clone-from/{sourceBrandId}',     [BrandCoverageController::class, 'cloneFrom']);

    // Master Geography — governorates
    Route::prefix('master-geography')->group(function (): void {
        Route::get('/',           [MasterGeographyController::class, 'index']);
        Route::post('/',          [MasterGeographyController::class, 'store']);
        Route::get('/{id}',       [MasterGeographyController::class, 'show']);
        Route::put('/{id}',       [MasterGeographyController::class, 'update']);
        Route::delete('/{id}',    [MasterGeographyController::class, 'destroy']);
        Route::post('/{id}/archive', [MasterGeographyController::class, 'archive']);

        // Master zones nested under a governorate
        Route::get('/{govId}/zones',              [MasterZoneController::class, 'index']);
        Route::post('/{govId}/zones',             [MasterZoneController::class, 'store']);
        Route::put('/{govId}/zones/{id}',         [MasterZoneController::class, 'update']);
        Route::delete('/{govId}/zones/{id}',      [MasterZoneController::class, 'destroy']);
        Route::post('/{govId}/zones/{id}/archive',[MasterZoneController::class, 'archive']);
    });
});

/*
|--------------------------------------------------------------------------
| Operations — Fulfillment Engine (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('fulfillment')->group(function (): void {
    // Single-order workflow transitions
    Route::post('orders/{order}/confirm',              [OrderFulfillmentController::class, 'confirm']);
    Route::post('orders/{order}/cancel',               [OrderFulfillmentController::class, 'cancel']);
    Route::post('orders/{order}/move-to-preparation',  [OrderFulfillmentController::class, 'moveToPreparation']);
    Route::post('orders/{order}/complete-delivery',    [OrderFulfillmentController::class, 'completeDelivery']);
    Route::post('orders/{order}/return',               [OrderFulfillmentController::class, 'returnOrder']);

    // Return receiving
    Route::post('returns/{customerReturn}/receive',    [OrderFulfillmentController::class, 'receiveReturn']);

    // Bulk workflow transitions
    Route::post('bulk/confirm',               [BulkFulfillmentController::class, 'confirmBulk']);
    Route::post('bulk/cancel',                [BulkFulfillmentController::class, 'cancelBulk']);
    Route::post('bulk/move-to-preparation',   [BulkFulfillmentController::class, 'moveToPreparationBulk']);
    Route::post('bulk/complete-delivery',     [BulkFulfillmentController::class, 'completeDeliveryBulk']);
});

/*
|--------------------------------------------------------------------------
| Marketing OS — Meta Integration Platform (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('marketing')->group(function (): void {
    // Dashboard
    Route::get('dashboard', [\Modules\Marketing\Dashboard\Presentation\Http\Controllers\MarketingDashboardController::class, 'index']);

    // Connector registry
    Route::get('connectors', [\Modules\Marketing\Connections\Presentation\Http\Controllers\ConnectionController::class, 'connectors']);

    // Connections
    Route::get('connections',                                         [\Modules\Marketing\Connections\Presentation\Http\Controllers\ConnectionController::class, 'index']);
    Route::get('connections/{connection}',                            [\Modules\Marketing\Connections\Presentation\Http\Controllers\ConnectionController::class, 'show']);
    Route::post('connections/{connection}/validate',                  [\Modules\Marketing\Connections\Presentation\Http\Controllers\ConnectionController::class, 'validatePermissions']);
    Route::post('connections/{connection}/disconnect',                [\Modules\Marketing\Connections\Presentation\Http\Controllers\ConnectionController::class, 'disconnect']);
    Route::post('connections/{connection}/sync',                      [\Modules\Marketing\Synchronization\Presentation\Http\Controllers\SyncController::class, 'triggerSync']);
    Route::get('connections/{connection}/sync-logs',                  [\Modules\Marketing\Synchronization\Presentation\Http\Controllers\SyncController::class, 'logs']);
    Route::get('connections/{connection}/health',                     [\Modules\Marketing\Connections\Presentation\Http\Controllers\ConnectorHealthController::class, 'show']);

    // Meta OAuth
    Route::get('meta/auth/redirect',   [\Modules\Marketing\MetaConnector\Presentation\Http\Controllers\MetaAuthController::class, 'redirect']);
    Route::get('meta/auth/callback',   [\Modules\Marketing\MetaConnector\Presentation\Http\Controllers\MetaAuthController::class, 'callback']);

    // Assets
    Route::get('assets',                                                [\Modules\Marketing\Assets\Presentation\Http\Controllers\MarketingAssetController::class, 'index']);
    Route::get('assets/{marketingAsset}',                               [\Modules\Marketing\Assets\Presentation\Http\Controllers\MarketingAssetController::class, 'show']);
    Route::post('assets/{marketingAsset}/check-health',                 [\Modules\Marketing\Assets\Presentation\Http\Controllers\MarketingAssetController::class, 'checkHealth']);
    Route::get('assets/{marketingAsset}/graph',                          [\Modules\Marketing\Assets\Presentation\Http\Controllers\AssetRelationshipController::class, 'graph']);

    // Asset Relationships (M2M mapping)
    Route::get('assets/{marketingAsset}/relationships',                 [\Modules\Marketing\Assets\Presentation\Http\Controllers\AssetRelationshipController::class, 'index']);
    Route::post('assets/{marketingAsset}/relationships',                [\Modules\Marketing\Assets\Presentation\Http\Controllers\AssetRelationshipController::class, 'store']);
    Route::delete('relationships/{relationship}',                       [\Modules\Marketing\Assets\Presentation\Http\Controllers\AssetRelationshipController::class, 'destroy']);
    Route::post('relationships/{relationship}/accept',                  [\Modules\Marketing\Assets\Presentation\Http\Controllers\AssetRelationshipController::class, 'accept']);
    Route::post('relationships/{relationship}/reject',                  [\Modules\Marketing\Assets\Presentation\Http\Controllers\AssetRelationshipController::class, 'reject']);
    Route::get('suggestions',                                           [\Modules\Marketing\Assets\Presentation\Http\Controllers\AssetRelationshipController::class, 'suggestions']);

    // Sync logs
    Route::get('sync-logs/{syncLog}',                                   [\Modules\Marketing\Synchronization\Presentation\Http\Controllers\SyncController::class, 'show']);

    // Mapping Profiles
    Route::get('mapping-profiles',                                      [\Modules\Marketing\MappingEngine\Presentation\Http\Controllers\MappingProfileController::class, 'index']);
    Route::post('mapping-profiles',                                     [\Modules\Marketing\MappingEngine\Presentation\Http\Controllers\MappingProfileController::class, 'store']);
    Route::get('mapping-profiles/{mappingProfile}',                     [\Modules\Marketing\MappingEngine\Presentation\Http\Controllers\MappingProfileController::class, 'show']);
    Route::put('mapping-profiles/{mappingProfile}',                     [\Modules\Marketing\MappingEngine\Presentation\Http\Controllers\MappingProfileController::class, 'update']);
    Route::delete('mapping-profiles/{mappingProfile}',                  [\Modules\Marketing\MappingEngine\Presentation\Http\Controllers\MappingProfileController::class, 'destroy']);
    Route::post('mapping-profiles/{mappingProfile}/apply',              [\Modules\Marketing\MappingEngine\Presentation\Http\Controllers\MappingProfileController::class, 'apply']);

    // Marketing Initiatives (ERP Business Layer — never synced with Meta)
    Route::get('initiative-dashboard',                                                   [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeDashboardController::class, 'index']);
    Route::get('initiatives',                                                             [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeController::class, 'index']);
    Route::post('initiatives',                                                            [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeController::class, 'store']);
    Route::get('initiatives/{initiative}',                                                [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeController::class, 'show']);
    Route::put('initiatives/{initiative}',                                                [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeController::class, 'update']);
    Route::post('initiatives/{initiative}/archive',                                       [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeController::class, 'archive']);
    Route::get('initiatives/{initiative}/kpis',                                           [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeDashboardController::class, 'kpis']);
    Route::get('initiatives/{initiative}/campaigns',                                      [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeCampaignController::class, 'index']);
    Route::post('initiatives/{initiative}/campaigns',                                     [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeCampaignController::class, 'assign']);
    Route::delete('initiatives/{initiative}/campaigns/{campaign}',                        [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeCampaignController::class, 'remove']);

    // Initiative Templates
    Route::get('initiative-templates',                                                    [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeTemplateController::class, 'index']);
    Route::post('initiative-templates',                                                   [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeTemplateController::class, 'store']);
    Route::get('initiative-templates/{initiativeTemplate}',                               [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeTemplateController::class, 'show']);
    Route::put('initiative-templates/{initiativeTemplate}',                               [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeTemplateController::class, 'update']);
    Route::delete('initiative-templates/{initiativeTemplate}',                            [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeTemplateController::class, 'destroy']);
    Route::post('initiative-templates/{initiativeTemplate}/create-initiative',            [\Modules\Marketing\Initiatives\Presentation\Http\Controllers\InitiativeTemplateController::class, 'createInitiative']);

    // Campaigns — trigger sync per connection
    Route::post('connections/{connection}/campaigns/sync',              [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignSyncController::class, 'triggerSync']);

    // Campaign Workspace (Phase 4)
    Route::get('campaigns',                                             [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignController::class, 'index']);
    Route::get('campaigns/dashboard',                                   [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignDashboardController::class, 'index']);

    // Campaign Ranking (Phase 7)
    Route::get('campaigns/ranking/campaigns',                           [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignRankingController::class, 'topCampaigns']);
    Route::get('campaigns/ranking/ad-sets',                             [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignRankingController::class, 'topAdSets']);
    Route::get('campaigns/ranking/ads',                                 [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignRankingController::class, 'topAds']);
    Route::get('campaigns/ranking/companies',                           [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignRankingController::class, 'topCompanies']);
    Route::get('campaigns/ranking/brands',                              [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignRankingController::class, 'topBrands']);
    Route::get('campaigns/ranking/channels',                            [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignRankingController::class, 'topChannels']);
    Route::get('campaigns/ranking/owners',                              [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignRankingController::class, 'topOwners']);

    // Campaign detail + sub-resources
    Route::get('campaigns/{campaign}',                                  [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignController::class, 'show']);
    Route::patch('campaigns/{campaign}/business-context',               [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignController::class, 'updateBusinessContext']);
    Route::post('campaigns/{campaign}/backfill',                        [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignSyncController::class, 'backfill']);
    Route::get('campaigns/{campaign}/insights',                         [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignInsightController::class, 'index']);
    Route::get('campaigns/{campaign}/insights/trend',                   [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignInsightController::class, 'trend']);
    Route::get('campaigns/{campaign}/creatives',                        [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignCreativeController::class, 'index']);
    Route::get('campaigns/{campaign}/creatives/{creative}',             [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignCreativeController::class, 'show']);
    Route::get('campaigns/{campaign}/ad-sets',                          [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignAdSetController::class, 'index']);
    Route::get('campaigns/{campaign}/ad-sets/{adSet}',                  [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignAdSetController::class, 'show']);
    Route::get('campaigns/{campaign}/ad-sets/{adSet}/ads',              [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignAdController::class, 'index']);
    Route::get('campaigns/{campaign}/ad-sets/{adSet}/ads/{ad}',         [\Modules\Marketing\Campaigns\Presentation\Http\Controllers\CampaignAdController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| Campaign Studio — ECOS-Native Campaign Operations Platform
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('marketing/studio')->group(function (): void {
    // ─── Studio KPIs & Dashboard ────────────────────────────────────────────────
    Route::get('kpis',                          [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignStudioController::class, 'kpis']);
    Route::get('dashboard',                     [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\StudioExecutiveDashboardController::class, 'index']);
    Route::get('dashboard/pending-approvals',   [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\StudioExecutiveDashboardController::class, 'pendingApprovals']);
    Route::get('dashboard/publishing-queue',    [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\StudioExecutiveDashboardController::class, 'publishingQueue']);

    // ─── Campaign Drafts (CRUD) ─────────────────────────────────────────────────
    Route::get('drafts',                        [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignStudioController::class, 'index']);
    Route::post('drafts',                       [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignStudioController::class, 'store']);
    Route::get('drafts/{draft}',                [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignStudioController::class, 'show']);
    Route::patch('drafts/{draft}',              [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignStudioController::class, 'update']);
    Route::delete('drafts/{draft}',             [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignStudioController::class, 'destroy']);
    Route::post('drafts/{draft}/duplicate',     [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignStudioController::class, 'duplicate']);

    // ─── Audience Builder ────────────────────────────────────────────────────────
    Route::get('drafts/{draft}/audience',       [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignAudienceController::class, 'show']);
    Route::put('drafts/{draft}/audience',       [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignAudienceController::class, 'update']);

    // ─── Creative Builder ────────────────────────────────────────────────────────
    Route::get('drafts/{draft}/creatives',               [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignCreativeController::class, 'index']);
    Route::post('drafts/{draft}/creatives',              [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignCreativeController::class, 'store']);
    Route::patch('drafts/{draft}/creatives/{creative}',  [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignCreativeController::class, 'update']);
    Route::delete('drafts/{draft}/creatives/{creative}', [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignCreativeController::class, 'destroy']);

    // ─── Placement Builder ───────────────────────────────────────────────────────
    Route::get('drafts/{draft}/placements',     [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignPlacementController::class, 'show']);
    Route::put('drafts/{draft}/placements',     [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignPlacementController::class, 'update']);

    // ─── Version History ─────────────────────────────────────────────────────────
    Route::get('drafts/{draft}/versions',                                               [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignVersionController::class, 'index']);
    Route::get('drafts/{draft}/versions/{versionA}/compare/{versionB}',                [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignVersionController::class, 'compare']);
    Route::post('drafts/{draft}/versions/{version}/restore',                            [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignVersionController::class, 'restore']);

    // ─── Approval Workflow ───────────────────────────────────────────────────────
    Route::post('drafts/{draft}/submit-for-approval',   [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignApprovalController::class, 'submit']);
    Route::get('drafts/{draft}/approval',               [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignApprovalController::class, 'show']);
    Route::get('approvals/pending',                     [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignApprovalController::class, 'pending']);
    Route::post('approvals/{approval}/decide',          [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignApprovalController::class, 'decide']);
    Route::delete('approvals/{approval}/cancel',        [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignApprovalController::class, 'cancel']);

    // ─── Publishing & Lifecycle ──────────────────────────────────────────────────
    Route::post('drafts/{draft}/publish',       [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\PublishingJobController::class, 'publish']);
    Route::post('drafts/{draft}/pause',         [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\PublishingJobController::class, 'pause']);
    Route::post('drafts/{draft}/resume',        [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\PublishingJobController::class, 'resume']);
    Route::post('drafts/{draft}/archive',       [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\PublishingJobController::class, 'archive']);
    Route::get('jobs',                          [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\PublishingJobController::class, 'index']);
    Route::get('jobs/stats',                    [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\PublishingJobController::class, 'stats']);
    Route::post('jobs/{job}/retry',             [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\PublishingJobController::class, 'retry']);

    // ─── Scheduling ──────────────────────────────────────────────────────────────
    Route::get('drafts/{draft}/schedule',           [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignScheduleController::class, 'pending']);
    Route::post('drafts/{draft}/schedule',          [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignScheduleController::class, 'store']);
    Route::delete('schedule-tasks/{task}',          [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignScheduleController::class, 'destroy']);

    // ─── Validation Engine ───────────────────────────────────────────────────────
    Route::post('drafts/{draft}/validate',          [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\ValidationController::class, 'validate']);
    Route::get('drafts/{draft}/validation-results', [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\ValidationController::class, 'results']);

    // ─── Commerce Integration ────────────────────────────────────────────────────
    Route::get('drafts/{draft}/products',              [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CommerceIntegrationController::class, 'index']);
    Route::post('drafts/{draft}/products',             [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CommerceIntegrationController::class, 'store']);
    Route::post('drafts/{draft}/products/refresh',     [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CommerceIntegrationController::class, 'refresh']);
    Route::delete('drafts/{draft}/products/{product}', [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CommerceIntegrationController::class, 'destroy']);

    // ─── Bulk Operations ─────────────────────────────────────────────────────────
    Route::post('bulk',                         [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\BulkOperationController::class, 'execute']);
    Route::get('bulk/{job}',                    [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\BulkOperationController::class, 'status']);

    // ─── Templates ───────────────────────────────────────────────────────────────
    Route::get('templates',                             [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignTemplateController::class, 'index']);
    Route::post('templates',                            [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignTemplateController::class, 'store']);
    Route::get('templates/{template}',                  [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignTemplateController::class, 'show']);
    Route::put('templates/{template}',                  [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignTemplateController::class, 'update']);
    Route::delete('templates/{template}',               [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignTemplateController::class, 'destroy']);
    Route::post('templates/{template}/create-campaign', [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\CampaignTemplateController::class, 'createCampaign']);

    // ─── Governance Policies ─────────────────────────────────────────────────────
    Route::get('governance',                    [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\GovernancePolicyController::class, 'index']);
    Route::post('governance',                   [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\GovernancePolicyController::class, 'store']);
    Route::get('governance/{policy}',           [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\GovernancePolicyController::class, 'show']);
    Route::put('governance/{policy}',           [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\GovernancePolicyController::class, 'update']);
    Route::delete('governance/{policy}',        [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\GovernancePolicyController::class, 'destroy']);

    // ─── Approval Workflow Templates ──────────────────────────────────────────────
    Route::get('workflows',                     [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\ApprovalWorkflowController::class, 'index']);
    Route::post('workflows',                    [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\ApprovalWorkflowController::class, 'store']);
    Route::get('workflows/{workflow}',          [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\ApprovalWorkflowController::class, 'show']);
    Route::put('workflows/{workflow}',          [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\ApprovalWorkflowController::class, 'update']);
    Route::delete('workflows/{workflow}',       [\Modules\Marketing\CampaignStudio\Presentation\Http\Controllers\ApprovalWorkflowController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Business Attribution Engine (BAE) — Core Platform
| NEVER depends on Marketing — all modules depend on BAE.
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('bae')->group(function (): void {
    // ─── Event Bus ────────────────────────────────────────────────────────────
    Route::get('events/timeline',                [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessEventController::class, 'timeline']);
    Route::get('events/for-entity',              [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessEventController::class, 'forEntity']);
    Route::get('events/for-dna/{dnaId}',         [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessEventController::class, 'forDna']);
    Route::get('events/{businessEvent}',         [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessEventController::class, 'show']);
    Route::post('events',                        [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessEventController::class, 'publish']);

    // ─── Business DNA ─────────────────────────────────────────────────────────
    Route::get('dna',                            [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessDnaController::class, 'index']);
    Route::post('dna',                           [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessDnaController::class, 'store']);
    Route::get('dna/for-entity',                 [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessDnaController::class, 'forEntity']);
    Route::get('dna/{businessDna}',              [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessDnaController::class, 'show']);
    Route::patch('dna/{businessDna}',            [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessDnaController::class, 'update']);

    // ─── Journey Explorer ─────────────────────────────────────────────────────
    Route::get('journey/search',                 [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\JourneyExplorerController::class, 'search']);
    Route::get('journey/{businessDna}',          [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\JourneyExplorerController::class, 'journey']);
    Route::post('journey/step',                  [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\JourneyExplorerController::class, 'recordStep']);

    // ─── Attribution Engine ───────────────────────────────────────────────────
    Route::get('attribution/{businessDna}',      [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\AttributionController::class, 'calculate']);
    Route::get('attribution/configs',            [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\AttributionController::class, 'configs']);
    Route::post('attribution/configs',           [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\AttributionController::class, 'saveConfig']);

    // ─── Business Metrics ─────────────────────────────────────────────────────
    Route::get('metrics/averages',               [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessMetricsController::class, 'aggregateAverages']);
    Route::get('metrics/{businessDna}',          [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\BusinessMetricsController::class, 'forDna']);

    // ─── Graph Layer ──────────────────────────────────────────────────────────
    Route::post('graph/nodes',                   [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\GraphController::class, 'upsertNode']);
    Route::post('graph/relationships',           [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\GraphController::class, 'createRelationship']);
    Route::get('graph/nodes/{entityNode}',       [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\GraphController::class, 'node']);
    Route::get('graph/nodes/{entityNode}/subgraph', [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\GraphController::class, 'subgraph']);

    // ─── Event Replay ─────────────────────────────────────────────────────────
    Route::post('replay',                        [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\ReplayController::class, 'replay']);

    // ─── PATCH-CORE-001: Enhanced Replay Engine ───────────────────────────────
    Route::get('replay/entity/{entityType}/{entityId}', [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\ReplayController::class, 'replayEntity']);
    Route::post('replay/batch',                  [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\ReplayController::class, 'batch']);
    Route::get('replay/module/{module}',         [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\ReplayController::class, 'replayModule']);
    Route::get('replay/audit',                   [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\ReplayController::class, 'auditLogs']);
    Route::get('replay/audit/stats',             [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\ReplayController::class, 'auditStats']);

    // ─── Time Machine ─────────────────────────────────────────────────────────
    Route::get('time-machine/context',           [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\TimeMachineController::class, 'context']);
    Route::get('time-machine/{entityType}/{entityId}',       [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\TimeMachineController::class, 'resolveAt']);
    Route::get('time-machine/{entityType}/{entityId}/view',  [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\TimeMachineController::class, 'historicalView']);
    Route::get('time-machine/{entityType}/{entityId}/diff',  [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\TimeMachineController::class, 'diff']);

    // ─── Root Cause Traversal ─────────────────────────────────────────────────
    Route::get('cause-effect/path',              [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\RootCauseController::class, 'criticalPath']);
    Route::get('cause-effect/{eventId}',         [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\RootCauseController::class, 'traverse']);
    Route::get('cause-effect/{eventId}/root-causes', [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\RootCauseController::class, 'rootCauses']);
    Route::get('cause-effect/{eventId}/effects', [\Modules\Core\BusinessAttribution\Presentation\Http\Controllers\RootCauseController::class, 'effects']);
});

/*
|--------------------------------------------------------------------------
| Customer Engagement Platform (CEP) — Unified Customer Communication
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('cep')->group(function (): void {
    // ─── Dashboard ────────────────────────────────────────────────────────────
    Route::get('dashboard/kpis',        [\Modules\CustomerEngagement\Presentation\Http\Controllers\DashboardController::class, 'kpis']);
    Route::get('dashboard/agents',      [\Modules\CustomerEngagement\Presentation\Http\Controllers\DashboardController::class, 'agentPerformance']);
    Route::get('dashboard/providers',   [\Modules\CustomerEngagement\Presentation\Http\Controllers\DashboardController::class, 'providerDistribution']);
    Route::get('dashboard/statuses',    [\Modules\CustomerEngagement\Presentation\Http\Controllers\DashboardController::class, 'statusDistribution']);
    Route::get('dashboard/unread-count',[\Modules\CustomerEngagement\Presentation\Http\Controllers\DashboardController::class, 'unreadCount']);

    // ─── Conversations ────────────────────────────────────────────────────────
    Route::get('conversations',                      [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationController::class, 'index']);
    Route::post('conversations',                     [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationController::class, 'store']);
    Route::get('conversations/{conversation}',       [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationController::class, 'show']);
    Route::patch('conversations/{conversation}',     [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationController::class, 'update']);
    Route::post('conversations/{conversation}/close',   [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationController::class, 'close']);
    Route::post('conversations/{conversation}/resolve', [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationController::class, 'resolve']);
    Route::post('conversations/{conversation}/reopen',  [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationController::class, 'reopen']);

    // ─── Messages ─────────────────────────────────────────────────────────────
    Route::get('conversations/{conversation}/messages',       [\Modules\CustomerEngagement\Presentation\Http\Controllers\MessageController::class, 'thread']);
    Route::post('conversations/{conversation}/messages',      [\Modules\CustomerEngagement\Presentation\Http\Controllers\MessageController::class, 'send']);
    Route::post('conversations/{conversation}/messages/read', [\Modules\CustomerEngagement\Presentation\Http\Controllers\MessageController::class, 'markRead']);
    Route::post('messages/ingest',                            [\Modules\CustomerEngagement\Presentation\Http\Controllers\MessageController::class, 'ingest']);

    // ─── Leads ───────────────────────────────────────────────────────────────
    Route::get('leads',                              [\Modules\CustomerEngagement\Presentation\Http\Controllers\LeadController::class, 'index']);
    Route::get('leads/{lead}',                       [\Modules\CustomerEngagement\Presentation\Http\Controllers\LeadController::class, 'show']);
    Route::patch('leads/{lead}',                     [\Modules\CustomerEngagement\Presentation\Http\Controllers\LeadController::class, 'update']);
    Route::post('leads/{lead}/qualify',              [\Modules\CustomerEngagement\Presentation\Http\Controllers\LeadController::class, 'qualify']);
    Route::post('leads/{lead}/disqualify',           [\Modules\CustomerEngagement\Presentation\Http\Controllers\LeadController::class, 'disqualify']);
    Route::post('leads/{lead}/convert',              [\Modules\CustomerEngagement\Presentation\Http\Controllers\LeadController::class, 'convert']);
    Route::post('conversations/{conversation}/leads',[\Modules\CustomerEngagement\Presentation\Http\Controllers\LeadController::class, 'createFromConversation']);

    // ─── Notes ───────────────────────────────────────────────────────────────
    Route::get('conversations/{conversation}/notes',              [\Modules\CustomerEngagement\Presentation\Http\Controllers\NoteController::class, 'index']);
    Route::post('conversations/{conversation}/notes',             [\Modules\CustomerEngagement\Presentation\Http\Controllers\NoteController::class, 'store']);
    Route::delete('conversations/{conversation}/notes/{note}',   [\Modules\CustomerEngagement\Presentation\Http\Controllers\NoteController::class, 'destroy']);

    // ─── Assignment ──────────────────────────────────────────────────────────
    Route::get('conversations/{conversation}/assignments',    [\Modules\CustomerEngagement\Presentation\Http\Controllers\AssignmentController::class, 'history']);
    Route::post('conversations/{conversation}/assign',        [\Modules\CustomerEngagement\Presentation\Http\Controllers\AssignmentController::class, 'assign']);
    Route::post('conversations/{conversation}/unassign',      [\Modules\CustomerEngagement\Presentation\Http\Controllers\AssignmentController::class, 'unassign']);
    Route::post('conversations/{conversation}/round-robin',   [\Modules\CustomerEngagement\Presentation\Http\Controllers\AssignmentController::class, 'roundRobin']);

    // ─── SLA ─────────────────────────────────────────────────────────────────
    Route::get('sla/policies',                         [\Modules\CustomerEngagement\Presentation\Http\Controllers\SlaController::class, 'policies']);
    Route::post('sla/policies',                        [\Modules\CustomerEngagement\Presentation\Http\Controllers\SlaController::class, 'storePolicy']);
    Route::patch('sla/policies/{slaPolicy}',           [\Modules\CustomerEngagement\Presentation\Http\Controllers\SlaController::class, 'updatePolicy']);
    Route::get('conversations/{conversation}/sla',     [\Modules\CustomerEngagement\Presentation\Http\Controllers\SlaController::class, 'violations']);
    Route::get('sla/compliance',                       [\Modules\CustomerEngagement\Presentation\Http\Controllers\SlaController::class, 'complianceStats']);
    Route::post('sla/check-breaches',                  [\Modules\CustomerEngagement\Presentation\Http\Controllers\SlaController::class, 'checkBreaches']);
});

/*
|--------------------------------------------------------------------------
| Marketing Automation Platform
| Prefix: marketing/automation
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('marketing/automation')->group(function (): void {
    // ─── Dashboard ────────────────────────────────────────────────────────────
    Route::get('dashboard',                         [\Modules\Marketing\Automation\Presentation\Http\Controllers\AutomationDashboardController::class, 'index']);

    // ─── Workflows ────────────────────────────────────────────────────────────
    Route::get('kpis',                              [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'kpis']);
    Route::get('workflows',                         [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'index']);
    Route::post('workflows',                        [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'store']);
    Route::get('workflows/{workflow}',              [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'show']);
    Route::patch('workflows/{workflow}',            [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'update']);
    Route::delete('workflows/{workflow}',           [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'destroy']);
    Route::post('workflows/{workflow}/duplicate',   [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'duplicate']);
    Route::post('workflows/{workflow}/activate',    [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'activate']);
    Route::post('workflows/{workflow}/pause',       [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'pause']);
    Route::post('workflows/{workflow}/archive',     [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowController::class, 'archive']);

    // ─── Canvas (nodes_graph) ─────────────────────────────────────────────────
    Route::put('workflows/{workflow}/canvas',       [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowNodeController::class, 'update']);

    // ─── Versions ─────────────────────────────────────────────────────────────
    Route::get('workflows/{workflow}/versions',                          [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowVersionController::class, 'index']);
    Route::get('workflows/{workflow}/versions/compare/{versionA}/{versionB}', [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowVersionController::class, 'compare']);
    Route::post('workflows/{workflow}/versions/{version}/restore',       [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowVersionController::class, 'restore']);

    // ─── Executions ───────────────────────────────────────────────────────────
    Route::get('workflows/{workflow}/executions',                        [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowExecutionController::class, 'index']);
    Route::get('workflows/{workflow}/executions/stats',                  [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowExecutionController::class, 'stats']);
    Route::get('workflows/{workflow}/executions/{execution}',            [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowExecutionController::class, 'show']);
    Route::post('workflows/{workflow}/executions/{execution}/cancel',    [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowExecutionController::class, 'cancel']);
    Route::post('workflows/{workflow}/executions/{execution}/retry',     [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowExecutionController::class, 'retry']);

    // ─── Manual Trigger ───────────────────────────────────────────────────────
    Route::post('workflows/{workflow}/trigger',     [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowTriggerController::class, 'trigger']);

    // ─── Simulation ───────────────────────────────────────────────────────────
    Route::post('workflows/{workflow}/simulate',    [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowSimulatorController::class, 'simulate']);

    // ─── Templates ────────────────────────────────────────────────────────────
    Route::get('templates',                         [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowTemplateController::class, 'index']);
    Route::post('templates',                        [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowTemplateController::class, 'store']);
    Route::get('templates/{template}',              [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowTemplateController::class, 'show']);
    Route::put('templates/{template}',              [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowTemplateController::class, 'update']);
    Route::delete('templates/{template}',           [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowTemplateController::class, 'destroy']);
    Route::post('templates/{template}/create-workflow', [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowTemplateController::class, 'createWorkflow']);

    // ─── Audience Segments ────────────────────────────────────────────────────
    Route::get('segments',                          [\Modules\Marketing\Automation\Presentation\Http\Controllers\AudienceSegmentController::class, 'index']);
    Route::post('segments',                         [\Modules\Marketing\Automation\Presentation\Http\Controllers\AudienceSegmentController::class, 'store']);
    Route::get('segments/{segment}',                [\Modules\Marketing\Automation\Presentation\Http\Controllers\AudienceSegmentController::class, 'show']);
    Route::put('segments/{segment}',                [\Modules\Marketing\Automation\Presentation\Http\Controllers\AudienceSegmentController::class, 'update']);
    Route::delete('segments/{segment}',             [\Modules\Marketing\Automation\Presentation\Http\Controllers\AudienceSegmentController::class, 'destroy']);
    Route::post('segments/{segment}/recalculate',   [\Modules\Marketing\Automation\Presentation\Http\Controllers\AudienceSegmentController::class, 'recalculate']);
    Route::get('segments/{segment}/memberships',    [\Modules\Marketing\Automation\Presentation\Http\Controllers\AudienceSegmentController::class, 'memberships']);

    // ─── Governance Policies ──────────────────────────────────────────────────
    Route::get('governance',                        [\Modules\Marketing\Automation\Presentation\Http\Controllers\AutomationGovernanceController::class, 'index']);
    Route::post('governance',                       [\Modules\Marketing\Automation\Presentation\Http\Controllers\AutomationGovernanceController::class, 'store']);
    Route::get('governance/{policy}',               [\Modules\Marketing\Automation\Presentation\Http\Controllers\AutomationGovernanceController::class, 'show']);
    Route::put('governance/{policy}',               [\Modules\Marketing\Automation\Presentation\Http\Controllers\AutomationGovernanceController::class, 'update']);
    Route::delete('governance/{policy}',            [\Modules\Marketing\Automation\Presentation\Http\Controllers\AutomationGovernanceController::class, 'destroy']);
});

// Public webhook endpoint (no auth — rate-limited)
Route::middleware(['throttle:30,1'])->prefix('marketing/automation')->group(function (): void {
    Route::post('webhook/{workflow}',               [\Modules\Marketing\Automation\Presentation\Http\Controllers\WorkflowTriggerController::class, 'webhook']);
});

/*
|--------------------------------------------------------------------------
| Omnichannel Commerce (MKT-007) — WhatsApp / Messenger / Instagram Direct
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('omnichannel')->group(function (): void {
    // ─── Channel Providers ────────────────────────────────────────────────────
    Route::get('providers',                          [\Modules\CustomerEngagement\Presentation\Http\Controllers\ChannelProviderController::class, 'index']);
    Route::post('providers',                         [\Modules\CustomerEngagement\Presentation\Http\Controllers\ChannelProviderController::class, 'store']);
    Route::get('providers/{channelProvider}',        [\Modules\CustomerEngagement\Presentation\Http\Controllers\ChannelProviderController::class, 'show']);
    Route::patch('providers/{channelProvider}',      [\Modules\CustomerEngagement\Presentation\Http\Controllers\ChannelProviderController::class, 'update']);
    Route::delete('providers/{channelProvider}',     [\Modules\CustomerEngagement\Presentation\Http\Controllers\ChannelProviderController::class, 'destroy']);
    Route::post('providers/{channelProvider}/activate', [\Modules\CustomerEngagement\Presentation\Http\Controllers\ChannelProviderController::class, 'activate']);

    // ─── Outbound Messages ────────────────────────────────────────────────────
    Route::post('conversations/{conversation}/send',          [\Modules\CustomerEngagement\Presentation\Http\Controllers\OutboundMessageController::class, 'send']);
    Route::post('conversations/{conversation}/macros/{macro}',[\Modules\CustomerEngagement\Presentation\Http\Controllers\OutboundMessageController::class, 'applyMacro']);

    // ─── Macros ───────────────────────────────────────────────────────────────
    Route::get('macros',                [\Modules\CustomerEngagement\Presentation\Http\Controllers\MacroController::class, 'index']);
    Route::post('macros',               [\Modules\CustomerEngagement\Presentation\Http\Controllers\MacroController::class, 'store']);
    Route::get('macros/{macro}',        [\Modules\CustomerEngagement\Presentation\Http\Controllers\MacroController::class, 'show']);
    Route::patch('macros/{macro}',      [\Modules\CustomerEngagement\Presentation\Http\Controllers\MacroController::class, 'update']);
    Route::delete('macros/{macro}',     [\Modules\CustomerEngagement\Presentation\Http\Controllers\MacroController::class, 'destroy']);

    // ─── Routing Rules ────────────────────────────────────────────────────────
    Route::get('routing-rules',                      [\Modules\CustomerEngagement\Presentation\Http\Controllers\RoutingController::class, 'index']);
    Route::post('routing-rules',                     [\Modules\CustomerEngagement\Presentation\Http\Controllers\RoutingController::class, 'store']);
    Route::get('routing-rules/{rule}',               [\Modules\CustomerEngagement\Presentation\Http\Controllers\RoutingController::class, 'show']);
    Route::patch('routing-rules/{rule}',             [\Modules\CustomerEngagement\Presentation\Http\Controllers\RoutingController::class, 'update']);
    Route::delete('routing-rules/{rule}',            [\Modules\CustomerEngagement\Presentation\Http\Controllers\RoutingController::class, 'destroy']);
    Route::post('conversations/{conversation}/auto-route', [\Modules\CustomerEngagement\Presentation\Http\Controllers\RoutingController::class, 'applyToConversation']);

    // ─── Attribution ──────────────────────────────────────────────────────────
    Route::get('conversations/{conversation}/attribution',    [\Modules\CustomerEngagement\Presentation\Http\Controllers\AttributionController::class, 'show']);
    Route::post('conversations/{conversation}/attribution',   [\Modules\CustomerEngagement\Presentation\Http\Controllers\AttributionController::class, 'capture']);

    // ─── Commerce (Order Wizard, Linked Entities) ─────────────────────────────
    Route::get('conversations/{conversation}/entities',       [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationCommerceController::class, 'linkedEntities']);
    Route::post('conversations/{conversation}/prepare-order', [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationCommerceController::class, 'prepareOrder']);
    Route::post('conversations/{conversation}/link-entity',   [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationCommerceController::class, 'linkOrder']);
    Route::get('commerce/kpis',                               [\Modules\CustomerEngagement\Presentation\Http\Controllers\ConversationCommerceController::class, 'kpis']);

    // ─── Product Selector ─────────────────────────────────────────────────────
    Route::get('products/search',       [\Modules\CustomerEngagement\Presentation\Http\Controllers\ProductSelectorController::class, 'search']);
    Route::get('products/{productId}',  [\Modules\CustomerEngagement\Presentation\Http\Controllers\ProductSelectorController::class, 'show']);
});

// ─── Omnichannel Webhooks (PUBLIC — provider-to-ECOS, throttled) ─────────────
Route::middleware(['throttle:100,1'])->prefix('omnichannel/webhook')->group(function (): void {
    // GET = Meta hub.challenge verification; POST = inbound messages + status updates
    Route::get('{channelProviderId}',  [\Modules\CustomerEngagement\Presentation\Http\Controllers\WebhookController::class, 'verify']);
    Route::post('{channelProviderId}', [\Modules\CustomerEngagement\Presentation\Http\Controllers\WebhookController::class, 'receive']);
});

/*
|--------------------------------------------------------------------------
| Webhooks — WooCommerce (public, no auth)
|--------------------------------------------------------------------------
*/
Route::middleware(['throttle:60,1'])->group(function (): void {
    Route::post('webhooks/woocommerce/{channel}/orders',    [WooCommerceWebhookController::class, 'handleOrder']);
    Route::post('webhooks/woocommerce/{channel}/products',  [WooCommerceWebhookController::class, 'handleProduct']);
    Route::post('webhooks/woocommerce/{channel}/customers', [WooCommerceWebhookController::class, 'handleCustomer']);
});
