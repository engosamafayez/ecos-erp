<?php

declare(strict_types=1);

return [
    App\Providers\AppServiceProvider::class,
    Modules\IAM\Infrastructure\Providers\IamServiceProvider::class,
    Modules\Organization\Companies\Infrastructure\Providers\OrganizationServiceProvider::class,
    Modules\Organization\Branches\Infrastructure\Providers\BranchServiceProvider::class,
    Modules\MasterData\Warehouses\Infrastructure\Providers\WarehouseServiceProvider::class,
    Modules\MasterData\Categories\Infrastructure\Providers\CategoryServiceProvider::class,
    Modules\MasterData\Units\Infrastructure\Providers\UnitServiceProvider::class,
    Modules\Inventory\Products\Infrastructure\Providers\ProductServiceProvider::class,
    Modules\Purchasing\Suppliers\Infrastructure\Providers\SupplierServiceProvider::class,
    Modules\Purchasing\PurchaseOrders\Infrastructure\Providers\PurchaseOrderServiceProvider::class,
    Modules\Purchasing\GoodsReceipts\Infrastructure\Providers\GoodsReceiptServiceProvider::class,
    Modules\Inventory\StockLedger\Infrastructure\Providers\StockLedgerServiceProvider::class,
    Modules\Sales\Customers\Infrastructure\Providers\CustomerServiceProvider::class,
    Modules\Commerce\Channels\Infrastructure\Providers\ChannelServiceProvider::class,
    Modules\Commerce\ProductMappings\Infrastructure\Providers\ProductMappingServiceProvider::class,
    Modules\Commerce\Connectors\Infrastructure\Providers\ConnectorServiceProvider::class,
    Modules\Commerce\ProductImport\Infrastructure\Providers\ProductImportServiceProvider::class,
    Modules\Commerce\Orders\Infrastructure\Providers\OrderServiceProvider::class,
    Modules\Commerce\OrderImport\Infrastructure\Providers\OrderImportServiceProvider::class,
    Modules\Commerce\Fulfillments\Infrastructure\Providers\FulfillmentServiceProvider::class,
    Modules\Commerce\StockSync\Infrastructure\Providers\StockSyncServiceProvider::class,
    Modules\Manufacturing\BillsOfMaterials\Infrastructure\Providers\BomServiceProvider::class,
    Modules\Commerce\Synchronization\Infrastructure\Providers\SynchronizationServiceProvider::class,
];
