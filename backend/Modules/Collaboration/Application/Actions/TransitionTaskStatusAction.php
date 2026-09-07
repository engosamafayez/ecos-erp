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
use Modules\Collaboration\Application\Notifications\TaskStatusChangedNotification;
use Modules\Collaboration\Domain\Enums\TaskStatus;
use Modules\Collaboration\Domain\Exceptions\CollaborationException;
use Modules\Collaboration\Domain\Models\InternalTask;

/**
 * The exact V1 transition set decided in the architecture report §11
 * ("Completion/reopen: DONE -> IN_PROGRESS (reopen) allowed; DONE -> TODO
 * not modeled separately") and reaffirmed by this task's brief §5 as
 * something to report explicitly rather than silently assume: reopening
 * IS approved V1 behaviour, always re-entering IN_PROGRESS, never TODO.
 * No BLOCKED/REVIEW/APPROVED/REJECTED/QA/ARCHIVED states exist (brief §4).
 */
final class TransitionTaskStatusAction extends BaseAction
{
    use LogsTaskActivity;

    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'todo' => ['in_progress', 'cancelled'],
        'in_progress' => ['done', 'cancelled'],
        'done' => ['in_progress'], // reopen — architecture report §11
        'cancelled' => [],
    ];

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, TaskStatus $newStatus] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $newStatus = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $newStatus instanceof TaskStatus) {
            throw new InvalidArgumentException('TransitionTaskStatusAction::execute expects (User $actor, InternalTask $task, TaskStatus $newStatus).');
        }

        if ($task->creator_user_id !== $actor->id && $task->assignee_user_id !== $actor->id) {
            throw new AuthorizationException('Only the task creator or assignee may change its status.');
        }

        $allowedTargets = self::ALLOWED_TRANSITIONS[$task->status->value] ?? [];

        if (! in_array($newStatus->value, $allowedTargets, true)) {
            throw CollaborationException::invalidStatusTransition($task->status->value, $newStatus->value);
        }

        $previousStatus = $task->status;

        $attributes = ['status' => $newStatus];
        $attributes['completed_at'] = $newStatus === TaskStatus::Done ? now() : null;
        $attributes['cancelled_at'] = $newStatus === TaskStatus::Cancelled ? now() : null;

        $task->update($attributes);

        $this->logActivity($task, $actor->id, 'status_changed', $previousStatus->value, $newStatus->value);

        $recipient = $actor->id === $task->assignee_user_id ? $task->creator : $task->assignee;

        if ($recipient !== null && $recipient->id !== $actor->id) {
            Notification::send($recipient, new TaskStatusChangedNotification($task, $previousStatus));
        }

        // TASK-ECOS-INTERNAL-COLLABORATION-TASKS-TRELLO-FINAL-CLOSURE-002 §27:
        // followers reuse this exact same notification the creator/assignee
        // already get — never a parallel watcher-notification engine — and
        // never the actor or whoever was already notified as $recipient
        // above (no duplicate delivery).
        $alreadyNotified = array_filter([$actor->id, $recipient?->id]);
        $followerIds = $task->followers()->whereNotIn('user_id', $alreadyNotified)->pluck('user_id');

        if ($followerIds->isNotEmpty()) {
            Notification::send(User::query()->whereIn('id', $followerIds)->get(), new TaskStatusChangedNotification($task, $previousStatus));
        }

        TaskBroadcast::dispatch($task, 'status_changed');

        return $task;
    }
}
