<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Collaboration\Domain\Models\InternalTask;

/**
 * Sent to the assignee on creation and on reassignment — never to the
 * creator (self-notification is never useful, same rule as
 * NewMessageNotification skipping the sender).
 */
final class TaskAssignedNotification extends Notification
{
    public function __construct(private readonly InternalTask $task) {}

    /**
     * TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005 D2 — the
     * shared producer contract's channel, not Laravel's stock `database` one; see
     * TaskFollowedNotification::via() for why (the catalog's per-type toggle otherwise
     * silently has no effect on this type).
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['notifications-core'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type' => 'collaboration_task_assigned',
            'task_id' => $this->task->id,
            'title' => $this->task->title,
            'priority' => $this->task->priority->value,
            'due_at' => $this->task->due_at?->toIso8601String(),
            'message' => "You were assigned: {$this->task->title}",
        ];
    }
}
