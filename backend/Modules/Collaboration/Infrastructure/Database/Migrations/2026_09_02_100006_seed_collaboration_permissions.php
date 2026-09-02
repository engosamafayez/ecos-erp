<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-ECOS-COLLABORATION-CORE-FOUNDATION-002 — CTO Remediation: permission
 * catalog persistence closure.
 *
 * ┌─ THE GAP ───────────────────────────────────────────────────────────────┐
 * │ `collaboration.conversations.create`, `.message_drivers` and             │
 * │ `groups.create` were declared in config/permissions.php's `modules`      │
 * │ catalog, which `RbacSeeder` turns into real `permissions` rows — but      │
 * │ ONLY when `php artisan db:seed` actually runs. Neither `scripts/deploy.sh`│
 * │ nor `docker/php/entrypoint.sh` invoke `db:seed` (or `RbacSeeder`) as part │
 * │ of routine deployment/startup — both treat migrations as opt-in          │
 * │ (`--migrate` / `MIGRATE_ON_START`) and seeding as narrower still          │
 * │ (`SEED_ADMIN_ON_START` only runs `AdminUserSeeder`). An already-running   │
 * │ environment that only ever re-runs migrations would never gain these     │
 * │ permission rows, and `RequirePermissionMiddleware` denies (fail-closed)   │
 * │ on an undefined permission name — every collaboration.* route would 403  │
 * │ for everyone except a system-role user.                                  │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * This is the exact scenario `2026_08_20_100000_seed_loading_os_permissions.php`
 * (Loading OS) already solved, mirrored verbatim in shape: insert the missing
 * `permissions` rows directly, guarded by the unique `name` column, so both a
 * fresh `db:seed` (via config/permissions.php + RbacSeeder) AND an
 * already-running environment (via this migration) reach the same end state.
 *
 * Unlike the Loading migration, this one applies NO role grants — CTO
 * ruling (this remediation, §8): persisting a permission's existence must
 * never double as authorizing anyone to use it. Which roles, if any, get
 * `collaboration.*` by default remains an undecided product/seed-data
 * decision, out of scope here exactly as it was in the original Task 2 report.
 */
return new class extends Migration
{
    /**
     * [name, resource, action, description]
     *
     * @var list<array{0:string,1:string,2:string,3:string}>
     */
    private const PERMISSIONS = [
        ['collaboration.conversations.create', 'conversations', 'create', 'Start a direct or Collaboration Group conversation'],
        ['collaboration.conversations.message_drivers', 'conversations', 'message_drivers', 'Start a direct conversation with a driver (also requires IAM data scope)'],
        ['collaboration.groups.create', 'groups', 'create', 'Create a Collaboration Group'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::PERMISSIONS as [$name, $resource, $action, $description]) {
            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => 'collaboration',
                'resource' => $resource,
                'action' => $action,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // No grants are applied here — see this file's docblock. Contrast with
        // 2026_08_20_100000_seed_loading_os_permissions.php's `grant()` step,
        // which this migration deliberately does not replicate.
    }

    /**
     * Never deletes a permission definition (RbacSeeder's own rule, restated
     * here): a lingering row is inert; removing one that something now
     * depends on silently 403s the module. Since this migration granted
     * nothing, there is nothing for down() to revoke either.
     */
    public function down(): void {}
};
