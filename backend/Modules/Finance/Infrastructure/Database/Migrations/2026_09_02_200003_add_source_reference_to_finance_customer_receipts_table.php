<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006.
 *
 * Same generic source_type/source_id reference pair added to
 * finance_customer_invoices by this task — here so a COD-collection receipt
 * can be traced back to its delivery_cod_records row and detected on replay
 * without guessing from the receipt number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_customer_receipts', function (Blueprint $table): void {
            $table->string('source_type', 40)->nullable()->after('description');
            $table->string('source_id', 64)->nullable()->after('source_type');

            $table->index(['company_id', 'source_type', 'source_id'], 'finance_cr_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_customer_receipts', function (Blueprint $table): void {
            $table->dropIndex('finance_cr_source_idx');
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
