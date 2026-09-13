<?php

declare(strict_types=1);

namespace Modules\Hr\Performance\Domain\Services;

use Modules\Hr\Compensation\Domain\Enums\KpiMetric;
use Modules\Hr\Compensation\Domain\Exceptions\CompensationException;
use Modules\Hr\Infrastructure\Services\HrAuditService;
use Modules\Hr\Performance\Domain\Enums\GoalSubject;
use Modules\Hr\Performance\Domain\Models\Goal;

/**
 * Setting measurable targets.
 *
 * A goal must name a metric the KPI engine can actually collect — otherwise it
 * would sit there forever at zero achievement with nobody able to say why.
 */
final class GoalService
{
    private const AUDITED_FIELDS = ['title', 'target_value', 'comparison', 'weight', 'status', 'notes'];

    public function __construct(private readonly HrAuditService $audit) {}

    public function set(string $companyId, array $data, ?int $actorId = null): Goal
    {
        $metric = KpiMetric::tryFrom((string) ($data['metric_key'] ?? ''));

        if ($metric === null) {
            throw CompensationException::unknownMetric((string) ($data['metric_key'] ?? ''));
        }

        $subject = ($data['subject_type'] ?? null) instanceof GoalSubject
            ? $data['subject_type']
            : (GoalSubject::tryFrom((string) ($data['subject_type'] ?? '')) ?? GoalSubject::Employee);

        // A metric where less is better defaults to the matching comparison, so
        // nobody accidentally sets a target to maximise their shortages.
        $comparison = $data['comparison'] ?? ($metric->higherIsBetter() ? 'gte' : 'lte');

        $keys = [
            'company_id' => $companyId,
            'subject_type' => $subject->value,
            'subject_id' => $data['subject_id'],
            'metric_key' => $metric->value,
            'period_month' => $data['period_month'],
        ];

        $before = Goal::query()->where($keys)->first()?->only(self::AUDITED_FIELDS) ?? [];

        $goal = Goal::updateOrCreate(
            $keys,
            [
                'title' => $data['title'] ?? $metric->label(),
                'target_value' => round((float) $data['target_value'], 4),
                'comparison' => $comparison,
                'weight' => (int) ($data['weight'] ?? 100),
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ],
        );

        $this->audit->log(
            action: $goal->wasRecentlyCreated ? 'hr.goal.created' : 'hr.goal.updated',
            entityType: HrAuditService::ENTITY_GOAL,
            entityId: (string) $goal->id,
            companyId: $companyId,
            actorId: $actorId,
            oldValues: $before,
            newValues: $goal->only(self::AUDITED_FIELDS),
            metadata: ['subject_type' => $subject->value, 'subject_id' => (string) $data['subject_id'], 'metric_key' => $metric->value, 'period_month' => (string) $data['period_month']],
        );

        return $goal;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Goal> */
    public function forSubject(string $companyId, GoalSubject $subject, string $subjectId, string $periodMonth)
    {
        return Goal::query()
            ->where('company_id', $companyId)
            ->where('subject_type', $subject->value)
            ->where('subject_id', $subjectId)
            ->where('period_month', $periodMonth)
            ->orderBy('metric_key')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Goal> */
    public function forPeriod(string $companyId, string $periodMonth, ?GoalSubject $subject = null)
    {
        return Goal::query()
            ->where('company_id', $companyId)
            ->where('period_month', $periodMonth)
            ->when($subject !== null, fn ($q) => $q->where('subject_type', $subject->value))
            ->orderBy('subject_type')
            ->get();
    }

    public function cancel(Goal $goal, ?int $actorId = null): Goal
    {
        $previousStatus = $goal->status;

        $goal->update(['status' => 'cancelled']);
        $goal->refresh();

        $this->audit->log(
            action: 'hr.goal.cancelled',
            entityType: HrAuditService::ENTITY_GOAL,
            entityId: (string) $goal->id,
            companyId: (string) $goal->company_id,
            actorId: $actorId,
            oldValues: ['status' => $previousStatus],
            newValues: ['status' => $goal->status],
        );

        return $goal;
    }
}
