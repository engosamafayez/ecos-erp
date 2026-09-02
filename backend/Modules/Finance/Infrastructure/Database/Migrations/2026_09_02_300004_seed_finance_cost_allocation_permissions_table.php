<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Finance OS — TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007.
 * A genuinely new capability (Task 5 confirmed no cost-allocation engine
 * exists) needs its own authority, distinct from finance.allocation.manage
 * (AP/AR payment-to-document matching — a different engine entirely, TASK
 * §19's own explicit instruction not to conflate the two).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['finance.cost_allocation.view', 'cost_allocation', 'view', 'View cost allocations'],
        ['finance.cost_allocation.manage', 'cost_allocation', 'manage', 'Create and reverse cost allocations'],
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
