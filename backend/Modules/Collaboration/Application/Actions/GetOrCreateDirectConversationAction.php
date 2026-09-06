<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\ManagesParticipants;
use Modules\Collaboration\Domain\Enums\ConversationType;
use Modules\Collaboration\Domain\Enums\ParticipantRole;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Services\DriverMessagingAuthorizer;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;

/**
 * Get-or-create semantics (architecture report §8): at most one active direct
 * conversation per unordered pair, enforced by the DB-level unique index on
 * (company_id, direct_pair_key) — see the conversations migration.
 */
final class GetOrCreateDirectConversationAction extends BaseAction
{
    use ManagesParticipants;

    public function __construct(
        private readonly AuthorizationGatewayInterface $authorizationGateway,
        private readonly DriverMessagingAuthorizer $driverMessagingAuthorizer,
    ) {}

    /**
     * @param  mixed  ...$arguments  [User $actor, int $targetUserId]
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the target does not exist in the actor's own company
     */
    public function execute(mixed ...$arguments): Conversation
    {
        [$actor, $targetUserId] = $arguments;

        if (! $actor instanceof User || ! is_int($targetUserId)) {
            throw new InvalidArgumentException('GetOrCreateDirectConversationAction::execute expects (User $actor, int $targetUserId).');
        }

        if ($targetUserId === $actor->id) {
            throw new InvalidArgumentException('A user cannot start a direct conversation with themself.');
        }

        // inspect() (not authorize()): authorize()/can() do not carry the
        // platform's is_system bypass, only inspect()/decision() do (ADR-038
        // Part 1) — without this, an is_system actor is wrongly denied here.
        if ($this->authorizationGateway->inspect($actor, 'collaboration.conversations.create')->isDenied()) {
            throw new AuthorizationException('This action is unauthorized (collaboration.conversations.create).');
        }

        // Scoped to the actor's own company: a cross-company id behaves exactly
        // like a nonexistent one, so no cross-tenant existence is ever leaked
        // (architecture report §14/§15 — fail closed).
        $target = User::query()
            ->where('id', $targetUserId)
            ->where('company_id', $actor->company_id)
            ->firstOrFail();

        $this->driverMessagingAuthorizer->assertCanAddress($actor, $target);

        $pairKey = Conversation::directPairKey($actor->id, $target->id);

        return DB::transaction(function () use ($actor, $target, $pairKey): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                [
                    'company_id' => $actor->company_id,
                    'type' => ConversationType::Direct,
                    'direct_pair_key' => $pairKey,
                ],
                ['created_by_user_id' => $actor->id],
            );

            $this->ensureActiveParticipant($conversation, $actor->id, ParticipantRole::Member);
            $this->ensureActiveParticipant($conversation, $target->id, ParticipantRole::Member);

            return $conversation;
        });
    }
}
