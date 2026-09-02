<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002, D4 (CTO-ratified).
 *
 * STOP 4 (TASK-IAM-HTTP-SURFACE-001-CONTRACT-AUDIT) found 6 permissions missing for
 * lifecycle/role-assignment operations that already exist as working domain code
 * (UserLifecycleService::archive/restore/deactivate/lock/unlock,
 * UserRoleAssignmentService::removeTemplate) but have never been HTTP-gated. Adds
 * exactly the 6 CTO-approved tokens under the existing `iam.users` domain — no new
 * permission domain, matching every other existing `iam.users.*` token's naming.
 *
 * Additive only, mirrors the pattern in 2026_12_24_000000_restore_logistics_two_segment_permissions.php:
 * insert the definition if missing (guarded on the unique name), then grant to company-admin
 * (which already holds every other iam.users.* token, per config/permissions.php's
 * role_permissions.company-admin block).
 *
 * NOT run against DEV by this task (DEV AUTHORITY: NONE, second-device implementation only).
 */
return new class extends Migration
{
    /** [name, action, description] */
    private const PERMISSIONS = [
        ['iam.users.archive', 'archive', 'Archive a user account (ARCHIVED lifecycle state)'],
        ['iam.users.restore', 'restore', 'Restore an archived or deleted user account to ACTIVE'],
        ['iam.users.deactivate', 'deactivate', 'Deactivate a user account (INACTIVE lifecycle state)'],
        ['iam.users.lock', 'lock', 'Lock a user account (LOCKED lifecycle state)'],
        ['iam.users.unlock', 'unlock', 'Unlock a user account back to ACTIVE'],
        ['iam.users.revoke-role', 'revoke-role', 'Revoke a role template assignment from a user'],
    ];

    private const GRANT_TO_ROLES = ['company-admin'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::PERMISSIONS as [$name, $action, $description]) {
            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => 'iam',
                'resource' => 'users',
                'action' => $action,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 0))
            ->pluck('id');

        foreach (self::GRANT_TO_ROLES as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if ($roleId === null) {
                continue;
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
    }

    /**
     * Reverses the grants only, matching the established precedent (restore_logistics_...):
     * an extra permission row is inert; deleting the definitions would strip them from any
     * environment/role that came to hold them through a path other than this migration.
     */
    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $ids = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 0))
            ->pluck('id');

        $roleIds = DB::table('roles')->whereIn('slug', self::GRANT_TO_ROLES)->pluck('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $ids)
            ->delete();
    }
};
