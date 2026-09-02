<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-06.
 *
 * ┌─ A DIFFERENT ENGINE FROM AP/AR ALLOCATION, DELIBERATELY ────────────────┐
 * │ Modules\Finance\Allocation\Domain\Services\AllocationEngine (Tasks 2-5)    │
 * │ matches a PAYMENT/RECEIPT to a BILL/INVOICE it settles — subledger cash-   │
 * │ application. This is a completely different concept: redistributing one   │
 * │ already-posted EXPENSE's cost across management dimensions (Brand) for     │
 * │ profitability analysis. Task 5 confirmed no such engine exists; this is    │
 * │ the smallest one, reusing the append-only/contra-row audit principle       │
 * │ (never the AP/AR tables or PaymentAllocation/ReceiptAllocation model).      │
 * │                                                                            │
 * │ NO journal_entry_id column: this table deliberately does NOT create a      │
 * │ second GL posting (see CostAllocationService's own docblock for why —      │
 * │ crediting a distinct "shared cost pool" account for cost already posted    │
 * │ once would double-count it in the P&L). It enriches management dimensions │
 * │ ONLY; the statutory GL entry for the underlying expense is untouched and    │
 * │ remains the sole economic truth.                                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('company_id');

            // What is being allocated — generic, so a future source type (not
            // only finance_expenses) can reuse this engine without a schema
            // change.
            $table->string('source_type', 40);
            $table->string('source_id', 64);
            // Snapshot of the source's total allocatable amount at allocation
            // time — the ceiling this and every sibling allocation is checked
            // against, independent of whether the source row itself later
            // becomes unreadable to this query.
            $table->decimal('source_amount', 20, 4);

            // fixed | percentage
            $table->string('method', 20);
            // The destination dimension — Brand, per Task 5's own FIN-EXEC-06
            // contract ("target brands"). Opaque/unvalidated, like every other
            // profit_center_id in this schema (TASK §8 — Finance is not a
            // master-data owner).
            $table->uuid('destination_profit_center_id');

            $table->decimal('allocated_amount', 20, 4);
            // Populated only when method = percentage; informational, the
            // amount column is what every invariant/read actually uses.
            $table->decimal('percentage', 7, 4)->nullable();

            // Append-only contra pattern — the exact PaymentAllocation/
            // ReceiptAllocation convention, a DIFFERENT table entirely.
            $table->foreignId('reverses_allocation_id')->nullable()
                ->constrained('finance_cost_allocations')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'source_type', 'source_id'], 'finance_ca_source_idx');
            $table->index(['company_id', 'destination_profit_center_id'], 'finance_ca_destination_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_cost_allocations');
    }
};
