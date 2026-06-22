<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

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
    }
}
