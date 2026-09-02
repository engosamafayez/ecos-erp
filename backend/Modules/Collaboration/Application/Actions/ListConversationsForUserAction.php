<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;

/**
 * Unread count is derived from `last_read_at` against `messages.created_at`
 * (architecture report §8/§12) — a ReadCursor, not a per-message read-receipt
 * row per participant. A user's own messages never count as unread for them.
 */
final class ListConversationsForUserAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $user] */
    public function execute(mixed ...$arguments): Collection
    {
        $user = $arguments[0] ?? null;

        if (! $user instanceof User) {
            throw new InvalidArgumentException('ListConversationsForUserAction::execute expects (User $user).');
        }

        $participantRows = ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->get()
            ->keyBy('conversation_id');

        if ($participantRows->isEmpty()) {
            return collect();
        }

        $conversations = Conversation::query()
            ->whereIn('id', $participantRows->keys())
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        return $conversations->map(function (Conversation $conversation) use ($participantRows): Conversation {
            $participant = $participantRows->get($conversation->id);
            $unreadSince = $participant->last_read_at ?? $participant->joined_at;

            $unreadCount = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_user_id', '!=', $participant->user_id)
                ->where('created_at', '>', $unreadSince)
                ->count();

            $conversation->setAttribute('unread_count', $unreadCount);
            $conversation->setAttribute('my_participant', $participant);

            return $conversation;
        });
    }
}
