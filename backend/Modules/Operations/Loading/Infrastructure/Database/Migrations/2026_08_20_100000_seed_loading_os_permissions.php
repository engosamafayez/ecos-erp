<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-SHIPPING-DRIVER-CLOSURE-001 §G8 — register the Loading OS permissions.
 *
 * ┌─ THE GAP ───────────────────────────────────────────────────────────────┐
 * │ LoadingSessionPolicy, AllocationRecordPolicy and VehicleAssignmentPolicy  │
 * │ authorise against `loading.session.*`, `loading.allocation.*`,            │
 * │ `loading.vehicle.assign` and (new) `loading.driver.operate`, but NONE of  │
 * │ those permission rows existed. PermissionService::userHasPermission()     │
 * │ returns false for an undefined name, so the entire Loading OS + driver    │
 * │ runtime was reachable ONLY by a system-role user (Gate::before bypass).   │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * This migration creates the rows and applies the authorised grants, mirroring
 * 2026_12_24_000000_restore_logistics_two_segment_permissions.php verbatim in
 * shape. config/permissions.php also carries these names now, so a fresh
 * `db:seed` reproduces them; this migration covers environments whose roles
 * already exist (so the grant lands without a re-seed). Idempotent on both
 * halves — `permissions.name` is unique and `role_permissions` is unique on
 * (role_id, permission_id).
 *
 * A Driver never receives these here (Section 15): only company-admin (full set)
 * and viewer (`.view` subset) are granted. `loading.driver.operate` is granted
 * to company-admin as an operational permission, NOT to any driver role — the
 * driver identity is resolved per-request in the driver runtime, not via a role.
 */
return new class extends Migration
{
    /**
     * [name, resource, action, description]
     *
     * @var list<array{0:string,1:string,2:string,3:string}>
     */
    private const PERMISSIONS = [
        ['loading.session.view', 'session', 'view', 'View loading sessions, vehicles and allocations'],
        ['loading.session.create', 'session', 'create', 'Create loading sessions'],
        ['loading.session.operate', 'session', 'operate', 'Operate a loading session (load products, record deliveries)'],
        ['loading.session.cancel', 'session', 'cancel', 'Cancel a loading session'],
        ['loading.session.dispatch', 'session', 'dispatch', 'Dispatch a loaded vehicle'],
        ['loading.vehicle.assign', 'vehicle', 'assign', 'Assign a vehicle to a loading session'],
        ['loading.allocation.view', 'allocation', 'view', 'View allocation records for a loading session'],
        ['loading.allocation.manage', 'allocation', 'manage', 'Run and manage product allocation'],
        ['loading.allocation.override', 'allocation', 'override', 'Override an allocation decision'],
        ['loading.driver.operate', 'driver', 'operate', 'Operate the driver runtime (own assigned trips only)'],
    ];

    /** Roles receiving the full set, per the authorised RBAC decision. */
    private const FULL_SET_ROLES = ['company-admin'];

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
                'module' => 'loading',
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
     * A lingering permission row is inert; a missing one silently 403s the module.
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
