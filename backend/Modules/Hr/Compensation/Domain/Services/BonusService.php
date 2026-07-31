<?php

declare(strict_types=1);

namespace Modules\Hr\Compensation\Domain\Services;

use Illuminate\Support\Carbon;
use Modules\Hr\Compensation\Domain\Enums\ApprovalStatus;
use Modules\Hr\Compensation\Domain\Enums\BonusType;
use Modules\Hr\Compensation\Domain\Exceptions\CompensationException;
use Modules\Hr\Compensation\Domain\Models\Bonus;
use Modules\Hr\Workforce\Domain\Models\Employee;

/** Bonuses — awarded, approved, and only then included in pay. */
final class BonusService
{
    public function award(Employee $employee, array $data, ?int $actorId = null): Bonus
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw CompensationException::amountMustBePositive();
        }

        $type = ($data['type'] ?? null) instanceof BonusType
            ? $data['type']
            : (BonusType::tryFrom((string) ($data['type'] ?? '')) ?? BonusType::Discretionary);

        return Bonus::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'payroll_period_id' => $data['payroll_period_id'] ?? null,
            'type' => $type->value,
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'EGP',
            'reason' => $data['reason'],
            'awarded_on' => $data['awarded_on'] ?? Carbon::now()->toDateString(),
            'status' => ApprovalStatus::Pending->value,
            'source' => $data['source'] ?? 'manual',
            'recommendation_id' => $data['recommendation_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actorId,
        ]);
    }

    public function approve(Bonus $bonus, ?int $approverId = null): Bonus
    {
        $this->assertTransition($bonus, ApprovalStatus::Approved);

        $bonus->update([
            'status' => ApprovalStatus::Approved->value,
            'approved_by' => $approverId,
            'approved_at' => Carbon::now(),
        ]);

        return $bonus->refresh();
    }

    public function reject(Bonus $bonus, ?int $approverId = null): Bonus
    {
        $this->assertTransition($bonus, ApprovalStatus::Rejected);

        $bonus->update([
            'status' => ApprovalStatus::Rejected->value,
            'approved_by' => $approverId,
            'approved_at' => Carbon::now(),
        ]);

        return $bonus->refresh();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Bonus> */
    public function approvedFor(Employee $employee, ?string $periodId, string $from, string $to)
    {
        return Bonus::query()
            ->where('employee_id', $employee->id)
            ->approved()
            ->where(function ($q) use ($periodId, $from, $to): void {
                $q->whereBetween('awarded_on', [$from, $to]);
                if ($periodId !== null) {
                    $q->orWhere('payroll_period_id', $periodId);
                }
            })
            ->orderBy('awarded_on')
            ->get();
    }

    private function assertTransition(Bonus $bonus, ApprovalStatus $target): void
    {
        if (! $bonus->status->canTransitionTo($target)) {
            throw CompensationException::invalidApprovalTransition($bonus->status->value, $target->value);
        }
    }
}
