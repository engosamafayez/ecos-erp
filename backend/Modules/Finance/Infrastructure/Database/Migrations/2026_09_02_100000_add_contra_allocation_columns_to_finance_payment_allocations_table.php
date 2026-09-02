<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-TRANSACTION-SAFETY-FOUNDATION-002.
 *
 * Additive columns supporting append-only contra-allocation: a correction is
 * a NEW row (negative amount) referencing the allocation it reverses, never
 * an edit of the original. PaymentAllocation::booted() already blocks every
 * update/delete on this table unconditionally; this migration does not
 * relax that guard, and nothing it adds is ever written to an existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_payment_allocations', function (Blueprint $table): void {
            $table->foreignId('reverses_allocation_id')->nullable()->after('amount')
                ->constrained('finance_payment_allocations')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable()->after('reverses_allocation_id');

            $table->index('reverses_allocation_id', 'finance_pa_reverses_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_payment_allocations', function (Blueprint $table): void {
            $table->dropIndex('finance_pa_reverses_idx');
            $table->dropConstrainedForeignId('reverses_allocation_id');
            $table->dropColumn('reversal_reason');
        });
    }
};
