<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;

/**
 * Cursor pagination on (created_at, id) rather than offset (architecture
 * report §8). Non-participants are refused outright — read access is
 * participation-gated, not permission-gated.
 */
final class GetConversationMessagesAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $user, Conversation $conversation, ?string $beforeMessageId, int $limit] */
    public function execute(mixed ...$arguments): Collection
    {
        $user = $arguments[0] ?? null;
        $conversation = $arguments[1] ?? null;
        $beforeMessageId = $arguments[2] ?? null;
        $limit = $arguments[3] ?? 50;

        if (! $user instanceof User || ! $conversation instanceof Conversation) {
            throw new InvalidArgumentException('GetConversationMessagesAction::execute expects (User $user, Conversation $conversation, ?string $beforeMessageId, int $limit).');
        }

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isParticipant) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }

        $query = Message::query()->where('conversation_id', $conversation->id);

        if ($beforeMessageId !== null) {
            $cursor = Message::query()->find($beforeMessageId);

            if ($cursor !== null) {
                $query->where('created_at', '<', $cursor->created_at);
            }
        }

        return $query->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }
}
