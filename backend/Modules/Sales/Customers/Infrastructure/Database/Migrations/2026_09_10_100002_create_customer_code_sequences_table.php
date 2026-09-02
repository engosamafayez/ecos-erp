<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-TASK-2-REMEDIATION-007-R1.
 *
 * Backs EloquentCustomerRepository::nextCodeNumber() with a dedicated per-company
 * sequence row, replacing the count()+1+lockForUpdate() approach that
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-OPERATIONAL-READ-MODEL-007 shipped and the CTO
 * withheld approval on — that approach was both logically incorrect (row count is not
 * "highest CUST-NNNNNN suffix in use" once any gap, soft-delete, or coexisting
 * legacy/manual code exists) and concurrency-unsafe (InnoDB gap locks over zero matching
 * rows are mutually compatible across transactions, so lockForUpdate() did not actually
 * serialize two concurrent "first customer for this company" requests).
 *
 * Deliberately lean, matching the proven `pos_return_counters` counter-table shape
 * (Modules/POS/Returns/Infrastructure/Database/Migrations/2026_07_01_000019_...): a plain
 * primary-keyed counter row, no surrogate id, no FK, no timestamps — this is an internal
 * bookkeeping table, not a business entity. No FK to `companies` for the same reason
 * `pos_return_counters.terminal_id` carries none.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_code_sequences')) {
            return;
        }

        Schema::create('customer_code_sequences', function (Blueprint $table): void {
            $table->uuid('company_id');
            $table->unsignedInteger('next_number')->default(0);

            $table->primary('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_code_sequences');
    }
};
