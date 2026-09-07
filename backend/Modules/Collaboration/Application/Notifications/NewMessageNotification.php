<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Domain\Models\Message;

/**
 * Sent to every OTHER active participant of the conversation except anyone who was
 * individually @mentioned (they get MentionedNotification instead, never both — §16).
 *
 * TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005 D2 — this
 * class's own prior docblock said "Stock Laravel notification, `database` channel...
 * no 'Enterprise Notification Platform' exists to call into instead", true when written
 * but stale since: the shared producer contract's channel now exists
 * (Modules\Notifications\...\CoreDatabaseChannel, TASK-ECOS-NOTIFICATIONS-FOUNDATION-002)
 * and this type is in the Notification Type Catalog with `userCanDisable: true` — but
 * routing through the stock `database` channel meant that toggle was silently never
 * consulted (NotificationDeliveryPolicy::isTypeEnabledFor() runs inside
 * CoreDatabaseChannel::send(), never Laravel's own DatabaseChannel). Fixed by moving
 * onto the existing shared channel — not a second one.
 */
final class NewMessageNotification extends Notification
{
    public function __construct(private readonly Message $message) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['notifications-core'];
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
