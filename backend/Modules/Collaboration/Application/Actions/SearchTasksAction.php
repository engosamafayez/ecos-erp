<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Services\SearchQueryExpander;

/**
 * Its own MySQL FULLTEXT index, deliberately separate from message search
 * (brief §23). Remediation of the original PostgreSQL tsvector design for
 * MySQL 8.4 — see 2026_09_02_100008's docblock for the full rationale.
 *
 * BOOLEAN MODE, via the shared SearchQueryExpander — see SearchMessagesAction's
 * docblock for why (TASK-ECOS-INTERNAL-COLLABORATION-MYSQL-SEARCH-STEMMING-
 * REMEDIATION-003-R1): the same stemming gap and the same safe-token
 * construction apply here, against `title`+`description` instead of `body`.
 *
 * Scoped to tasks the requester created or is assigned to — the same
 * ownership boundary every other task operation uses, applied before the
 * text predicate (never "search everything, filter after").
 */
final class SearchTasksAction extends BaseAction
{
    public function __construct(
        private readonly SearchQueryExpander $queryExpander,
    ) {}

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
            ->where(fn ($q) => $q->where('creator_user_id', $user->id)
                ->orWhere('assignee_user_id', $user->id)
                ->orWhereHas('additionalAssignees', fn ($aq) => $aq->where('user_id', $user->id)))
            ->with(['creator', 'assignee'])
            ->whereRaw('MATCH(title, description) AGAINST(? IN BOOLEAN MODE)', [$this->queryExpander->toBooleanQueryString($searchQuery)])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
