<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\ManagesParticipants;
use Modules\Collaboration\Domain\Enums\ConversationType;
use Modules\Collaboration\Domain\Enums\ParticipantRole;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Services\DriverMessagingAuthorizer;

/**
 * Only a Collaboration Group's owner may add members (architecture report
 * §7/§8 — "creator is implicit admin"); direct conversations have a fixed,
 * immutable membership of two and are never a valid target here.
 */
final class AddGroupParticipantAction extends BaseAction
{
    use ManagesParticipants;

    public function __construct(
        private readonly DriverMessagingAuthorizer $driverMessagingAuthorizer,
    ) {}

    /** @param  mixed  ...$arguments  [User $actor, Conversation $conversation, int $targetUserId] */
    public function execute(mixed ...$arguments): ConversationParticipant
    {
        [$actor, $conversation, $targetUserId] = $arguments;

        if (! $actor instanceof User || ! $conversation instanceof Conversation || ! is_int($targetUserId)) {
            throw new InvalidArgumentException('AddGroupParticipantAction::execute expects (User $actor, Conversation $conversation, int $targetUserId).');
        }

        if ($conversation->type !== ConversationType::Group) {
            throw new InvalidArgumentException('Participants can only be added to a Collaboration Group conversation.');
        }

        $ownerRow = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->first();

        if ($ownerRow === null || $ownerRow->role !== ParticipantRole::Owner) {
            throw new AuthorizationException('Only the group owner may add members.');
        }

        $target = User::query()
            ->where('id', $targetUserId)
            ->where('company_id', $conversation->company_id)
            ->firstOrFail();

        $this->driverMessagingAuthorizer->assertCanAddress($actor, $target);

        return $this->ensureActiveParticipant($conversation, $target->id, ParticipantRole::Member);
    }
}
