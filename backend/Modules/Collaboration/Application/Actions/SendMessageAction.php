<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Documents\DocumentService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Modules\Collaboration\Application\DTO\SendMessageData;
use Modules\Collaboration\Application\Events\MessageBroadcast;
use Modules\Collaboration\Application\Notifications\MentionedNotification;
use Modules\Collaboration\Application\Notifications\NewMessageNotification;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Domain\Exceptions\CollaborationException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Collaboration\Domain\Models\MessageMention;
use Modules\Collaboration\Domain\Models\VoiceMetadata;

/**
 * Handles every message type (text/image/file/voice) and both a plain send
 * and a reply (an optional `replyToMessageId` on the same DTO) — one message
 * engine, not four (brief §3). Messages are immutable once created: this
 * action has no update/delete counterpart (§9).
 *
 * Media/voice storage reuses `App\Core\Documents\DocumentService` — the
 * canonical secure storage authority (private disk, no public URL) — never
 * a Collaboration-owned filesystem path. The document is attached *inside*
 * the same transaction that creates the Message row (brief §10): if the
 * attach fails, the Message row rolls back with it, so a failed upload can
 * never leave a visible, retrievable "empty" message behind. A file that
 * physically landed on disk moments before such a failure is an inert
 * orphan — nothing in the schema points to it — matching the "a lingering
 * unreferenced row/file is the safe failure" posture RbacSeeder's own
 * docblock already established for this codebase.
 */
final class SendMessageAction extends BaseAction
{
    private const MEDIA_TYPES = [MessageType::Image, MessageType::File, MessageType::Voice];

    public function __construct(private readonly DocumentService $documents) {}

    /** @param  mixed  ...$arguments  [SendMessageData $data] */
    public function execute(mixed ...$arguments): Message
    {
        $data = $arguments[0] ?? null;

        if (! $data instanceof SendMessageData) {
            throw new InvalidArgumentException('SendMessageAction::execute expects a SendMessageData.');
        }

        if (in_array($data->type, self::MEDIA_TYPES, true) && $data->file === null) {
            throw new InvalidArgumentException("A file is required for message type '{$data->type->value}'.");
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

        $message = DB::transaction(function () use ($data, $conversation): Message {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $data->senderUserId,
                'type' => $data->type,
                'body' => $data->body,
                'reply_to_message_id' => $data->replyToMessageId,
                'created_at' => now(),
            ]);

            if ($data->file !== null) {
                $document = $this->documents->attach(
                    companyId: $conversation->company_id,
                    subjectType: 'CollaborationMessage',
                    subjectId: $message->id,
                    documentType: $data->type->value,
                    file: $data->file,
                    uploadedBy: $data->senderUserId,
                );

                if ($data->type === MessageType::Voice) {
                    VoiceMetadata::query()->create([
                        'document_id' => $document->id,
                        'duration_seconds' => $data->voiceDurationSeconds,
                        'format' => $data->file->getMimeType(),
                        'created_at' => now(),
                    ]);
                }
            }

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

        $this->broadcastAndNotify($message, $conversation, $activeParticipantIds, $data->mentionedUserIds);

        return $message;
    }

    /**
     * Fired only after the transaction above has committed — a broadcast or
     * notification for a message that was subsequently rolled back would be
     * worse than a missed one. A mentioned user gets ONLY the mention
     * notification, never also the generic one, for the same message
     * (brief §16 — no duplicate notifications for the same event).
     *
     * @param  list<int>  $activeParticipantIds
     * @param  list<int>  $mentionedUserIds
     */
    private function broadcastAndNotify(Message $message, Conversation $conversation, array $activeParticipantIds, array $mentionedUserIds): void
    {
        MessageBroadcast::dispatch($message);

        $recipientIds = array_values(array_diff($activeParticipantIds, [$message->sender_user_id]));

        if ($recipientIds === []) {
            return;
        }

        $mentioned = User::query()->whereIn('id', array_intersect($recipientIds, $mentionedUserIds))->get();
        $others = User::query()->whereIn('id', array_diff($recipientIds, $mentionedUserIds))->get();

        if ($mentioned->isNotEmpty()) {
            Notification::send($mentioned, new MentionedNotification($message));
        }

        if ($others->isNotEmpty()) {
            Notification::send($others, new NewMessageNotification($message));
        }
    }
}
