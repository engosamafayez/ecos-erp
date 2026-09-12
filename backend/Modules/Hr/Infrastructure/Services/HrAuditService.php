<?php

declare(strict_types=1);

namespace Modules\Hr\Infrastructure\Services;

use App\Core\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * The Hr module's one seam onto the platform's canonical audit trail.
 *
 * ┌─ ONE MECHANISM, NOT A SECOND ONE ───────────────────────────────────────┐
 * │ This is a thin wrapper around App\Core\Audit\AuditService — the same       │
 * │ pattern IAM's UserAuditService/RoleTemplateAuditService already establish  │
 * │ for their own aggregates. Hr does not get its own audit table.             │
 * │                                                                            │
 * │ Every call is deferred with DB::afterCommit(): AuditService itself has no   │
 * │ transactional guarantee (nothing in the platform gives it one today), so   │
 * │ recording here — inside whichever transaction is open when a service calls │
 * │ us, or immediately when none is — is what keeps the trail from ever        │
 * │ describing a mutation that was later rolled back.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class HrAuditService
{
    public const ENTITY_EMPLOYEE = 'hr_employee';

    public const ENTITY_ATTENDANCE_DAY = 'hr_attendance_day';

    public const ENTITY_LEAVE_REQUEST = 'hr_leave_request';

    public const ENTITY_GOAL = 'hr_goal';

    public const ENTITY_PERFORMANCE_SNAPSHOT = 'hr_performance_snapshot';

    public const ENTITY_MANAGER_REVIEW = 'hr_manager_review';

    public const ENTITY_BONUS_RECOMMENDATION = 'hr_bonus_recommendation';

    public const ENTITY_EMPLOYEE_INCIDENT = 'hr_employee_incident';

    /** Marker written in place of a redacted value — never null-vs-absent ambiguous. */
    private const REDACTED = '[redacted]';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        string $entityType,
        string $entityId,
        ?string $companyId,
        ?int $actorId,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
    ): void {
        DB::afterCommit(function () use ($action, $entityType, $entityId, $companyId, $actorId, $oldValues, $newValues, $metadata): void {
            $this->audit->record(
                action: $action,
                entityType: $entityType,
                entityId: $entityId,
                companyId: $companyId,
                userId: $actorId,
                oldValues: $oldValues,
                newValues: $newValues,
                metadata: $metadata,
            );
        });
    }

    /**
     * Replace named fields' values with a redaction marker, when present.
     *
     * For private free-text (a review's narrative comments, an incident's
     * description) the trail should show THAT the field changed, never WHAT it
     * changed to or from — this is where that line is drawn, at the call site,
     * since AuditService itself has no field-aware filtering of its own.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function redact(array $values, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = $values[$field] === null ? null : self::REDACTED;
            }
        }

        return $values;
    }
}
