<?php

declare(strict_types=1);

namespace Modules\Crm\Engagement\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Crm\Engagement\Domain\Enums\FollowUpQueue;
use Modules\Crm\Engagement\Domain\Enums\TaskPriority;
use Modules\Crm\Engagement\Domain\Enums\TaskStatus;
use Modules\Crm\Engagement\Domain\Enums\TaskType;
use Modules\Crm\Engagement\Domain\Services\FollowUpQueueClassifier;

/** A CRM actionable — a task, follow-up, appointment or meeting with a lifecycle. */
class CustomerTask extends Model
{
    use HasUuids;

    protected $table = 'crm_customer_tasks';

    protected $fillable = [
        'company_id', 'customer_id', 'task_type', 'title', 'description', 'status', 'priority',
        'due_at', 'scheduled_at', 'location', 'assignee_id', 'created_by', 'completed_at', 'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'task_type' => TaskType::class,
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === TaskStatus::Open;
    }

    /**
     * The validated priority, or null for a historical value outside the
     * approved V1 set (§7/§8) — `priority` intentionally has no native enum
     * cast, since that would throw on read for exactly the historical values
     * this must instead degrade gracefully for.
     */
    public function priorityEnum(): ?TaskPriority
    {
        return TaskPriority::tryFrom((string) $this->priority);
    }

    /** Null for a closed task, or when this task has no due date and isn't open+unscheduled. */
    public function queue(?Carbon $now = null): ?FollowUpQueue
    {
        return FollowUpQueueClassifier::classify($this->status, $this->due_at, $now);
    }

    public function isOverdue(?Carbon $now = null): bool
    {
        return FollowUpQueueClassifier::isOverdue($this->status, $this->due_at, $now);
    }
}
