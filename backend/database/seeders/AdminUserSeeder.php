<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // updateOrCreate: always restores the canonical dev password so credentials
        // cannot drift between seeder runs, factory calls, or manual DB changes.
        User::updateOrCreate(
            ['email' => 'admin@ecos.local'],
            [
                'name'     => 'Administrator',
                'password' => 'Admin@123456',
            ]
        );
    }
}
