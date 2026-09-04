<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006.
 *
 * ┌─ WHY THIS EXISTS ───────────────────────────────────────────────────────┐
 * │ finance_customer_invoices had no way to trace a document back to the      │
 * │ commercial order that caused it, and no way to detect "this order already │
 * │ has an invoice" on a replayed Delivered event without guessing from the    │
 * │ invoice number. source_type/source_id is the same generic reference pair  │
 * │ already used by finance_supplier_ledger_entries / finance_customer_ledger_│
 * │ entries — reused here rather than inventing a second convention.          │
 * │ customer_id stays opaque (F2 does not integrate with operational          │
 * │ modules); this pair is the F3-side integration's own bookkeeping.         │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_customer_invoices', function (Blueprint $table): void {
            $table->string('source_type', 40)->nullable()->after('description');
            $table->string('source_id', 64)->nullable()->after('source_type');

            $table->index(['company_id', 'source_type', 'source_id'], 'finance_ci_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_customer_invoices', function (Blueprint $table): void {
            $table->dropIndex('finance_ci_source_idx');
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
