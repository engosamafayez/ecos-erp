<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Collaboration\Domain\Services\SearchQueryExpander;

/**
 * MySQL-native full-text search (ADR-044 §9, brief §19-21) — no Scout, no
 * Meilisearch. Remediation of the original PostgreSQL tsvector/websearch_to_
 * tsquery design for MySQL 8.4, the authoritative ECOS database (see
 * TASK-ECOS-INTERNAL-COLLABORATION-MYSQL-MIGRATION-REMEDIATION-003 and the
 * 2026_09_02_100008 migration's docblock).
 *
 * BOOLEAN MODE, via the shared SearchQueryExpander (TASK-ECOS-INTERNAL-
 * COLLABORATION-MYSQL-SEARCH-STEMMING-REMEDIATION-003-R1): MySQL FULLTEXT
 * has no built-in stemmer, so a plural query like "shipments" would not
 * match a stored singular "shipment" the way the original PostgreSQL design
 * did. The expander turns the raw query into the raw term(s) plus bounded
 * singular/plural alternates, extracted as plain word tokens — never the
 * user's raw punctuation — so the resulting AGAINST() string is always safe
 * boolean-mode input, with no operator-injection risk. The original
 * contract still defines no ranking beyond `orderByDesc('created_at')`, so
 * BOOLEAN MODE's default (space-separated terms = OR, no relevance scoring
 * concerns) changes nothing observable beyond adding the stemmed matches.
 *
 * Authorization comes first, structurally: the participant scope is
 * resolved and applied to the query *before* the search predicate runs, so
 * a conversation the actor cannot access is never a candidate row in the
 * first place (brief §20 — never "search globally, filter after"). Tenant/
 * company isolation follows for free — a user can only ever be an active
 * participant of a conversation in their own company (Task 2).
 *
 * Optional single-conversation scoping (architecture report §19, TASK-ECOS-
 * INTERNAL-COLLABORATION-CHAT-FINAL-IMPLEMENTATION-002): when a
 * `$conversationId` is given, this narrows to that one conversation instead
 * of gathering every conversation the caller participates in — the same
 * search index and query shape, just a tighter `WHERE`, with its own
 * explicit participation check (an actor who isn't a member of that specific
 * conversation is refused outright, not silently given zero results).
 */
final class SearchMessagesAction extends BaseAction
{
    public function __construct(
        private readonly SearchQueryExpander $queryExpander,
    ) {}

    /** @param  mixed  ...$arguments  [User $user, string $query, int $limit, ?string $conversationId] */
    public function execute(mixed ...$arguments): Collection
    {
        $user = $arguments[0] ?? null;
        $searchQuery = $arguments[1] ?? null;
        $limit = $arguments[2] ?? 20;
        $conversationId = $arguments[3] ?? null;

        if (! $user instanceof User || ! is_string($searchQuery) || trim($searchQuery) === '') {
            throw new InvalidArgumentException('SearchMessagesAction::execute expects (User $user, string $query, int $limit, ?string $conversationId).');
        }

        if ($conversationId !== null) {
            $isParticipant = ConversationParticipant::query()
                ->where('conversation_id', $conversationId)
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->exists();

            if (! $isParticipant) {
                throw new AuthorizationException('You are not a participant of this conversation.');
            }

            $conversationIds = collect([$conversationId]);
        } else {
            $conversationIds = ConversationParticipant::query()
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->pluck('conversation_id');

            if ($conversationIds->isEmpty()) {
                return new Collection;
            }
        }

        return Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->whereRaw('MATCH(body) AGAINST(? IN BOOLEAN MODE)', [$this->queryExpander->toBooleanQueryString($searchQuery)])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
