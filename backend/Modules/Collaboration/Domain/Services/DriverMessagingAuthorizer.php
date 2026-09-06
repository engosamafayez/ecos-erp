<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\IAM\Domain\Contracts\ScopeResolverInterface;
use Modules\Logistics\Drivers\Domain\Models\Driver;

/**
 * The single place Collaboration enforces ADR-044 §1.6 / architecture report
 * §14 for every employee->driver interaction: messaging (Task 2) and now
 * task assignment (Task 4, brief §9) — both need BOTH a permission AND
 * IAM's canonical Data Scope Engine, never a permission alone, and never a
 * Collaboration-specific substitute for either. Two public methods share
 * one private check, parameterised only by which permission applies —
 * assignment gets its own token (`collaboration.tasks.assign_drivers`)
 * rather than reusing the messaging one, since a company may reasonably
 * want to grant one without the other.
 *
 * A no-op for a target who is not a linked driver — ordinary employee-to-
 * employee interaction is unaffected either way.
 */
final class DriverMessagingAuthorizer
{
    private const MESSAGE_PERMISSION = 'collaboration.conversations.message_drivers';

    private const ASSIGN_PERMISSION = 'collaboration.tasks.assign_drivers';

    private const SCOPE_RESOURCE = 'logistics.drivers';

    public function __construct(
        private readonly AuthorizationGatewayInterface $authorizationGateway,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    /** @throws AuthorizationException */
    public function assertCanAddress(User $actor, User $target): void
    {
        $this->assertDriverPermissionAndScope($actor, $target, self::MESSAGE_PERMISSION);
    }

    /** @throws AuthorizationException */
    public function assertCanAssign(User $actor, User $target): void
    {
        $this->assertDriverPermissionAndScope($actor, $target, self::ASSIGN_PERMISSION);
    }

    private function assertDriverPermissionAndScope(User $actor, User $target, string $permission): void
    {
        $driver = Driver::query()->where('user_id', $target->id)->first();

        if ($driver === null) {
            return;
        }

        // inspect(), not authorize()/can(): those two do not carry the platform's
        // is_system bypass, only inspect()/decision() do (ADR-038 Part 1) —
        // without this, an is_system actor is wrongly denied here.
        if ($this->authorizationGateway->inspect($actor, $permission)->isDenied()) {
            throw new AuthorizationException(sprintf('This action is unauthorized (%s).', $permission));
        }

        $inScope = Driver::query()
            ->scopedTo($actor, self::SCOPE_RESOURCE)
            ->whereKey($driver->id)
            ->exists();

        if (! $inScope) {
            throw new AuthorizationException('This driver is outside your authorized scope.');
        }
    }
}
