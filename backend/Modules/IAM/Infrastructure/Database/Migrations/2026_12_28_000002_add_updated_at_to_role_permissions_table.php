<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes a latent bug in the canonical `role_permissions` pivot, surfaced by
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001's rationalization migration —
 * the FIRST real-world caller that ever re-compiles an EXISTING role's grants
 * with a CHANGED pivot attribute (`data_scope`), rather than only inserting
 * brand-new ones.
 *
 * ROOT CAUSE (verified against the installed framework, not guessed): the
 * `role_permissions` table has `created_at` but no `updated_at`
 * (2026_06_29_200001_replace_role_permission_with_role_permissions_table —
 * deliberate at the time). `Modules\IAM\Domain\Models\RolePermission` declares
 * `$timestamps = false` for exactly that reason. But
 * `Illuminate\Database\Eloquent\Relations\Concerns\AsPivot::hasTimestampAttributes()`
 * — invoked every time `BelongsToMany::updateExistingPivotUsingCustomClass()`
 * hydrates a pivot row via `fromRawAttributes()` — checks ONLY for
 * `created_at` in the fetched row and, finding it, forces
 * `$instance->timestamps = true` on that hydrated instance regardless of the
 * model class's own declared value. `save()` then tries to write
 * `updated_at`, which does not exist, and `RoleTemplateCompiler::compile()`
 * fails with `SQLSTATE[42S22]: Column not found: 'role_permissions.updated_at'`
 * for ANY role whose recompile changes an existing grant's pivot data — not
 * only during this task's migration; any future
 * RoleTemplateController::update() → compile() on a template with a data_scope
 * change would hit the identical failure.
 *
 * THE FIX IS SCHEMA-ONLY, NOT A REWRITE OF THE CANONICAL COMPILER: adding the
 * column `role_permissions` was always structurally missing lets the SAME
 * `RoleTemplateCompiler`, the SAME `Role::permissions()` relation, and the
 * SAME `sync()` call continue to be the one and only path that writes grants
 * — exactly what this task's own §11/§14/§15 require ("reuse canonical
 * authorities", "do NOT create a second engine"). Nothing about
 * authorization, grants, or the compiler's validation changes; a column that
 * was implicitly expected by the framework's own pivot machinery now exists.
 *
 * Additive and reversible. Every existing row gets `updated_at = created_at`
 * so no row is left with a nonsensical NULL modification time, and no
 * existing read of this table is affected (nothing selects `updated_at`
 * today).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_permissions') || Schema::hasColumn('role_permissions', 'updated_at')) {
            return;
        }

        Schema::table('role_permissions', function (Blueprint $table): void {
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        DB::table('role_permissions')->whereNull('updated_at')->update([
            'updated_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_permissions') || ! Schema::hasColumn('role_permissions', 'updated_at')) {
            return;
        }

        Schema::table('role_permissions', function (Blueprint $table): void {
            $table->dropColumn('updated_at');
        });
    }
};
