<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;

/**
 * Mute is per-participant, per-conversation, and purely a notification
 * preference (architecture report §21) — it never affects unread-count
 * derivation, message history, or broadcast delivery, only whether
 * SendMessageAction::broadcastAndNotify() sends the generic
 * NewMessageNotification for this participant. Reuses the existing
 * ConversationParticipant row (same participation-gated pattern as
 * MarkConversationReadAction) rather than a new table or engine.
 */
final class MuteConversationAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, Conversation $conversation, bool $muted] */
    public function execute(mixed ...$arguments): ConversationParticipant
    {
        $actor = $arguments[0] ?? null;
        $conversation = $arguments[1] ?? null;
        $muted = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $conversation instanceof Conversation || ! is_bool($muted)) {
            throw new InvalidArgumentException('MuteConversationAction::execute expects (User $actor, Conversation $conversation, bool $muted).');
        }

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->first();

        if ($participant === null) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }

        $participant->update(['muted_at' => $muted ? now() : null]);

        return $participant;
    }
}
