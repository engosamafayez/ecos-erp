<?php

declare(strict_types=1);

namespace Modules\Hr\Performance\Domain\Services;

use Illuminate\Support\Carbon;
use Modules\Hr\Infrastructure\Services\HrAuditService;
use Modules\Hr\Performance\Domain\Models\ManagerReview;
use Modules\Hr\Workforce\Domain\Models\Employee;

/** Manager reviews — a rating and three notes, once per employee per month. */
final class ManagerReviewService
{
    /** Confidential narrative fields kept out of the audit trail's old/new values. */
    private const REDACTED_FIELDS = ['strengths', 'improvement_notes', 'manager_comments'];

    private const AUDITED_FIELDS = ['overall_rating', 'strengths', 'improvement_notes', 'manager_comments', 'status'];

    public function __construct(private readonly HrAuditService $audit) {}

    public function save(Employee $employee, string $periodMonth, array $data, ?Employee $reviewer = null, ?int $actorId = null): ManagerReview
    {
        $keys = [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'period_month' => $periodMonth,
        ];

        $before = ManagerReview::query()->where($keys)->first()?->only(self::AUDITED_FIELDS) ?? [];

        $review = ManagerReview::updateOrCreate(
            $keys,
            [
                'reviewer_employee_id' => $reviewer?->id,
                'overall_rating' => max(1, min(5, (int) ($data['overall_rating'] ?? 3))),
                'strengths' => $data['strengths'] ?? null,
                'improvement_notes' => $data['improvement_notes'] ?? null,
                'manager_comments' => $data['manager_comments'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'created_by' => $actorId,
            ],
        );

        $this->audit->log(
            action: $review->wasRecentlyCreated ? 'hr.manager_review.created' : 'hr.manager_review.updated',
            entityType: HrAuditService::ENTITY_MANAGER_REVIEW,
            entityId: (string) $review->id,
            companyId: (string) $employee->company_id,
            actorId: $actorId,
            oldValues: $this->audit->redact($before, self::REDACTED_FIELDS),
            newValues: $this->audit->redact($review->only(self::AUDITED_FIELDS), self::REDACTED_FIELDS),
            metadata: ['employee_id' => (string) $employee->id, 'reviewer_employee_id' => $reviewer?->id, 'period_month' => $periodMonth],
        );

        return $review;
    }

    public function submit(ManagerReview $review, ?int $actorId = null): ManagerReview
    {
        $previousStatus = $review->status;

        $review->update(['status' => 'submitted', 'submitted_at' => Carbon::now()]);
        $review->refresh();

        $this->audit->log(
            action: 'hr.manager_review.submitted',
            entityType: HrAuditService::ENTITY_MANAGER_REVIEW,
            entityId: (string) $review->id,
            companyId: (string) $review->company_id,
            actorId: $actorId,
            oldValues: ['status' => $previousStatus],
            newValues: ['status' => $review->status, 'submitted_at' => $review->submitted_at?->toDateTimeString()],
        );

        return $review;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ManagerReview> */
    public function forPeriod(string $companyId, string $periodMonth)
    {
        return ManagerReview::query()
            ->with('employee:id,first_name,last_name,employee_number')
            ->where('company_id', $companyId)
            ->where('period_month', $periodMonth)
            ->orderByDesc('overall_rating')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ManagerReview> */
    public function historyFor(Employee $employee, int $months = 12)
    {
        $earliest = Carbon::now()->subMonthsNoOverflow($months - 1)->format('Y-m');

        return ManagerReview::query()
            ->where('employee_id', $employee->id)
            ->where('period_month', '>=', $earliest)
            ->orderByDesc('period_month')
            ->get();
    }
}
