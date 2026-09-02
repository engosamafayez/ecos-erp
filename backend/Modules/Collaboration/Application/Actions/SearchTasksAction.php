<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\InternalTask;

/**
 * Its own tsvector/index, deliberately separate from message search (brief
 * §23). Scoped to tasks the requester created or is assigned to — the same
 * ownership boundary every other task operation uses, applied before the
 * text predicate (never "search everything, filter after").
 */
final class SearchTasksAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $user, string $query, int $limit] */
    public function execute(mixed ...$arguments): Collection
    {
        $user = $arguments[0] ?? null;
        $searchQuery = $arguments[1] ?? null;
        $limit = $arguments[2] ?? 20;

        if (! $user instanceof User || ! is_string($searchQuery) || trim($searchQuery) === '') {
            throw new InvalidArgumentException('SearchTasksAction::execute expects (User $user, string $query, int $limit).');
        }

        return InternalTask::query()
            ->where('company_id', $user->company_id)
            ->where(fn ($q) => $q->where('creator_user_id', $user->id)->orWhere('assignee_user_id', $user->id))
            ->with(['creator', 'assignee'])
            ->whereRaw("search_tsv @@ websearch_to_tsquery('english', ?)", [$searchQuery])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
