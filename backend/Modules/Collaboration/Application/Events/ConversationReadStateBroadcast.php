<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lets other active clients on the same conversation (read-receipt UI for
 * other participants, or the reading user's own other open tabs/devices)
 * pick up a read-cursor change without polling. Carries nothing beyond the
 * cursor itself — see MessageBroadcast's docblock for the same broadcasting-
 * without-Reverb-installed reasoning.
 */
final class ConversationReadStateBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $conversationId,
        public readonly int $userId,
        public readonly ?string $lastReadAt,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("collaboration.conversation.{$this->conversationId}");
    }

    public function broadcastAs(): string
    {
        return 'read-state.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'last_read_at' => $this->lastReadAt,
        ];
    }
}
