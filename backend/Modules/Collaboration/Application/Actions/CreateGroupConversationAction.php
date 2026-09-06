<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\ManagesParticipants;
use Modules\Collaboration\Domain\Enums\ConversationType;
use Modules\Collaboration\Domain\Enums\ParticipantRole;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Services\DriverMessagingAuthorizer;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\Organization\Teams\Domain\Models\Team;

/**
 * Creates a Collaboration Group (architecture report §7) — an ad-hoc,
 * Collaboration-owned conversation. `$teamId`, if given, is a read-only
 * label reference to an Organizational Team, never a membership source
 * (ADR-011 forbids redefining Team; the two concepts stay strictly
 * separate — see ADR-044 CTO Ratification §2).
 */
final class CreateGroupConversationAction extends BaseAction
{
    use ManagesParticipants;

    public function __construct(
        private readonly AuthorizationGatewayInterface $authorizationGateway,
        private readonly DriverMessagingAuthorizer $driverMessagingAuthorizer,
    ) {}

    /**
     * @param  mixed  ...$arguments  [User $actor, string $title, list<int> $participantUserIds, ?string $teamId]
     */
    public function execute(mixed ...$arguments): Conversation
    {
        $actor = $arguments[0] ?? null;
        $title = $arguments[1] ?? null;
        $participantUserIds = $arguments[2] ?? null;
        $teamId = $arguments[3] ?? null;

        if (! $actor instanceof User || ! is_string($title) || $title === '' || ! is_array($participantUserIds)) {
            throw new InvalidArgumentException('CreateGroupConversationAction::execute expects (User $actor, string $title, array $participantUserIds, ?string $teamId).');
        }

        // inspect() (not authorize()): authorize()/can() do not carry the
        // platform's is_system bypass, only inspect()/decision() do (ADR-038
        // Part 1) — without this, an is_system actor is wrongly denied here.
        if ($this->authorizationGateway->inspect($actor, 'collaboration.groups.create')->isDenied()) {
            throw new AuthorizationException('This action is unauthorized (collaboration.groups.create).');
        }

        // Scoped to the actor's own company, exactly like direct-conversation
        // target resolution — a foreign-company id is silently dropped rather
        // than trusted.
        $memberIds = User::query()
            ->where('company_id', $actor->company_id)
            ->whereIn('id', array_values(array_unique(array_map('intval', $participantUserIds))))
            ->get();

        foreach ($memberIds as $member) {
            if ($member->id !== $actor->id) {
                $this->driverMessagingAuthorizer->assertCanAddress($actor, $member);
            }
        }

        if ($teamId !== null) {
            Team::query()->where('company_id', $actor->company_id)->findOrFail($teamId);
        }

        return DB::transaction(function () use ($actor, $title, $memberIds, $teamId): Conversation {
            $conversation = Conversation::query()->create([
                'company_id' => $actor->company_id,
                'type' => ConversationType::Group,
                'title' => $title,
                'created_by_user_id' => $actor->id,
                'team_id' => $teamId,
            ]);

            $this->ensureActiveParticipant($conversation, $actor->id, ParticipantRole::Owner);

            foreach ($memberIds as $member) {
                if ($member->id !== $actor->id) {
                    $this->ensureActiveParticipant($conversation, $member->id, ParticipantRole::Member);
                }
            }

            return $conversation;
        });
    }
}
