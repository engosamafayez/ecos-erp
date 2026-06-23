<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Inventory\Products\Infrastructure\Database\Seeders\ProductSeeder;
use Modules\MasterData\Categories\Infrastructure\Database\Seeders\CategorySeeder;
use Modules\MasterData\Units\Infrastructure\Database\Seeders\UnitSeeder;
use Modules\MasterData\Warehouses\Infrastructure\Database\Seeders\WarehouseSeeder;
use Modules\Organization\Branches\Infrastructure\Database\Seeders\BranchSeeder;
use Modules\Organization\Companies\Infrastructure\Database\Seeders\CompanySeeder;
use Modules\Purchasing\Suppliers\Infrastructure\Database\Seeders\SupplierSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Default administrator account (IAM-001).
        User::updateOrCreate(
            ['email' => 'admin@ecos.local'],
            [
                'name' => 'ECOS Administrator',
                'password' => 'Admin@123456',
            ],
        );

        // Organization module (ORG-001 companies, ORG-002 branches).
        $this->call(CompanySeeder::class);
        $this->call(BranchSeeder::class);

        // Master Data module (MD-001).
        $this->call(UnitSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(WarehouseSeeder::class);

        // Inventory module (PROD-001).
        $this->call(ProductSeeder::class);

        // Purchasing module (PUR-001).
        $this->call(SupplierSeeder::class);
    }
}
