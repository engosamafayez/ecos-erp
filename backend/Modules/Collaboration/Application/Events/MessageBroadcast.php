<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Domain\Models\Message;

/**
 * Near-realtime delivery (ADR-044 §1.8). Uses only core `laravel/framework`
 * broadcasting contracts — no dependency on the Reverb package itself, so
 * this class is fully functional (and testable) with the 'log'/'null'
 * driver today, and requires zero code changes once Reverb is actually
 * installed and BROADCAST_CONNECTION is switched (see engineering report
 * §14 for the exact runtime-dependency status).
 *
 * Payload is deliberately minimal (architecture report §14 / brief §14):
 * text body is safe to include (every recipient is already authorized to
 * read it), but media messages carry only `has_attachment` — never a
 * document id, storage path, or URL. A client fetches media through the
 * existing authorized MessageAttachmentController endpoint, which re-derives
 * the document from the message id itself.
 */
final class MessageBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("collaboration.conversation.{$this->message->conversation_id}");
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_user_id' => $this->message->sender_user_id,
            'type' => $this->message->type->value,
            'body' => $this->message->type === MessageType::Text ? $this->message->body : null,
            'reply_to_message_id' => $this->message->reply_to_message_id,
            'has_attachment' => in_array($this->message->type, [MessageType::Image, MessageType::File, MessageType::Voice], true),
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
