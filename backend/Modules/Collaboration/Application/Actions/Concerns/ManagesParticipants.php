<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions\Concerns;

use Modules\Collaboration\Domain\Enums\ParticipantRole;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;

/**
 * Shared by every action that puts a user into a conversation. "Get or
 * create" a direct conversation, or re-adding someone to a group, must
 * reactivate the existing membership row rather than insert a duplicate or
 * reset an already-active one — see architecture report §8 ("membership
 * lifecycle").
 */
trait ManagesParticipants
{
    private function ensureActiveParticipant(Conversation $conversation, int $userId, ParticipantRole $role): ConversationParticipant
    {
        /** @var ConversationParticipant|null $participant */
        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        if ($participant === null) {
            return ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
                'role' => $role,
                'joined_at' => now(),
            ]);
        }

        if ($participant->left_at !== null) {
            $participant->update(['left_at' => null, 'joined_at' => now()]);
        }

        return $participant;
    }

    private function isActiveParticipant(Conversation $conversation, int $userId): bool
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }
}
