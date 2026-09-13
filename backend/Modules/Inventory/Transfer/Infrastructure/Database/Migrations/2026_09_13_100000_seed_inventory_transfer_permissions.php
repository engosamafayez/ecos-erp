<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-ECOS-V1.1-OPS-01-IMPLEMENTATION-044A-R1 — register the Inventory
 * Transfer permissions.
 *
 * The HTTP route added by this task gates on `inventory.transfers.create`,
 * but that permission name never existed as a row — `PermissionService::
 * userHasPermission()` returns false for an undefined name, so the route
 * would 403 for every non-system-role user. config/permissions.php also
 * carries these names now, so a fresh `db:seed` reproduces them; this
 * migration covers environments whose roles already exist. Idempotent on
 * both halves — `permissions.name` is unique and `role_permissions` is
 * unique on (role_id, permission_id) — mirroring
 * 2026_08_20_100000_seed_loading_os_permissions.php verbatim in shape.
 */
return new class extends Migration
{
    /**
     * [name, resource, action, description]
     *
     * @var list<array{0:string,1:string,2:string,3:string}>
     */
    private const PERMISSIONS = [
        ['inventory.transfers.view', 'transfers', 'view', 'View warehouse-to-warehouse stock transfers'],
        ['inventory.transfers.create', 'transfers', 'create', 'Create a warehouse-to-warehouse stock transfer'],
    ];

    /** Roles receiving the full set, per config/permissions.php's role_permissions grants. */
    private const FULL_SET_ROLES = [
        'company-admin',
        'warehouse-manager',
        'inventory-operator',
        'warehouse-operator',
        'inventory-controller',
    ];

    /** Roles receiving only names ending in `.view`. */
    private const VIEW_ONLY_ROLES = ['viewer'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        // ── 1. Create any missing permission (idempotent on the unique name) ──────
        foreach (self::PERMISSIONS as [$name, $resource, $action, $description]) {
            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => 'inventory',
                'resource' => $resource,
                'action' => $action,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── 2. Apply the authorised grants (idempotent; no-op if a role is absent) ─
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 0))
            ->pluck('id', 'name');

        foreach (self::FULL_SET_ROLES as $slug) {
            $this->grant($slug, $permissionIds->all(), $now);
        }

        foreach (self::VIEW_ONLY_ROLES as $slug) {
            $viewOnly = $permissionIds
                ->filter(static fn ($id, string $name): bool => str_ends_with($name, '.view'))
                ->all();

            $this->grant($slug, $viewOnly, $now);
        }
    }

    /**
     * @param  array<string, string>  $permissionIds  name => id
     */
    private function grant(string $slug, array $permissionIds, mixed $now): void
    {
        $roleId = DB::table('roles')->where('slug', $slug)->value('id');

        if ($roleId === null) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'effect' => 'allow',
                'conditions' => null,
                'expires_at' => null,
                'created_at' => $now,
            ]);
        }
    }

    /**
     * Reverses the GRANTS only — the definitions are deliberately left in place.
     * A lingering permission row is inert; a missing one silently 403s the route.
     */
    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $ids = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 0))
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('slug', array_merge(self::FULL_SET_ROLES, self::VIEW_ONLY_ROLES))
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $ids)
            ->delete();
    }
};
