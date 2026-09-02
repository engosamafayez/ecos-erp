<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Collaboration\Domain\Models\Message
 */
final class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_user_id' => $this->sender_user_id,
            'type' => $this->type->value,
            'body' => $this->body,
            'reply_to_message_id' => $this->reply_to_message_id,
            'mentioned_user_ids' => $this->whenLoaded('mentions', fn () => $this->mentions->pluck('mentioned_user_id')->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
