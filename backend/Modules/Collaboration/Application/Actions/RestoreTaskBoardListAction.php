<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\TaskBoardList;

final class RestoreTaskBoardListAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, TaskBoardList $list] */
    public function execute(mixed ...$arguments): TaskBoardList
    {
        $actor = $arguments[0] ?? null;
        $list = $arguments[1] ?? null;

        if (! $actor instanceof User || ! $list instanceof TaskBoardList) {
            throw new InvalidArgumentException('RestoreTaskBoardListAction::execute expects (User $actor, TaskBoardList $list).');
        }

        if ((string) $list->company_id !== (string) $actor->company_id) {
            throw new AuthorizationException('This board list does not belong to your company.');
        }

        // Always append at the end of the currently-active lists rather than
        // trusting the list's stale pre-archive position: a list may have sat
        // archived long enough for that position to now collide with an
        // active list's (remediation-010 §3 — "no duplicate/list-order
        // corruption"; nothing previously recomputed this on restore).
        $position = 1 + (int) (TaskBoardList::query()
            ->where('company_id', $actor->company_id)
            ->whereNull('archived_at')
            ->max('position') ?? -1);

        $list->update(['archived_at' => null, 'position' => $position]);

        return $list;
    }
}
