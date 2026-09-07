<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Collaboration\Domain\Models\InternalTask;

/** Sent only when someone ELSE adds a user as a follower — never for a self-follow (brief §27, no self-notification). */
final class TaskFollowedNotification extends Notification
{
    public function __construct(private readonly InternalTask $task) {}

    /**
     * TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005 D2 — the
     * shared producer contract's channel (Modules\Notifications\...\CoreDatabaseChannel),
     * not Laravel's stock `database` channel: this is what actually makes the Notification
     * Type Catalog's per-type on/off toggle (NotificationDeliveryPolicy::isTypeEnabledFor())
     * take effect for this type. Same row shape either way — see CoreDatabaseChannel's own
     * docblock (a notification not implementing ProvidesNotificationMetadataInterface
     * still works, falling back to its defaults).
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
            'type' => 'collaboration_task_followed',
            'task_id' => $this->task->id,
            'title' => $this->task->title,
            'message' => "You were added as a follower of: {$this->task->title}",
        ];
    }
}
