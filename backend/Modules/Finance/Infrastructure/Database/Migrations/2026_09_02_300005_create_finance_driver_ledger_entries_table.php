<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-07.
 *
 * ┌─ A DRIVER FINANCIAL SUBLEDGER, THE SAME SHAPE AS SUPPLIER/CUSTOMER ─────┐
 * │ TASK §10 forbids a mutable "driver balance" field as accounting            │
 * │ authority — the driver's position must derive from canonical ledger        │
 * │ entries, exactly like a supplier's or customer's. This is the append-only, │
 * │ SUM(amount)-is-the-balance subledger every other party already gets, with  │
 * │ driver_id as the same kind of opaque party reference customer_id/          │
 * │ supplier_id already are — no FK into Logistics, so this table never          │
 * │ duplicates driver master data.                                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_driver_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('company_id');
            $table->string('driver_id', 64); // opaque party reference — no FK

            $table->date('entry_date');
            // advance | expense | shortage | settlement | reversal
            $table->string('entry_type', 20);
            $table->decimal('amount', 20, 4); // signed — SUM(amount) is the balance

            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('finance_journal_entries')->nullOnDelete();

            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'driver_id', 'entry_date'], 'finance_dle_driver_idx');
            $table->index(['company_id', 'source_type', 'source_id'], 'finance_dle_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_driver_ledger_entries');
    }
};
