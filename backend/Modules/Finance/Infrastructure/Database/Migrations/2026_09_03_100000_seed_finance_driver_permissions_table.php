<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Finance OS — TASK-ECOS-FINANCE-UX-REPORTING-CLOSURE-008.
 * A read-only view over Task 7's DriverLedgerEntry authority needs its own
 * narrow permission — the same justification that minted finance.expense.view
 * / finance.cost_allocation.view in Task 7.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['finance.driver.view', 'driver', 'view', 'View the Driver financial subledger (advances, approved expenses, approved shortages)'],
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

        DB::table('permissions')->whereIn('name', array_column(self::PERMISSIONS, 0))->delete();
    }
};
