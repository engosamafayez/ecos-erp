<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance OS — TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007, FIN-EXEC-05.
 *
 * ┌─ THE SMALLEST CANONICAL EXPENSE-TYPE → ACCOUNT MAPPING ─────────────────┐
 * │ Task 5 found no Expense capture surface exists anywhere in this          │
 * │ codebase. Rather than force every expense through the generic F3          │
 * │ BusinessEventType/PostingRule catalog (designed for a closed, versioned    │
 * │ enum of cross-module event names — a poor fit for an open-ended,          │
 * │ ops-defined list of expense types), a category is a direct, company-owned  │
 * │ mapping to one existing Chart-of-Accounts expense leaf — the same          │
 * │ indirection principle (name a role, not an account id) at a smaller,       │
 * │ purpose-fit scale. No account is created by this migration; every          │
 * │ category points at an account that must already exist.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('company_id');
            $table->string('name', 120);
            $table->foreignId('expense_account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'name'], 'finance_ec_company_name_unique');
            $table->index(['company_id', 'is_active'], 'finance_ec_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expense_categories');
    }
};
