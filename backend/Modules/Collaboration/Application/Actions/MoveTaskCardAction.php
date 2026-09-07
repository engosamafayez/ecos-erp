<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Application\Events\TaskBroadcast;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskBoardList;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

/**
 * Moves a card to a (possibly different) board LIST at a target position —
 * an organizational placement, never a TaskStatus transition (brief §1/§3).
 * Ordering uses a per-list full-rewrite of `board_position` (brief §25 —
 * "avoid naive GLOBAL sequential rewrites"; this one is scoped to a single
 * destination list, typically a handful of cards, not the whole board/
 * company) inside a transaction with row locks, so two concurrent moves
 * into the same list can never leave duplicate or gapped positions.
 */
final class MoveTaskCardAction extends BaseAction
{
    use LogsTaskActivity;

    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, TaskBoardList $destination, int $position] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $destination = $arguments[2] ?? null;
        $position = $arguments[3] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $destination instanceof TaskBoardList || ! is_int($position)) {
            throw new InvalidArgumentException('MoveTaskCardAction::execute expects (User $actor, InternalTask $task, TaskBoardList $destination, int $position).');
        }

        if (! $this->policy->moveCard($actor, $task)) {
            throw new AuthorizationException('You do not have access to move this task.');
        }

        // Tenant isolation (brief §24): a card may only move into a list
        // owned by the SAME company as the task itself — never validated by
        // the frontend alone.
        if ((string) $destination->company_id !== (string) $task->company_id) {
            throw new AuthorizationException('That list does not belong to this task\'s company.');
        }

        $previousListId = $task->task_list_id;
        $previousListName = $task->list?->name;

        DB::transaction(function () use ($task, $destination, $position): void {
            $siblingIds = InternalTask::query()
                ->where('task_list_id', $destination->id)
                ->where('id', '!=', $task->id)
                ->orderBy('board_position')
                ->lockForUpdate()
                ->pluck('id')
                ->values()
                ->all();

            $insertAt = max(0, min($position, count($siblingIds)));
            array_splice($siblingIds, $insertAt, 0, [$task->id]);

            foreach ($siblingIds as $index => $id) {
                InternalTask::query()->where('id', $id)->update([
                    'task_list_id' => $destination->id,
                    'board_position' => $index,
                ]);
            }
        });

        $task->refresh();

        if ($previousListId !== $destination->id) {
            $this->logActivity($task, $actor->id, 'list_changed', $previousListName, $destination->name);
        }

        TaskBroadcast::dispatch($task, 'moved');

        return $task;
    }
}
