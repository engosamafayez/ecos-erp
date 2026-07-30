<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Finance\Allocation\Domain\Services\AllocationEngine;
use Modules\Finance\Banking\Domain\Services\BankingService;
use Modules\Finance\Banking\Domain\Services\BankReconciliationService;
use Modules\Finance\Cash\Domain\Services\CashService;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Ledger\Domain\Services\JournalEngine;
use Modules\Finance\Ledger\Domain\Services\TrialBalanceService;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;
use Modules\Finance\Payables\Domain\Services\ApAgingService;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Finance\Posting\Domain\Services\PostingCoordinator;
use Modules\Finance\Posting\Domain\Services\PostingValidator;
use Modules\Finance\Posting\Domain\Strategies\DirectPostingStrategy;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Finance\Receivables\Domain\Services\ArAgingService;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;
use Modules\Finance\Shared\Domain\Services\ControlAccountReconciliationService;
use Modules\Finance\Shared\Domain\Services\ControlAccountResolver;
use Modules\Finance\Tax\Domain\Services\TaxService;

/**
 * Finance OS — EPIC F1. Ledger Core & Fiscal Foundation.
 *
 * ┌─ THE FINANCIAL SYSTEM OF RECORD ────────────────────────────────────────┐
 * │ Registers the Journal Engine (the sole GL writer), the Posting Engine    │
 * │ (which requests journals, never writes), the fiscal calendar, the chart  │
 * │ of accounts, tax core, and the trial-balance read model. It integrates   │
 * │ with NO operational module — that is EPIC F3. This is the foundation      │
 * │ every future accounting feature stands on.                               │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ledger — the source of truth and its read model.
        $this->app->singleton(JournalEngine::class);
        $this->app->singleton(ChartOfAccountsService::class);
        $this->app->singleton(TrialBalanceService::class);

        // Fiscal.
        $this->app->singleton(FiscalCalendarService::class);

        // Posting — validator, strategy, and the idempotent coordinator.
        $this->app->singleton(PostingValidator::class);
        $this->app->singleton(DirectPostingStrategy::class);
        $this->app->singleton(PostingCoordinator::class);

        // Tax core.
        $this->app->singleton(TaxService::class);

        // ── EPIC F2 · Subledgers ────────────────────────────────────────────────
        // Shared control-account wiring.
        $this->app->singleton(ControlAccountResolver::class);
        $this->app->singleton(ControlAccountReconciliationService::class);

        // Accounts Receivable.
        $this->app->singleton(AccountsReceivableService::class);
        $this->app->singleton(CustomerLedgerService::class);
        $this->app->singleton(ArAgingService::class);

        // Accounts Payable.
        $this->app->singleton(AccountsPayableService::class);
        $this->app->singleton(SupplierLedgerService::class);
        $this->app->singleton(ApAgingService::class);

        // Allocation Engine (shared AR/AP matching).
        $this->app->singleton(AllocationEngine::class);

        // Cash & Banking.
        $this->app->singleton(CashService::class);
        $this->app->singleton(BankingService::class);
        $this->app->singleton(BankReconciliationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
