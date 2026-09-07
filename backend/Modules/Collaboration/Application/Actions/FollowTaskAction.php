<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Modules\Collaboration\Application\Notifications\TaskFollowedNotification;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

/** Handles both self-follow and creator-adds-another-follower (brief §16 — TaskPolicy::manageFollower already tells them apart). */
final class FollowTaskAction extends BaseAction
{
    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, User $target] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $target = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $target instanceof User) {
            throw new InvalidArgumentException('FollowTaskAction::execute expects (User $actor, InternalTask $task, User $target).');
        }

        if ((string) $target->company_id !== (string) $task->company_id) {
            throw new AuthorizationException('That user does not belong to this task\'s company.');
        }

        if (! $this->policy->manageFollower($actor, $task, $target)) {
            throw new AuthorizationException('You do not have access to add this follower.');
        }

        if (! $task->followers()->where('user_id', $target->id)->exists()) {
            $task->followers()->create(['user_id' => $target->id, 'created_at' => now()]);

            if ($target->id !== $actor->id) {
                Notification::send($target, new TaskFollowedNotification($task));
            }
        }

        return $task;
    }
}
