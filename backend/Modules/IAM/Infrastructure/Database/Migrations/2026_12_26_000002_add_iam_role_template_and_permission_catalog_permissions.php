<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002.
 *
 * The Role Template API (§14) and the read-only Permission catalog API (§13) are both
 * required scope for this task, but no `iam.role-templates.*` or `iam.permissions.*`
 * permission existed to gate them — the Task 1 architecture report flagged both as
 * "NOT VERIFIED — likely needs adding" (§16). This is additive under the EXISTING `iam`
 * domain (same domain as iam.users/iam.roles already in the catalog) — not a new
 * permission domain, consistent with D4's "do not invent a new permission domain"
 * constraint, which was scoped to iam.users specifically.
 *
 * Same idempotent insert-then-grant pattern as the D4 migration.
 */
return new class extends Migration
{
    /** [name, resource, action, description] */
    private const PERMISSIONS = [
        ['iam.role-templates.view', 'role-templates', 'view', 'List and inspect Role Templates, versions and comparisons'],
        ['iam.role-templates.create', 'role-templates', 'create', 'Create a custom Role Template or clone a system template'],
        ['iam.role-templates.update', 'role-templates', 'update', 'Edit a custom Role Template definition, or archive it'],
        ['iam.role-templates.delete', 'role-templates', 'delete', 'Hard-delete a custom Role Template that has never been assigned'],
        ['iam.permissions.view', 'permissions', 'view', 'View the permission catalog and grouping metadata'],
    ];

    private const GRANT_TO_ROLES = ['company-admin'];

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
                'module' => 'iam',
                'resource' => $resource,
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
