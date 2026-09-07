<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11 + §12.
 *
 * Role lifecycle needs a state column. §11 requires an explicit Archive action and §12
 * requires duplicate/legacy roles to be ARCHIVED rather than deleted ("Do NOT delete
 * blindly", "preserve audit/history"). The `roles` table carried only name/slug/
 * description/is_system, so there was nowhere to record that a role has been retired —
 * archival would have meant deletion, which is exactly what the task forbids.
 *
 * Three nullable, additive columns. Every existing row keeps its current meaning
 * (archived_at = null → active), so nothing about current authorization changes: no query
 * in the Authorization Platform filters on these columns today, and a role that is
 * archived still resolves normally for any user who still holds it. Archival is a
 * MANAGEMENT state — it withdraws a role from the assignable catalogue, it does not
 * silently revoke access from its current holders. §19's "never silently increase access"
 * has a mirror obligation, and this is it.
 *
 * `archived_reason` and `archived_by` exist so the rationalization pass can record WHY a
 * legacy role was retired and WHO retired it, alongside the audit-service entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index()->after('is_system');
            }
            if (! Schema::hasColumn('roles', 'archived_reason')) {
                $table->string('archived_reason', 512)->nullable()->after('archived_at');
            }
            if (! Schema::hasColumn('roles', 'archived_by')) {
                $table->unsignedBigInteger('archived_by')->nullable()->after('archived_reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            foreach (['archived_by', 'archived_reason', 'archived_at'] as $column) {
                if (Schema::hasColumn('roles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
