<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\TaskBoardList;

final class RenameTaskBoardListAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, TaskBoardList $list, string $name] */
    public function execute(mixed ...$arguments): TaskBoardList
    {
        $actor = $arguments[0] ?? null;
        $list = $arguments[1] ?? null;
        $name = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $list instanceof TaskBoardList || ! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('RenameTaskBoardListAction::execute expects (User $actor, TaskBoardList $list, string $name).');
        }

        if ((string) $list->company_id !== (string) $actor->company_id) {
            throw new AuthorizationException('This board list does not belong to your company.');
        }

        $list->update(['name' => trim($name)]);

        return $list;
    }
}
