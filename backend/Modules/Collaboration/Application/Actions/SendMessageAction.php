<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Collaboration\Application\DTO\SendMessageData;
use Modules\Collaboration\Domain\Exceptions\CollaborationException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Collaboration\Domain\Models\MessageMention;

/**
 * Handles both a plain send and a reply (an optional `replyToMessageId` on
 * the same DTO) — a reply is not a different operation, just a message with
 * a parent pointer (architecture report §10). Messages are immutable once
 * created: this action has no update/delete counterpart (§9).
 */
final class SendMessageAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [SendMessageData $data] */
    public function execute(mixed ...$arguments): Message
    {
        $data = $arguments[0] ?? null;

        if (! $data instanceof SendMessageData) {
            throw new InvalidArgumentException('SendMessageAction::execute expects a SendMessageData.');
        }

        $conversation = Conversation::query()->findOrFail($data->conversationId);

        // Defense in depth: the controller's policy check is the primary gate,
        // this makes the action itself safe regardless of caller.
        $isSender = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $data->senderUserId)
            ->whereNull('left_at')
            ->exists();

        if (! $isSender) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }

        if ($data->replyToMessageId !== null) {
            $replyTarget = Message::query()->find($data->replyToMessageId);

            if ($replyTarget === null || $replyTarget->conversation_id !== $conversation->id) {
                throw CollaborationException::crossConversationReply($data->replyToMessageId, $conversation->id);
            }
        }

        $activeParticipantIds = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->pluck('user_id')
            ->all();

        foreach ($data->mentionedUserIds as $mentionedUserId) {
            if (! in_array($mentionedUserId, $activeParticipantIds, true)) {
                throw CollaborationException::invalidMention($mentionedUserId);
            }
        }

        return DB::transaction(function () use ($data, $conversation): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $data->senderUserId,
                'type' => $data->type,
                'body' => $data->body,
                'reply_to_message_id' => $data->replyToMessageId,
                'created_at' => now(),
            ]);

            foreach (array_unique($data->mentionedUserIds) as $mentionedUserId) {
                MessageMention::query()->create([
                    'message_id' => $message->id,
                    'mentioned_user_id' => $mentionedUserId,
                    'created_at' => now(),
                ]);
            }

            $conversation->update(['last_message_at' => $message->created_at]);

            return $message;
        });
    }
}
