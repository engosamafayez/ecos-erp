<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Organization\Branches\Infrastructure\Database\Seeders\BranchSeeder;
use Modules\Organization\Companies\Infrastructure\Database\Seeders\CompanySeeder;

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
    }
}
