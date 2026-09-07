<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Collaboration\Domain\Enums\TaskStatus;
use Modules\Collaboration\Domain\Models\InternalTask;

/**
 * Sent to whichever of {creator, assignee} did NOT perform the transition —
 * e.g. the assignee marks a task Done, the creator is notified; never sent
 * to the actor who made the change themself.
 */
final class TaskStatusChangedNotification extends Notification
{
    public function __construct(
        private readonly InternalTask $task,
        private readonly TaskStatus $previousStatus,
    ) {}

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
            'type' => 'collaboration_task_status_changed',
            'task_id' => $this->task->id,
            'title' => $this->task->title,
            'from_status' => $this->previousStatus->value,
            'to_status' => $this->task->status->value,
            'message' => "'{$this->task->title}' moved to {$this->task->status->value}",
        ];
    }
}
