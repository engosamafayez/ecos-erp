<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Enums\TaskPriority;
use Modules\Collaboration\Domain\Enums\TaskStatus;
use Modules\Collaboration\Domain\Models\InternalTask;

/**
 * Scoped backend filtering (brief §22) — never "fetch everything, filter
 * client-side". `scope` narrows to created-by-me / assigned-to-me / either
 * (default); tenant isolation is implicit — a task's `company_id` always
 * matches its creator/assignee's, so scoping by "my tasks" already can
 * never cross a company boundary.
 */
final class ListMyTasksAction extends BaseAction
{
    /**
     * @param  mixed  ...$arguments  [User $user, array{scope?: string, status?: TaskStatus, priority?: TaskPriority, overdue?: bool, team_id?: string, archived?: bool} $filters]
     */
    public function execute(mixed ...$arguments): Collection
    {
        $user = $arguments[0] ?? null;
        $filters = $arguments[1] ?? [];

        if (! $user instanceof User || ! is_array($filters)) {
            throw new InvalidArgumentException('ListMyTasksAction::execute expects (User $user, array $filters).');
        }

        $query = InternalTask::query()
            ->where('company_id', $user->company_id)
            ->with(['creator', 'assignee', 'additionalAssignees', 'list', 'labels'])
            ->withCount([
                'comments',
                'attachments',
                'followers',
                'checklistItems as checklist_items_total',
                'checklistItems as checklist_items_completed' => fn ($q) => $q->where('is_completed', true),
            ]);

        $query->where(function ($q) use ($user, $filters): void {
            $scope = $filters['scope'] ?? 'mine';

            // §7 — "assigned to me" (and default "mine") also includes tasks
            // where the user is only an additional assignee, not just the
            // primary one; otherwise being added would be functionally
            // invisible in the requester's own task list.
            $isAdditionalAssignee = fn ($aq) => $aq->where('user_id', $user->id);

            match ($scope) {
                'created' => $q->where('creator_user_id', $user->id),
                'assigned' => $q->where('assignee_user_id', $user->id)->orWhereHas('additionalAssignees', $isAdditionalAssignee),
                default => $q->where('creator_user_id', $user->id)
                    ->orWhere('assignee_user_id', $user->id)
                    ->orWhereHas('additionalAssignees', $isAdditionalAssignee),
            };
        });

        if (isset($filters['status']) && $filters['status'] instanceof TaskStatus) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['priority']) && $filters['priority'] instanceof TaskPriority) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['overdue'])) {
            $query->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Cancelled->value]);
        }

        if (! empty($filters['team_id'])) {
            $query->where('team_id', $filters['team_id']);
        }

        // §5 — the normal Board/List views never see archived tasks unless
        // explicitly asked for (the new Archive view passes archived=true).
        if (! empty($filters['archived'])) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        return $query->orderByRaw('due_at IS NULL, due_at ASC')
            ->orderByDesc('created_at')
            ->get();
    }
}
