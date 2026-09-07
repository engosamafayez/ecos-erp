<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Policies;

use App\Models\User;
use Modules\Collaboration\Domain\Models\InternalTask;

/**
 * Ownership-gated (creator/assignee), not permission-gated — same shape as
 * ConversationPolicy. Every method also checks company_id explicitly
 * (brief §29 — "fail closed across tenant/company boundaries") even though
 * creator/assignee are always same-company by construction (CreateTaskAction/
 * ReassignTaskAction only ever resolve targets scoped to the actor's own
 * company) — an explicit check here is a second, independent enforcement
 * point, not a redundant no-op.
 */
final class TaskPolicy
{
    public function view(User $user, InternalTask $task): bool
    {
        return $this->sameCompany($user, $task)
            && ($this->isOwnerOrAssignee($user, $task) || $this->isFollower($user, $task));
    }

    public function update(User $user, InternalTask $task): bool
    {
        return $this->sameCompany($user, $task) && $task->creator_user_id === $user->id;
    }

    public function reassign(User $user, InternalTask $task): bool
    {
        return $this->update($user, $task);
    }

    public function transitionStatus(User $user, InternalTask $task): bool
    {
        return $this->view($user, $task);
    }

    /** Cancellation is one of transitionStatus's allowed targets, not a separate rule — named here only so it's independently traceable (brief §28's explicit policy list). */
    public function cancel(User $user, InternalTask $task): bool
    {
        return $this->transitionStatus($user, $task);
    }

    /** Covers driver self-transition too — nothing branches on participant type; an assignee is an assignee. */
    public function comment(User $user, InternalTask $task): bool
    {
        return $this->view($user, $task);
    }

    public function attach(User $user, InternalTask $task): bool
    {
        return $this->view($user, $task);
    }

    /**
     * TASK-ECOS-INTERNAL-COLLABORATION-TASKS-TRELLO-FINAL-CLOSURE-002 §17/§23
     * — moving a card between board lists, editing its checklist, and
     * attaching/detaching a label are all collaborative "working on the
     * task" actions, the same trust level as commenting or transitioning
     * status — creator or assignee, not creator-only like update()/reassign().
     */
    public function moveCard(User $user, InternalTask $task): bool
    {
        return $this->view($user, $task);
    }

    public function manageChecklist(User $user, InternalTask $task): bool
    {
        return $this->view($user, $task);
    }

    public function manageLabels(User $user, InternalTask $task): bool
    {
        return $this->view($user, $task);
    }

    /**
     * §16 — "add/remove follower where permitted": a user may always add or
     * remove THEMSELVES (self-follow/unfollow, but only once they can
     * already view the task — creator, assignee, or an existing follower);
     * adding or removing SOMEONE ELSE as a follower is creator-only, the
     * same ownership tier as reassign()/update() (deciding who else watches
     * a task is closer to task ownership than to day-to-day task work).
     */
    public function manageFollower(User $actor, InternalTask $task, User $target): bool
    {
        if (! $this->sameCompany($actor, $task)) {
            return false;
        }

        if ($actor->id === $target->id) {
            return $this->isOwnerOrAssignee($actor, $task) || $this->isFollower($actor, $task);
        }

        return $task->creator_user_id === $actor->id;
    }

    private function isOwnerOrAssignee(User $user, InternalTask $task): bool
    {
        return $task->creator_user_id === $user->id || $task->assignee_user_id === $user->id;
    }

    private function isFollower(User $user, InternalTask $task): bool
    {
        return $task->relationLoaded('followers')
            ? $task->followers->contains('user_id', $user->id)
            : $task->followers()->where('user_id', $user->id)->exists();
    }

    private function sameCompany(User $user, InternalTask $task): bool
    {
        return (string) $task->company_id === (string) $user->company_id;
    }
}
