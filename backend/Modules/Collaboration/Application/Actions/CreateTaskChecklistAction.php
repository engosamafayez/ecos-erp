<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskChecklist;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

final class CreateTaskChecklistAction extends BaseAction
{
    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, string $title] */
    public function execute(mixed ...$arguments): TaskChecklist
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $title = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! is_string($title) || trim($title) === '') {
            throw new InvalidArgumentException('CreateTaskChecklistAction::execute expects (User $actor, InternalTask $task, string $title).');
        }

        if (! $this->policy->manageChecklist($actor, $task)) {
            throw new AuthorizationException('You do not have access to this task\'s checklist.');
        }

        $nextPosition = 1 + (int) ($task->checklists()->max('position') ?? -1);

        return $task->checklists()->create([
            'title' => trim($title),
            'position' => $nextPosition,
        ]);
    }
}
