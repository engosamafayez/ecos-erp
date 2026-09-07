<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskChecklist;
use Modules\Collaboration\Domain\Models\TaskChecklistItem;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

final class AddTaskChecklistItemAction extends BaseAction
{
    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, TaskChecklist $checklist, string $title] */
    public function execute(mixed ...$arguments): TaskChecklistItem
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $checklist = $arguments[2] ?? null;
        $title = $arguments[3] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $checklist instanceof TaskChecklist || ! is_string($title) || trim($title) === '') {
            throw new InvalidArgumentException('AddTaskChecklistItemAction::execute expects (User $actor, InternalTask $task, TaskChecklist $checklist, string $title).');
        }

        if (! $this->policy->manageChecklist($actor, $task)) {
            throw new AuthorizationException('You do not have access to this task\'s checklist.');
        }

        if ($checklist->task_id !== $task->id) {
            throw new AuthorizationException('That checklist does not belong to this task.');
        }

        $nextPosition = 1 + (int) ($checklist->items()->max('position') ?? -1);

        return $checklist->items()->create([
            'title' => trim($title),
            'position' => $nextPosition,
        ]);
    }
}
