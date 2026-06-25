<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate: password is only set when the account is created for the
        // first time. Re-running this seeder never overwrites an existing password.
        User::firstOrCreate(
            ['email' => 'admin@ecos.local'],
            [
                'name'     => 'Administrator',
                'password' => 'Admin@123456',
            ]
        );
    }
}
