<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskLabel;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

final class AttachTaskLabelAction extends BaseAction
{
    use LogsTaskActivity;

    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, TaskLabel $label] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $label = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $label instanceof TaskLabel) {
            throw new InvalidArgumentException('AttachTaskLabelAction::execute expects (User $actor, InternalTask $task, TaskLabel $label).');
        }

        if (! $this->policy->manageLabels($actor, $task)) {
            throw new AuthorizationException('You do not have access to label this task.');
        }

        if ((string) $label->company_id !== (string) $task->company_id) {
            throw new AuthorizationException('That label does not belong to this task\'s company.');
        }

        if (! $task->labels()->where('label_id', $label->id)->exists()) {
            $task->labels()->attach($label->id, ['created_at' => now()]);
            $this->logActivity($task, $actor->id, 'label_added', null, $label->name);
        }

        return $task;
    }
}
