<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Application\Events\ConversationReadStateBroadcast;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;

/**
 * `last_read_at` is the field unread-count derivation actually relies on
 * (architecture report §8/§12); `last_read_message_id` is kept alongside it
 * only as a UX convenience ("scroll back to here"). Broadcasts the new
 * cursor (Task 3, brief §23) — the read model itself is unchanged, this
 * only adds a realtime notice of a value that was already being persisted.
 */
final class MarkConversationReadAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, Conversation $conversation, ?string $lastReadMessageId] */
    public function execute(mixed ...$arguments): ConversationParticipant
    {
        $actor = $arguments[0] ?? null;
        $conversation = $arguments[1] ?? null;
        $lastReadMessageId = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $conversation instanceof Conversation) {
            throw new InvalidArgumentException('MarkConversationReadAction::execute expects (User $actor, Conversation $conversation, ?string $lastReadMessageId).');
        }

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->first();

        if ($participant === null) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }

        $attributes = ['last_read_at' => now()];

        if ($lastReadMessageId !== null) {
            $message = Message::query()->find($lastReadMessageId);

            if ($message !== null && $message->conversation_id === $conversation->id) {
                $attributes['last_read_message_id'] = $message->id;
            }
        }

        $participant->update($attributes);

        ConversationReadStateBroadcast::dispatch(
            $conversation->id,
            $actor->id,
            $participant->last_read_at?->toIso8601String(),
        );

        return $participant;
    }
}
