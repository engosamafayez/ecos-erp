<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Policies;

use App\Models\User;
use Modules\Collaboration\Domain\Enums\ParticipantRole;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;

/**
 * Conversation access is participation-gated, not permission-gated
 * (architecture report §8) — there is no "collaboration.conversations.view"
 * permission to check. Registered via `Gate::policy()` in
 * CollaborationServiceProvider and actually invoked from every controller
 * action below (unlike Organization\Teams\TeamPolicy, which is registered
 * but never called — see architecture report §2).
 */
final class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->activeRoleFor($user, $conversation) !== null;
    }

    public function sendMessage(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function manageParticipants(User $user, Conversation $conversation): bool
    {
        return $this->activeRoleFor($user, $conversation) === ParticipantRole::Owner;
    }

    private function activeRoleFor(User $user, Conversation $conversation): ?ParticipantRole
    {
        /** @var ConversationParticipant|null $row */
        $row = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        return $row?->role;
    }
}
