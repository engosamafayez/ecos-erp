<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Application\Events\TaskBroadcast;
use Modules\Collaboration\Application\Notifications\TaskAssignedNotification;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Services\DriverMessagingAuthorizer;

/**
 * Only the task creator may reassign it (brief §28 — ownership-gated, not
 * permission-gated, exactly like a Collaboration Group owner adding
 * participants in Task 2).
 */
final class ReassignTaskAction extends BaseAction
{
    use LogsTaskActivity;

    public function __construct(private readonly DriverMessagingAuthorizer $driverMessagingAuthorizer) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, int $newAssigneeUserId] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $newAssigneeUserId = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! is_int($newAssigneeUserId)) {
            throw new InvalidArgumentException('ReassignTaskAction::execute expects (User $actor, InternalTask $task, int $newAssigneeUserId).');
        }

        if ($task->creator_user_id !== $actor->id) {
            throw new AuthorizationException('Only the task creator may reassign it.');
        }

        $newAssignee = User::query()
            ->where('id', $newAssigneeUserId)
            ->where('company_id', $task->company_id)
            ->firstOrFail();

        if ($newAssignee->id !== $actor->id) {
            $this->driverMessagingAuthorizer->assertCanAssign($actor, $newAssignee);
        }

        $previousAssigneeId = $task->assignee_user_id;
        $task->update(['assignee_user_id' => $newAssignee->id]);

        $this->logActivity($task, $actor->id, 'assigned', (string) $previousAssigneeId, (string) $newAssignee->id);

        if ($newAssignee->id !== $actor->id) {
            Notification::send($newAssignee, new TaskAssignedNotification($task));
        }

        TaskBroadcast::dispatch($task, 'reassigned');

        return $task;
    }
}
