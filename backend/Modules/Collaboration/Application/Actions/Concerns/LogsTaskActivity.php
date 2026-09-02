<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions\Concerns;

use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\InternalTaskActivity;

/**
 * Shared by every action that mutates a task — one append-only history
 * mechanism (brief §18), not a separate one per action.
 */
trait LogsTaskActivity
{
    private function logActivity(InternalTask $task, int $actorUserId, string $eventType, ?string $from = null, ?string $to = null): void
    {
        InternalTaskActivity::query()->create([
            'task_id' => $task->id,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'from_value' => $from,
            'to_value' => $to,
            'created_at' => now(),
        ]);
    }
}
