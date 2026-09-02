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
        return $this->sameCompany($user, $task) && $this->isOwnerOrAssignee($user, $task);
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

    private function isOwnerOrAssignee(User $user, InternalTask $task): bool
    {
        return $task->creator_user_id === $user->id || $task->assignee_user_id === $user->id;
    }

    private function sameCompany(User $user, InternalTask $task): bool
    {
        return (string) $task->company_id === (string) $user->company_id;
    }
}
