<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Infrastructure\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sales\Customers\Domain\Models\Customer;

final class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['code' => 'CUS-001', 'name' => 'Cairo Retail', 'city' => 'Cairo'],
            ['code' => 'CUS-002', 'name' => 'Nile Distribution', 'city' => 'Giza'],
            ['code' => 'CUS-003', 'name' => 'Alex Trading', 'city' => 'Alexandria'],
        ];

        foreach ($customers as $customer) {
            Customer::updateOrCreate(
                ['code' => $customer['code']],
                [
                    'name' => $customer['name'],
                    'country' => 'Egypt',
                    'city' => $customer['city'],
                    'is_active' => true,
                ],
            );
        }
    }
}
