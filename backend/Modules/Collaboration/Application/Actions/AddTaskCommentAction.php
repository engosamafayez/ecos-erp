<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\InternalTaskComment;

/**
 * Ownership-gated (creator or assignee), not permission-gated — same
 * pattern as every other task/conversation read-or-write in this module.
 * Comment notifications were deliberately not added (brief §24 explicitly
 * warns against notification spam; task-assigned and status-changed are the
 * two triggers judged worth it for V1).
 */
final class AddTaskCommentAction extends BaseAction
{
    use LogsTaskActivity;

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, string $body] */
    public function execute(mixed ...$arguments): InternalTaskComment
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $body = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException('AddTaskCommentAction::execute expects (User $actor, InternalTask $task, string $body).');
        }

        if ($task->creator_user_id !== $actor->id && $task->assignee_user_id !== $actor->id) {
            throw new AuthorizationException('You do not have access to this task.');
        }

        $comment = InternalTaskComment::query()->create([
            'task_id' => $task->id,
            'author_user_id' => $actor->id,
            'body' => $body,
            'created_at' => now(),
        ]);

        $this->logActivity($task, $actor->id, 'comment_added');

        return $comment;
    }
}
