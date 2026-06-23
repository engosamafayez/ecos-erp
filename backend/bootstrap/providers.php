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
];
