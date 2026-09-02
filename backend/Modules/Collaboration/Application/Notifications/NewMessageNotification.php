<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Domain\Models\Message;

/**
 * Stock Laravel notification, `database` channel — the same pattern
 * `Operations\Preparation\ExceptionRaisedNotification` already uses; no
 * "Enterprise Notification Platform" exists to call into instead (verified
 * directly against source, not assumed from docs — see engineering report
 * §17). Sent to every OTHER active participant of the conversation except
 * anyone who was individually @mentioned (they get MentionedNotification
 * instead, never both — §16).
 */
final class NewMessageNotification extends Notification
{
    public function __construct(private readonly Message $message) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type' => 'collaboration_new_message',
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
            'sender_user_id' => $this->message->sender_user_id,
            'message' => 'New message',
            'preview' => $this->preview(),
        ];
    }

    /**
     * Never a raw storage path or URL (brief §17) — a fixed, safe label per
     * type for anything that isn't plain text.
     */
    private function preview(): string
    {
        return match ($this->message->type) {
            MessageType::Text => mb_strimwidth((string) $this->message->body, 0, 140, '…'),
            MessageType::Image => '[Image]',
            MessageType::File => '[File]',
            MessageType::Voice => '[Voice message]',
            MessageType::System => (string) $this->message->body,
        };
    }
}
