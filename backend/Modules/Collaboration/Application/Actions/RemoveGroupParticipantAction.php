<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Enums\ConversationType;
use Modules\Collaboration\Domain\Enums\ParticipantRole;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;

/**
 * A member may always remove themself ("leave"); removing someone else
 * requires the group owner. Soft-leave only (`left_at`) — the row stays for
 * history (architecture report §8).
 */
final class RemoveGroupParticipantAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, Conversation $conversation, int $targetUserId] */
    public function execute(mixed ...$arguments): void
    {
        [$actor, $conversation, $targetUserId] = $arguments;

        if (! $actor instanceof User || ! $conversation instanceof Conversation || ! is_int($targetUserId)) {
            throw new InvalidArgumentException('RemoveGroupParticipantAction::execute expects (User $actor, Conversation $conversation, int $targetUserId).');
        }

        if ($conversation->type !== ConversationType::Group) {
            throw new InvalidArgumentException('Participants can only be removed from a Collaboration Group conversation.');
        }

        $actorRow = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->first();

        $isSelfRemoval = $targetUserId === $actor->id;

        if (! $isSelfRemoval && ($actorRow === null || $actorRow->role !== ParticipantRole::Owner)) {
            throw new AuthorizationException('Only the group owner may remove another member.');
        }

        if ($isSelfRemoval && $actorRow === null) {
            throw new AuthorizationException('You are not a member of this conversation.');
        }

        $targetRow = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $targetUserId)
            ->whereNull('left_at')
            ->firstOrFail();

        $targetRow->update(['left_at' => now()]);
    }
}
