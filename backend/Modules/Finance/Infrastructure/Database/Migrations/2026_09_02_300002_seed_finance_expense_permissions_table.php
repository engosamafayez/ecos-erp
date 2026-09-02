<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Finance OS — TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007.
 *
 * A genuinely new capability (Task 5 found no Expense capture surface
 * anywhere) needs its own authority — the exact reasoning that justified
 * finance.allocation.manage. Segregation of duties mirrors finance.ap.
 * payment.*: create is a distinct authority from approve.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['finance.expense.view', 'expense', 'view', 'View expenses and expense categories'],
        ['finance.expense.category.manage', 'expense', 'category', 'Create and manage expense categories'],
        ['finance.expense.create', 'expense', 'create', 'Maker: create a Finance expense'],
        ['finance.expense.approve', 'expense', 'approve', 'Checker: approve, post, and reverse a Finance expense'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as [$name, $resource, $action, $description]) {
            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => 'finance',
                'resource' => $resource,
                'action' => $action,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 0))
            ->delete();
    }
};
