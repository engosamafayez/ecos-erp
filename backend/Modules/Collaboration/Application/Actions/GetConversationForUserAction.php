<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;

/**
 * Single-conversation counterpart of ListConversationsForUserAction — same
 * `my_participant`/`unread_count` attribute derivation (architecture report
 * §8/§12), just for the one conversation ConversationController::show()
 * already authorized via ConversationPolicy::view. Without this, the show
 * endpoint returned `my_role: null` / `unread_count: null` for every
 * conversation, unlike the list endpoint — this closes that gap rather than
 * duplicating the unread-count formula inline in the controller.
 */
final class GetConversationForUserAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $user, Conversation $conversation] */
    public function execute(mixed ...$arguments): Conversation
    {
        $user = $arguments[0] ?? null;
        $conversation = $arguments[1] ?? null;

        if (! $user instanceof User || ! $conversation instanceof Conversation) {
            throw new InvalidArgumentException('GetConversationForUserAction::execute expects (User $user, Conversation $conversation).');
        }

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if ($participant === null) {
            return $conversation;
        }

        $unreadSince = $participant->last_read_at ?? $participant->joined_at;

        $unreadCount = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_user_id', '!=', $participant->user_id)
            ->where('created_at', '>', $unreadSince)
            ->count();

        $conversation->setAttribute('unread_count', $unreadCount);
        $conversation->setAttribute('my_participant', $participant);

        return $conversation;
    }
}
