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
 * PostgreSQL full-text search (ADR-044 §9, brief §19-21) — no Scout, no
 * Meilisearch. Authorization comes first, structurally: the participant
 * scope is resolved and applied to the query *before* the search predicate
 * runs, so a conversation the actor cannot access is never a candidate row
 * in the first place (brief §20 — never "search globally, filter after").
 * Tenant/company isolation follows for free — a user can only ever be an
 * active participant of a conversation in their own company (Task 2).
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
            ->whereRaw("body_tsv @@ websearch_to_tsquery('english', ?)", [$searchQuery])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
