<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * CORE-02 Task 2 §2/§3 — the one permission gating the new central Audit read/search
 * surface (`App\Core\Audit\AuditQueryService` / `AuditLogController`).
 *
 * Standalone migration (the Finance `finance.driver.view` / Go-Live `admin.golive.manage`
 * pattern) rather than editing the shared `config/permissions.php` catalogue's permission
 * *rows* — that file is edited too, but only to grant this already-registered permission to
 * `system-auditor`/`company-admin`, never to invent the row itself there.
 */
return new class extends Migration
{
    private const PERMISSION = ['system.audit.view', 'system', 'audit', 'view', 'View the central audit trail (read-only)'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        [$name, $module, $resource, $action, $description] = self::PERMISSION;

        if (DB::table('permissions')->where('name', $name)->exists()) {
            return;
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

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('name', self::PERMISSION[0])->delete();
    }
};
