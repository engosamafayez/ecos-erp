<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-...-026 §2 — a single, high-impact permission for the whole Go-Live Preparation surface
 * (preview, destructive reset execution, opening-balance establishment, Go-Live activation).
 * One permission rather than several: every action it gates is part of the same one-time,
 * high-risk operational event, and splitting it would only create the illusion of finer control
 * without a real use case for granting one half without the other.
 *
 * Standalone migration (the Finance `finance.driver.view` pattern) rather than editing the shared
 * `config/permissions.php` catalogue — avoids a merge conflict with any other concurrent lane
 * editing that same shared file, and is exactly the pattern this codebase already uses when a
 * module wants to add a permission from its own migration set.
 *
 * Deliberately NOT auto-granted to any role here. This permission is destructive enough that this
 * task will not guess which role should hold it — an administrator with IAM access grants it
 * explicitly, same as any other permission in this system's own role-management UI.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['admin.golive.manage', 'admin', 'golive', 'manage', 'Manage Pre-Live test-data reset and Go-Live activation (high-impact, destructive)'],
        // Mirrors the existing finance.ap.opening.post permission (Supplier opening balances,
        // TASK-PROC-SUPPLIER-OPENING-BALANCE-001) on the AR side for the new Customer opening
        // balance authority this task adds.
        ['finance.ar.opening.post', 'finance', 'ar_opening', 'post', 'Post a Customer opening receivable balance'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as [$name, $module, $resource, $action, $description]) {
            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => $module,
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
        // (column 0 of each PERMISSIONS row is still the permission name after the module column
        // was inserted at index 1 — array_column(..., 0) is unaffected by that change)
    }
};
