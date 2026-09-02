<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Enums\AttachedToType;
use Modules\Collaboration\Domain\Enums\OperationalContextType;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Collaboration\Domain\Models\OperationalContextLink;

/**
 * Foundation only (architecture report §13/§19): records that a
 * conversation, message, or (Task 4) task relates to an operational entity
 * elsewhere in ECOS. Never fetches or exposes that entity's own data — a
 * caller who wants Order/Trip/Distribution Group/Driver details still goes
 * through that entity's own authorized endpoint (brief §20/§21).
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

        match ($attachedToType) {
            AttachedToType::Conversation, AttachedToType::Message => $this->assertConversationAccess($actor, $attachedToType, $attachedToId),
            AttachedToType::Task => $this->assertTaskAccess($actor, $attachedToId),
        };

        return OperationalContextLink::query()->create([
            'context_type' => $contextType,
            'context_id' => $contextId,
            'attached_to_type' => $attachedToType,
            'attached_to_id' => $attachedToId,
            'created_by_user_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function assertConversationAccess(User $actor, AttachedToType $attachedToType, string $attachedToId): void
    {
        $conversationId = $attachedToType === AttachedToType::Message
            ? Message::query()->findOrFail($attachedToId)->conversation_id
            : $attachedToId;

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isParticipant) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }
    }

    /**
     * Task access is ownership-based (creator or assignee), not
     * participation-based — a task need not have a source conversation at
     * all (brief §14's "authorize BOTH task access and conversation/message
     * access" is enforced by this method being independent of, not derived
     * from, conversation participation).
     */
    private function assertTaskAccess(User $actor, string $taskId): void
    {
        $task = InternalTask::query()->findOrFail($taskId);

        if ($task->creator_user_id !== $actor->id && $task->assignee_user_id !== $actor->id) {
            throw new AuthorizationException('You do not have access to this task.');
        }
    }
}
