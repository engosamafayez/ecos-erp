<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Collaboration\Domain\Models\Message;

final class MentionedNotification extends Notification
{
    public function __construct(private readonly Message $message) {}

    /**
     * TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005 D2 — the
     * shared producer contract's channel, not Laravel's stock `database` one; see
     * NewMessageNotification::via() for why (the catalog's per-type toggle otherwise
     * silently has no effect on this type).
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['notifications-core'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type' => 'collaboration_mention',
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
            'sender_user_id' => $this->message->sender_user_id,
            'message' => 'You were mentioned',
            'preview' => mb_strimwidth((string) $this->message->body, 0, 140, '…'),
        ];
    }
}
