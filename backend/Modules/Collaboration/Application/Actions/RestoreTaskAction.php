<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskBoardList;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

/**
 * §5 — "restored item returns to a valid canonical position/state". A task
 * may have sat archived long enough that its original list was itself
 * archived or renamed away; this re-homes it to a safe default list in that
 * case, and always recomputes `board_position` to the end of whichever list
 * it lands in — never trusting its stale pre-archive position, which could
 * now collide with cards added to that list while this one was archived.
 */
final class RestoreTaskAction extends BaseAction
{
    use LogsTaskActivity;

    public function __construct(
        private readonly TaskPolicy $policy,
        private readonly EnsureDefaultTaskBoardListsAction $ensureDefaultLists,
    ) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask) {
            throw new InvalidArgumentException('RestoreTaskAction::execute expects (User $actor, InternalTask $task).');
        }

        if (! $this->policy->restore($actor, $task)) {
            throw new AuthorizationException('You do not have access to restore this task.');
        }

        DB::transaction(function () use ($actor, $task): void {
            $list = $task->task_list_id !== null
                ? TaskBoardList::query()->where('id', $task->task_list_id)->lockForUpdate()->first()
                : null;

            if ($list === null || $list->archived_at !== null) {
                $list = $this->ensureDefaultLists->execute($actor)->first();
            }

            $position = 1 + (int) (InternalTask::query()
                ->where('task_list_id', $list?->id)
                ->where('id', '!=', $task->id)
                ->max('board_position') ?? -1);

            $task->update(['archived_at' => null, 'task_list_id' => $list?->id, 'board_position' => $position]);
        });

        $this->logActivity($task, $actor->id, 'restored');

        return $task;
    }
}
