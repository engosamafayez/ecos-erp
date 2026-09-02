<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Enums\AttachedToType;
use Modules\Collaboration\Domain\Enums\OperationalContextType;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Collaboration\Domain\Models\OperationalContextLink;

/**
 * Foundation only (architecture report §13/§19): records that a conversation
 * or message relates to an operational entity elsewhere in ECOS. Never
 * fetches or exposes that entity's own data — a caller who wants Order/Trip/
 * Distribution Group/Driver details still goes through that entity's own
 * authorized endpoint.
 */
final class AttachOperationalContextAction extends BaseAction
{
    /**
     * @param  mixed  ...$arguments  [User $actor, AttachedToType $attachedToType, string $attachedToId, OperationalContextType $contextType, string $contextId]
     */
    public function execute(mixed ...$arguments): OperationalContextLink
    {
        [$actor, $attachedToType, $attachedToId, $contextType, $contextId] = $arguments;

        if (
            ! $actor instanceof User
            || ! $attachedToType instanceof AttachedToType
            || ! is_string($attachedToId)
            || ! $contextType instanceof OperationalContextType
            || ! is_string($contextId)
        ) {
            throw new InvalidArgumentException('AttachOperationalContextAction::execute expects (User $actor, AttachedToType $attachedToType, string $attachedToId, OperationalContextType $contextType, string $contextId).');
        }

        $conversationId = match ($attachedToType) {
            AttachedToType::Conversation => $attachedToId,
            AttachedToType::Message => Message::query()->findOrFail($attachedToId)->conversation_id,
            AttachedToType::Task => throw new InvalidArgumentException('Task-attached context links are not available until Task 4.'),
        };

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isParticipant) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }

        Conversation::query()->findOrFail($conversationId);

        return OperationalContextLink::query()->create([
            'context_type' => $contextType,
            'context_id' => $contextId,
            'attached_to_type' => $attachedToType,
            'attached_to_id' => $attachedToId,
            'created_by_user_id' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
