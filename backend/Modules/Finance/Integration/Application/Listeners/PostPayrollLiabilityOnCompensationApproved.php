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
 * │ salaries + commission always equals the run's total gross (HR's own         │
 * │ gross = basic + bonus + commission, summed across employees) — an           │
 * │ aggregation of an already-frozen fact, not a recalculation of anyone's pay.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ WHY A RUN WITH ADVANCE RECOVERY DOES NOT POST (TASK-ECOS-FIN-03-        ┐
 * │ EMPLOYEE-ADVANCE-ACCOUNTING-INTEGRITY-001)                                │
 * │                                                                            │
 * │ Crediting employee_advance_receivable is only correct if Finance already   │
 * │ carries a debit there for the same advance — recognised when the money      │
 * │ was actually handed out. It never has: HR's own Advance migration says       │
 * │ so directly ("HR records the advance and recovers it from pay. Finance       │
 * │ disburses the money and owns the cash side; nothing here posts an entry.")   │
 * │ and no Finance-side disbursement listener exists for it (unlike Logistics'    │
 * │ driver advances, which DriverFinanceService does post). Crediting the role   │
 * │ anyway would recognise a recovery against a receivable nobody ever debited    │
 * │ — an invalid, unbalanced-in-substance entry that happens to balance in form.  │
 * │                                                                            │
 * │ Folding the advance amount into salaries_payable instead (to keep posting     │
 * │ everything else) was considered and rejected: it would silently overstate     │
 * │ what the run still owes and erase the one thread this whole system relies      │
 * │ on to eventually reconcile the advance at all.                                │
 * │                                                                            │
 * │ So a run with any recovered advance amount posts NOTHING today — logged        │
 * │ clearly, not dead-lettered as a failure, since nothing here is broken; the    │
 * │ missing piece is a Finance-side advance-disbursement authority that does not  │
 * │ exist yet. Runs with no advance recovery are entirely unaffected.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Queued (async), the same posting path used for every other rule-driven,
 * generic-bridge event (fleet cost, COGS) — payroll approval is not on any
 * caller's critical path waiting for a journal to exist. Idempotent on the
 * run: a redelivered event resolves to the one journal already posted for it
 * (or, for a blocked run, is simply evaluated and skipped again).
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

        if ($event->totalAdvances > 0.0) {
            // Not an error: a correctly-handled boundary, not a bug. See the
            // class docblock — there is no Finance-recognised receivable for
            // this amount to clear yet.
            Log::channel('daily')->warning(
                '[PostPayrollLiabilityOnCompensationApproved] Skipped — advance recovery has no Finance-recognised receivable to credit',
                [
                    'payroll_run_id' => $event->payrollRunId,
                    'company_id' => $event->companyId,
                    'period_code' => $event->periodCode,
                    'total_advances' => round($event->totalAdvances, 4),
                ],
            );

            return;
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
