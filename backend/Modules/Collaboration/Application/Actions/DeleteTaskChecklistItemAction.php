<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskChecklistItem;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

final class DeleteTaskChecklistItemAction extends BaseAction
{
    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, TaskChecklistItem $item] */
    public function execute(mixed ...$arguments): void
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $item = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $item instanceof TaskChecklistItem) {
            throw new InvalidArgumentException('DeleteTaskChecklistItemAction::execute expects (User $actor, InternalTask $task, TaskChecklistItem $item).');
        }

        if (! $this->policy->manageChecklist($actor, $task)) {
            throw new AuthorizationException('You do not have access to this task\'s checklist.');
        }

        if ($item->checklist->task_id !== $task->id) {
            throw new AuthorizationException('That checklist item does not belong to this task.');
        }

        $item->delete();
    }
}
