<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Collaboration\Domain\Models\Conversation
 */
final class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Collaboration\Domain\Models\ConversationParticipant|null $myParticipant */
        $myParticipant = $this->getAttribute('my_participant');

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'type' => $this->type->value,
            'title' => $this->title,
            'created_by_user_id' => $this->created_by_user_id,
            'team_id' => $this->team_id,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'unread_count' => $this->getAttribute('unread_count'),
            'my_role' => $myParticipant?->role?->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
