<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Application\Events\TaskBroadcast;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

final class RemoveTaskAssigneeAction extends BaseAction
{
    use LogsTaskActivity;

    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, User $target] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $target = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $target instanceof User) {
            throw new InvalidArgumentException('RemoveTaskAssigneeAction::execute expects (User $actor, InternalTask $task, User $target).');
        }

        if (! $this->policy->manageAssignees($actor, $task, $target)) {
            throw new AuthorizationException('You do not have access to remove this assignee.');
        }

        $task->additionalAssignees()->detach($target->id);

        $this->logActivity($task, $actor->id, 'assignee_removed', (string) $target->id, null);

        TaskBroadcast::dispatch($task, 'reassigned');

        return $task;
    }
}
