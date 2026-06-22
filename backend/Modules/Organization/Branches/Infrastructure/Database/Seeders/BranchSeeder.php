<?php

declare(strict_types=1);

namespace Modules\Organization\Branches\Infrastructure\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Organization\Branches\Domain\Models\Branch;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * Seeds sample branches for the ECOS Holding company (ORG-002).
 */
final class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('code', 'ECOS')->first();

        if ($company === null) {
            return;
        }

        Branch::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'CAI-HQ'],
            [
                'name' => 'Cairo HQ',
                'manager_name' => 'Omar Hassan',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'is_head_office' => true,
                'is_active' => true,
            ],
        );

        Branch::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'ALX'],
            [
                'name' => 'Alexandria',
                'manager_name' => 'Mona Adel',
                'city' => 'Alexandria',
                'country' => 'Egypt',
                'is_head_office' => false,
                'is_active' => true,
            ],
        );
    }
}
