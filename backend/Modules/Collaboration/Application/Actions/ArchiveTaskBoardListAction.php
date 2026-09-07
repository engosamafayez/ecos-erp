<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\TaskBoardList;

/**
 * Archive, not delete (brief §2 — "Restore List where practical"): cards
 * already placed in the list keep their `task_list_id` untouched, they just
 * stop appearing on the active board because the list itself is filtered
 * out (brief §2's "Archive List").
 */
final class ArchiveTaskBoardListAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, TaskBoardList $list] */
    public function execute(mixed ...$arguments): TaskBoardList
    {
        $actor = $arguments[0] ?? null;
        $list = $arguments[1] ?? null;

        if (! $actor instanceof User || ! $list instanceof TaskBoardList) {
            throw new InvalidArgumentException('ArchiveTaskBoardListAction::execute expects (User $actor, TaskBoardList $list).');
        }

        if ((string) $list->company_id !== (string) $actor->company_id) {
            throw new AuthorizationException('This board list does not belong to your company.');
        }

        $list->update(['archived_at' => now()]);

        return $list;
    }
}
