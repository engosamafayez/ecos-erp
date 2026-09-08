<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Application\Events\TaskBroadcast;
use Modules\Collaboration\Application\Notifications\TaskAssignedNotification;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Services\DriverMessagingAuthorizer;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

/**
 * Adds an ADDITIONAL assignee alongside the task's single primary
 * `assignee_user_id` (TASK-ECOS-INTERNAL-COLLABORATION-FINAL-USER-REVIEW-
 * REMEDIATION-010 §7 — "preserve it for backward compatibility; add a
 * canonical multiple-assignee relation"). The primary assignee column and
 * its authority (ReassignTaskAction, notifications, "my tasks" ownership
 * checks) are untouched by this action; this is a purely additive
 * membership set, reusing the same TaskAssignedNotification/audit-trail
 * machinery the primary assignment flow already uses.
 */
final class AddTaskAssigneeAction extends BaseAction
{
    use LogsTaskActivity;

    public function __construct(
        private readonly TaskPolicy $policy,
        private readonly DriverMessagingAuthorizer $driverMessagingAuthorizer,
    ) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, User $target] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $target = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $target instanceof User) {
            throw new InvalidArgumentException('AddTaskAssigneeAction::execute expects (User $actor, InternalTask $task, User $target).');
        }

        if ((string) $target->company_id !== (string) $task->company_id) {
            throw new AuthorizationException("That user does not belong to this task's company.");
        }

        if (! $this->policy->manageAssignees($actor, $task, $target)) {
            throw new AuthorizationException('You do not have access to add this assignee.');
        }

        if ($target->id !== $actor->id) {
            $this->driverMessagingAuthorizer->assertCanAssign($actor, $target);
        }

        // Avoid duplicate assignments (§7): the primary assignee is already
        // "assigned" and must never also appear in the additional-set.
        if ($target->id === $task->assignee_user_id) {
            throw new InvalidArgumentException('This user is already the primary assignee.');
        }

        if (! $task->additionalAssignees()->where('user_id', $target->id)->exists()) {
            // BelongsToMany::attach() writes only the two FK columns plus
            // whatever's in this array — it never populates a pivot's own
            // surrogate key (that requires a pivot model, which this
            // deliberately-plain pivot table doesn't have, matching
            // collaboration_task_label_task's own convention). Under strict
            // MySQL this column has no default, so it must be supplied here.
            $task->additionalAssignees()->attach($target->id, ['id' => (string) Str::orderedUuid(), 'created_at' => now()]);

            $this->logActivity($task, $actor->id, 'assignee_added', null, (string) $target->id);

            if ($target->id !== $actor->id) {
                Notification::send($target, new TaskAssignedNotification($task));
            }

            TaskBroadcast::dispatch($task, 'reassigned');
        }

        return $task;
    }
}
