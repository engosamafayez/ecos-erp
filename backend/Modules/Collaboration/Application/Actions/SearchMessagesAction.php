<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;

/**
 * MySQL-native full-text search (ADR-044 §9, brief §19-21) — no Scout, no
 * Meilisearch. Remediation of the original PostgreSQL tsvector/websearch_to_
 * tsquery design for MySQL 8.4, the authoritative ECOS database (see
 * TASK-ECOS-INTERNAL-COLLABORATION-MYSQL-MIGRATION-REMEDIATION-003 and the
 * 2026_09_02_100008 migration's docblock). NATURAL LANGUAGE MODE is used
 * deliberately over BOOLEAN MODE: the original contract defines no ranking
 * beyond `orderByDesc('created_at')`, so no operator syntax (+/-/".../*) is
 * needed, and free-text user input is never at risk of being misparsed as
 * a boolean operator.
 *
 * Authorization comes first, structurally: the participant scope is
 * resolved and applied to the query *before* the search predicate runs, so
 * a conversation the actor cannot access is never a candidate row in the
 * first place (brief §20 — never "search globally, filter after"). Tenant/
 * company isolation follows for free — a user can only ever be an active
 * participant of a conversation in their own company (Task 2).
 */
final class SearchMessagesAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $user, string $query, int $limit] */
    public function execute(mixed ...$arguments): Collection
    {
        $user = $arguments[0] ?? null;
        $searchQuery = $arguments[1] ?? null;
        $limit = $arguments[2] ?? 20;

        if (! $user instanceof User || ! is_string($searchQuery) || trim($searchQuery) === '') {
            throw new InvalidArgumentException('SearchMessagesAction::execute expects (User $user, string $query, int $limit).');
        }

        $authorizedConversationIds = ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->pluck('conversation_id');

        if ($authorizedConversationIds->isEmpty()) {
            return new Collection();
        }

        return Message::query()
            ->whereIn('conversation_id', $authorizedConversationIds)
            ->whereRaw('MATCH(body) AGAINST(? IN NATURAL LANGUAGE MODE)', [$searchQuery])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
