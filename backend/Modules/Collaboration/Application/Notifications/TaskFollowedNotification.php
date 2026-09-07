<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Collaboration\Domain\Models\InternalTask;

/** Sent only when someone ELSE adds a user as a follower — never for a self-follow (brief §27, no self-notification). */
final class TaskFollowedNotification extends Notification
{
    public function __construct(private readonly InternalTask $task) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['database'];
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
