<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-04.
 *
 * finance_cost_centers had no way to trace a cost center back to the
 * operational entity (a Warehouse) it represents, and no way to detect "this
 * warehouse already has a cost center" without a fragile code/name match —
 * the same generic reference pair already used by finance_customer_invoices/
 * finance_customer_receipts/finance_expenses, reused here rather than a
 * fourth convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_cost_centers', function (Blueprint $table): void {
            $table->string('source_type', 40)->nullable()->after('parent_id');
            $table->string('source_id', 64)->nullable()->after('source_type');

            $table->index(['company_id', 'source_type', 'source_id'], 'finance_cc_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_cost_centers', function (Blueprint $table): void {
            $table->dropIndex('finance_cc_source_idx');
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
