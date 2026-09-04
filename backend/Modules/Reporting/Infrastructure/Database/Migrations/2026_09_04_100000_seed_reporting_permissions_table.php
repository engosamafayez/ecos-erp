<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reporting Platform Foundation — TASK-ECOS-REPORTING-PLATFORM-FOUNDATION-002.
 *
 * The 10 category-level Reporting permissions (ADR-045 Decision 5 / ENTERPRISE-REPORTING-
 * PLATFORM.md §8): Option B ("category-level reporting permissions") was the adopted IAM
 * model — one global reports.view was rejected as too coarse, source-domain view
 * permissions alone were rejected for conflating "can operate AP/AR" with "can view the AR
 * Aging report". No new AuthorizationGateway, no new middleware, no new engine — these are
 * plain rows in the existing `permissions` table, gated with the existing `permission:`
 * route middleware, exactly like every other module's permissions.
 *
 * Deliberately NOT auto-granted to any role here (the same pattern already used by
 * Finance's own seed_finance_expense_permissions_table.php / seed_finance_cost_allocation_
 * permissions_table.php) — which roles should hold Reporting access is a product/business
 * decision for a later task, not an architecture-foundation one.
 *
 * Written, not run: this task's DEV authority is NONE ("DEV: DO NOT TOUCH"). A future,
 * properly DEV-authorized task applies this the same way TASK-ECOS-COMBINED-RUNTIME-DEV-
 * GATE-003 applied Finance/IAM's own deferred migrations.
 */
return new class extends Migration
{
    /** @return list<string> */
    private function categories(): array
    {
        return [
            'executive', 'sales', 'customers', 'products', 'inventory',
            'procurement', 'preparation', 'distribution', 'drivers', 'finance',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach ($this->categories() as $category) {
            $name = "reports.{$category}.view";

            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => 'reports',
                'resource' => $category,
                'action' => 'view',
                'description' => "View the {$category} Reports category",
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $names = array_map(static fn (string $category): string => "reports.{$category}.view", $this->categories());

        DB::table('permissions')->whereIn('name', $names)->delete();
    }
};
