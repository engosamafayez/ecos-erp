<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-OPERATIONAL-READ-MODEL-007.
 *
 * `customers.code` was created globally unique (2026_06_23_160000), and
 * 2026_07_08_910001 later added `company_id` without ever revisiting that
 * constraint — so today two different companies cannot use the same customer
 * code, unlike every sibling *CodeGeneratorService entity (brands,
 * business_accounts, teams), which are all uniqued on (company_id, code).
 * Backend-generated codes (CustomerCodeGeneratorService, this same task) rely
 * on the composite constraint being in place — a per-company sequential
 * counter is only collision-safe if uniqueness is scoped the same way.
 *
 * No data backfill/migration is performed here (explicitly out of this task's
 * scope) — this only widens the constraint; it cannot itself create a
 * collision, since a global-unique set is trivially also composite-unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->unique(['company_id', 'code'], 'customers_company_id_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_company_id_code_unique');
            $table->unique('code');
        });
    }
};
