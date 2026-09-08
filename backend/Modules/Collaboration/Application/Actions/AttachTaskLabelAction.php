<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
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
            // Pre-existing bug found while implementing remediation-010 §7
            // (multiple assignees): collaboration_task_label_task has its own
            // UUID primary key but no DB default for it, and plain
            // BelongsToMany::attach() never populates a pivot's surrogate key
            // — under strict MySQL this INSERT has always failed with "Field
            // 'id' doesn't have a default value" (reproduced against a real
            // MySQL instance; confirmed unrelated to and pre-existing this
            // task). Supplying it explicitly here fixes both this call site
            // and the identical one this task's own AddTaskAssigneeAction
            // uses for the same reason.
            $task->labels()->attach($label->id, ['id' => (string) Str::orderedUuid(), 'created_at' => now()]);
            $this->logActivity($task, $actor->id, 'label_added', null, $label->name);
        }

        return $task;
    }
}
