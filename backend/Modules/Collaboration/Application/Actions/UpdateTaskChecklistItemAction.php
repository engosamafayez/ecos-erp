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

/** Edits the item's title and/or completion state — a PATCH, only the submitted fields change. */
final class UpdateTaskChecklistItemAction extends BaseAction
{
    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, TaskChecklistItem $item, array<string, mixed> $changes] */
    public function execute(mixed ...$arguments): TaskChecklistItem
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $item = $arguments[2] ?? null;
        $changes = $arguments[3] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $item instanceof TaskChecklistItem || ! is_array($changes)) {
            throw new InvalidArgumentException('UpdateTaskChecklistItemAction::execute expects (User $actor, InternalTask $task, TaskChecklistItem $item, array $changes).');
        }

        if (! $this->policy->manageChecklist($actor, $task)) {
            throw new AuthorizationException('You do not have access to this task\'s checklist.');
        }

        if ($item->checklist->task_id !== $task->id) {
            throw new AuthorizationException('That checklist item does not belong to this task.');
        }

        $item->update(array_intersect_key($changes, array_flip(['title', 'is_completed'])));

        return $item;
    }
}
