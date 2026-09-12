<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-WOOCOMMERCE-WOO-01-REFUND-FINANCE-INTEGRATION-043.
 *
 * ┌─ WHY THIS EXISTS ───────────────────────────────────────────────────────┐
 * │ (company_id, source_type, source_id) on finance_customer_invoices was an  │
 * │ index only (2026_09_02_200000), not a constraint — safe for its original  │
 * │ single caller (CommercialAccountingService::recognizeRevenue(), one       │
 * │ 'order' document per order, guarded by an application-level existence     │
 * │ check). A second caller now exists (WooRefundApplicationService, one      │
 * │ 'woo_refund' credit note per Woo refund id) where two webhook deliveries   │
 * │ racing the same application-level check could otherwise both pass it and  │
 * │ post two credit notes for one refund. This upgrades the existing index to │
 * │ a real DB-enforced uniqueness guarantee — no new column, no new table, no  │
 * │ second ledger. NULLs remain unconstrained against each other (standard    │
 * │ multi-column unique-index semantics), so every pre-existing row with no    │
 * │ source_type/source_id is untouched.                                       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_customer_invoices', function (Blueprint $table): void {
            $table->dropIndex('finance_ci_source_idx');
            $table->unique(['company_id', 'source_type', 'source_id'], 'finance_ci_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_customer_invoices', function (Blueprint $table): void {
            $table->dropUnique('finance_ci_source_unique');
            $table->index(['company_id', 'source_type', 'source_id'], 'finance_ci_source_idx');
        });
    }
};
