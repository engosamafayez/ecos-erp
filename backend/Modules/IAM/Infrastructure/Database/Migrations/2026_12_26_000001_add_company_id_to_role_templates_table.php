<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002, D11 (CTO-ratified).
 *
 * Custom Role Templates are tenant/company scoped; system templates (is_system=true) remain
 * global/shared "official ECOS job profiles" (ADR-039) and this column stays null for them.
 * Additive and reversible: nullable, indexed, no existing row's meaning changes — every
 * pre-existing template (all system templates, by definition) is simply company_id = null,
 * identical to its current implicit (global) behavior.
 *
 * NOT run against DEV by this task (DEV AUTHORITY: NONE, second-device implementation only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_templates') || Schema::hasColumn('role_templates', 'company_id')) {
            return;
        }

        Schema::table('role_templates', function (Blueprint $table) {
            $table->uuid('company_id')->nullable()->index()->after('is_system');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_templates') || ! Schema::hasColumn('role_templates', 'company_id')) {
            return;
        }

        Schema::table('role_templates', function (Blueprint $table) {
            $table->dropColumn('company_id');
        });
    }
};
