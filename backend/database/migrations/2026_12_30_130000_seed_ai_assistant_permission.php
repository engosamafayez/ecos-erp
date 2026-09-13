<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * CORE-03 Task 1 §8 — the ONE entry permission gating whether a user can reach
 * the Resident AI feature at all. It is NOT sufficient on its own to access any
 * business data; every tool call additionally requires that tool's own existing
 * domain permission (enforced in AIToolInvoker), so this migration adds no
 * business-data permission of any kind.
 *
 * Deliberately NOT auto-granted to any role here (the Go-Live migration's own
 * precedent) — an administrator with IAM access grants it explicitly once V1 is
 * ready to enable for a given role.
 */
return new class extends Migration
{
    private const PERMISSION = ['ai.assistant.use', 'ai', 'assistant', 'use', 'Access the Resident AI assistant'];

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
