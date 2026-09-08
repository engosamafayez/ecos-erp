<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

/**
 * Archive, not delete (§5 — "do not silently delete archived records"):
 * task_list_id/board_position/status are all left untouched, exactly like
 * ArchiveTaskBoardListAction leaves a list's cards untouched. Creator-only,
 * the same ownership tier as update()/reassign() — archiving is a
 * structural decision about the task, not day-to-day task work.
 */
final class ArchiveTaskAction extends BaseAction
{
    use LogsTaskActivity;

    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask) {
            throw new InvalidArgumentException('ArchiveTaskAction::execute expects (User $actor, InternalTask $task).');
        }

        if (! $this->policy->archive($actor, $task)) {
            throw new AuthorizationException('You do not have access to archive this task.');
        }

        $task->update(['archived_at' => now()]);

        $this->logActivity($task, $actor->id, 'archived');

        return $task;
    }
}
