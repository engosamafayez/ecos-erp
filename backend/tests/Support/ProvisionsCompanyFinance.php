<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;
use Modules\Finance\Fiscal\Domain\Enums\PeriodStatus;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Shared\Domain\Services\CompanyFinanceProvisioner;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * Opt-in Finance provisioning for the tests that actually post money.
 *
 * WHY THIS IS A TRAIT AND NOT A FACTORY CHANGE. Roughly 134 test files build companies through
 * `Company::factory()`. Provisioning them all — ~100 accounts, ~27 roles and a tax code each —
 * would slow the suite and, worse, silently change the finance-data conditions those unrelated
 * tests run under. Only the suites that post a supplier payable need this, so only they opt in.
 *
 * It delegates to {@see CompanyFinanceProvisioner}, the same class the production
 * `CreateCompanyAction` uses, so a test company is provisioned by exactly the path a real one is —
 * no parallel fixture logic to drift.
 *
 * Company-scoped and idempotent, both inherited from the underlying seeders: calling it twice for
 * one company creates no duplicate account, role or tax code, and one company's data never
 * attaches to another.
 */
trait ProvisionsCompanyFinance
{
    /** Give an existing company the Finance data a payable posting needs. */
    protected function provisionFinance(Company|string $company): void
    {
        $id = $company instanceof Company ? (string) $company->id : $company;

        app(CompanyFinanceProvisioner::class)->provision($id);
        $this->openFiscalYearAround($id);
    }

    /**
     * Open a fiscal calendar covering today, so journals can actually post.
     *
     * This is NOT part of {@see CompanyFinanceProvisioner}, deliberately. Accounts, roles and tax
     * codes are derivable defaults; a fiscal calendar is a business decision (year start, period
     * count) that Finance makes per company, which is why `JournalEngine` fails closed with
     * "Create and open the period first" rather than inventing one. Production onboarding must
     * therefore open a year before a new company posts — the fixture states that same step.
     *
     * The whole year is opened, not just period 1, so a test is never date-fragile: a suite that
     * passes in January must not break because the wall clock reached February.
     */
    protected function openFiscalYearAround(string $companyId, ?Carbon $date = null): void
    {
        $start = ($date ?? Carbon::now())->copy()->startOfYear();

        $year = app(FiscalCalendarService::class)->createYear(
            $companyId,
            'FY-'.$start->year,
            $start,
            $start->copy()->endOfYear(),
        );

        foreach ($year->periods as $period) {
            if ($period->status !== PeriodStatus::Open) {
                app(FiscalCalendarService::class)->openPeriod($period);
            }
        }
    }

    /** Create a company that can post financially. */
    protected function companyWithFinance(array $attributes = []): Company
    {
        $company = Company::factory()->create($attributes);

        $this->provisionFinance($company);

        return $company;
    }
}
