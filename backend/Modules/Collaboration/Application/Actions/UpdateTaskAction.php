<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Application\Events\TaskBroadcast;
use Modules\Collaboration\Domain\Models\InternalTask;

/**
 * Editable-fields update (brief §28's "update editable fields" policy
 * case) — title/description/priority/due_at only. Creator-only, same
 * ownership gate as reassignment. `$changes` carries only the fields the
 * request actually submitted (a PATCH, not a PUT) — logs one activity row
 * per field with an old/new value worth showing (priority, due date);
 * title/description changes are logged without echoing full text content
 * into the audit trail.
 */
final class UpdateTaskAction extends BaseAction
{
    use LogsTaskActivity;

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, array<string, mixed> $changes] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $changes = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! is_array($changes)) {
            throw new InvalidArgumentException('UpdateTaskAction::execute expects (User $actor, InternalTask $task, array $changes).');
        }

        if ($task->creator_user_id !== $actor->id) {
            throw new AuthorizationException('Only the task creator may edit this task.');
        }

        if (array_key_exists('priority', $changes) && $changes['priority'] !== $task->priority) {
            $this->logActivity($task, $actor->id, 'priority_changed', $task->priority->value, $changes['priority']->value);
        }

        if (array_key_exists('due_at', $changes)) {
            $this->logActivity($task, $actor->id, 'due_date_changed', $task->due_at?->toIso8601String(), $changes['due_at']);
        }

        if (array_key_exists('title', $changes) || array_key_exists('description', $changes)) {
            $this->logActivity($task, $actor->id, 'updated');
        }

        $task->update($changes);

        TaskBroadcast::dispatch($task, 'updated');

        return $task;
    }
}
