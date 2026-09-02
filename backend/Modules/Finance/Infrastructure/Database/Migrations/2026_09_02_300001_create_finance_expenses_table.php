<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-05.
 *
 * A canonical Finance expense document: maker creates (Draft), a different
 * checker approves (Approved — segregation of duties, the exact
 * SupplierPayment pattern reused since money is leaving the business the
 * same way), then posts (Dr expense_category.expense_account_id, Cr the
 * chosen funding account). source_type/source_id let a future operational
 * domain (or Cost Allocation, this same task) trace back to what caused the
 * expense without Finance duplicating that domain's own record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('company_id');
            $table->foreignId('expense_category_id')->constrained('finance_expense_categories')->restrictOnDelete();

            $table->string('number', 60);
            $table->date('expense_date');
            $table->decimal('amount', 20, 4);
            $table->char('currency', 3)->default('EGP');

            $table->foreignId('funding_account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('finance_journal_entries')->nullOnDelete();

            // draft | approved | posted | void
            $table->string('status', 20)->default('draft');

            // Generic reference pair — the same convention already used by
            // finance_customer_invoices/finance_customer_receipts. Nullable: a
            // manually-entered expense has no operational source at all.
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 64)->nullable();

            // Dimensions — mirrors finance_journal_lines, so a category/cost
            // driver can be attributed before the journal even exists.
            $table->uuid('branch_id')->nullable();
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('finance_cost_centers')->nullOnDelete();
            $table->uuid('profit_center_id')->nullable();

            $table->string('description', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'finance_exp_company_number_unique');
            $table->index(['company_id', 'status'], 'finance_exp_company_status_idx');
            $table->index(['company_id', 'source_type', 'source_id'], 'finance_exp_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
    }
};
