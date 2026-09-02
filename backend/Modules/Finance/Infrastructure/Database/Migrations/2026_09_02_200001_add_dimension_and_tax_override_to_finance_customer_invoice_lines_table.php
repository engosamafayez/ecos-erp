<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006.
 *
 * ┌─ TWO SMALL, INDEPENDENT ADDITIONS ───────────────────────────────────────┐
 * │ profit_center_id: the F1 journal line has carried this dimension,         │
 * │ nullable and unused, since EPIC F1. The subledger line never did. Adding   │
 * │ it here is what lets an AR document line's own dimension reach the GL      │
 * │ line, the same way cost_center_id / branch_id already do below.           │
 * │                                                                            │
 * │ tax_account_id: an integration that already knows its tax AMOUNT (an       │
 * │ order's own tax_total, computed once by the commercial pricing engine)     │
 * │ must not have it re-derived from a finance_tax_codes rate — that could      │
 * │ silently disagree with the figure the customer was actually charged. This  │
 * │ column lets a line name its output-tax account directly; when absent, the  │
 * │ existing tax_code_id → TaxCode::output_account_id path is unchanged.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_customer_invoice_lines', function (Blueprint $table): void {
            $table->uuid('profit_center_id')->nullable()->after('branch_id');
            $table->foreignId('tax_account_id')->nullable()->after('tax_code_id')
                ->constrained('finance_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_customer_invoice_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tax_account_id');
            $table->dropColumn('profit_center_id');
        });
    }
};
