<?php

declare(strict_types=1);

namespace Modules\Finance\Integration\Application\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Finance\Integration\Application\Services\FinancialIntegrationService;
use Modules\Finance\Integration\Domain\Enums\BusinessEventType;
use Modules\Finance\Integration\Domain\ValueObjects\FinancialEvent;
use Modules\Hr\Compensation\Domain\Events\CompensationApproved;
use Throwable;

/**
 * Finance's subscriber to HR's payroll-approved announcement
 * (TASK-ECOS-FIN-03-PAYROLL-FINANCE-POSTING-CLOSURE-001).
 *
 * ┌─ HR ANNOUNCES · FINANCE POSTS · NEITHER WRITES THE OTHER'S TABLE ───────┐
 * │ CompensationApproved carries only the approved, frozen totals of one       │
 * │ payroll run — nothing here recalculates a salary, a bonus or a commission. │
 * │ It reads the same posted-through-the-generic-bridge path every other        │
 * │ event in BusinessEventType uses (PostingCoordinator → JournalEngine),        │
 * │ never the ledger directly, and never a second payroll ledger of its own.    │
 * │                                                                            │
 * │ The five posted amounts are exactly the terms of HR's own net formula        │
 * │ (net = basic + bonus + commission − advances − approved deductions),         │
 * │ summed across the run's employees — an aggregation for the journal, not a    │
 * │ recalculation of anyone's pay.                                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Queued (async), the same posting path used for every other rule-driven,
 * generic-bridge event (fleet cost, COGS) — payroll approval is not on any
 * caller's critical path waiting for a journal to exist. Idempotent on the
 * run: a redelivered event resolves to the one journal already posted for it.
 *
 * Deliberately does not post a payment — approving payroll recognises what is
 * owed, not that it has been paid. Deliberately does not post an employer
 * contribution/social-insurance leg — the approved fact this listener reads
 * carries no such component to post from.
 */
final class PostPayrollLiabilityOnCompensationApproved
{
    private const SOURCE_MODULE = 'hr.payroll';

    public function __construct(private readonly FinancialIntegrationService $integration) {}

    public function handle(CompensationApproved $event): void
    {
        if ($event->totalGross <= 0.0) {
            return; // nothing approved to recognise — an empty run has no effect
        }

        try {
            $salaries = round(
                array_sum(array_column($event->employees, 'basic_salary'))
                + array_sum(array_column($event->employees, 'bonus_total')),
                4,
            );
            $commission = round(array_sum(array_column($event->employees, 'commission_total')), 4);

            $financialEvent = new FinancialEvent(
                companyId: $event->companyId,
                eventType: BusinessEventType::PayrollApproved,
                sourceModule: self::SOURCE_MODULE,
                entityType: 'payroll_run',
                entityId: $event->payrollRunId,
                amounts: [
                    'salaries' => $salaries,
                    'commission' => $commission,
                    'net_payable' => round($event->totalNet, 4),
                    'deductions' => round($event->totalDeductions, 4),
                    'advances' => round($event->totalAdvances, 4),
                ],
                occurredAt: Carbon::parse($event->approvedAt),
                idempotencyKey: 'payroll_run:'.$event->payrollRunId,
                currency: $event->currency,
                actorId: $event->approvedBy,
                reference: $event->periodCode,
                description: 'Payroll approved — '.$event->periodCode,
            );

            $this->integration->recordAsync($financialEvent);
        } catch (Throwable $e) {
            Log::channel('daily')->error('[PostPayrollLiabilityOnCompensationApproved] Failed to translate/post payroll', [
                'payroll_run_id' => $event->payrollRunId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
