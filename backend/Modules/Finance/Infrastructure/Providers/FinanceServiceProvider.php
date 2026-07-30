<?php

declare(strict_types=1);

namespace Modules\Finance\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Ledger\Domain\Services\JournalEngine;
use Modules\Finance\Ledger\Domain\Services\TrialBalanceService;
use Modules\Finance\Posting\Domain\Services\PostingCoordinator;
use Modules\Finance\Posting\Domain\Services\PostingValidator;
use Modules\Finance\Posting\Domain\Strategies\DirectPostingStrategy;
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
