<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Collaboration\Domain\Models\ConversationParticipant
 */
final class ConversationParticipantResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'role' => $this->role->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'left_at' => $this->left_at?->toIso8601String(),
            'last_read_at' => $this->last_read_at?->toIso8601String(),
            'last_read_message_id' => $this->last_read_message_id,
        ];
    }
}
