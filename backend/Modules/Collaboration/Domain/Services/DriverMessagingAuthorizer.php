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
 * §14: employee→driver messaging needs BOTH the collaboration permission
 * AND IAM's canonical Data Scope Engine — never a permission alone, and
 * never a Collaboration-specific substitute for either mechanism. Centralised
 * here so no Action can add a driver to a conversation while accidentally
 * skipping the check.
 *
 * A no-op for a target who is not a linked driver — ordinary employee-to-
 * employee messaging is unaffected.
 */
final class DriverMessagingAuthorizer
{
    private const PERMISSION = 'collaboration.conversations.message_drivers';

    private const SCOPE_RESOURCE = 'logistics.drivers';

    public function __construct(
        private readonly AuthorizationGatewayInterface $authorizationGateway,
        private readonly ScopeResolverInterface $scopeResolver,
    ) {}

    /**
     * @throws AuthorizationException when $actor lacks the permission, or the
     *                                 target driver falls outside $actor's resolved data scope
     */
    public function assertCanAddress(User $actor, User $target): void
    {
        $driver = Driver::query()->where('user_id', $target->id)->first();

        if ($driver === null) {
            return;
        }

        // Throws AuthorizationException itself on denial (IAM's own contract).
        $this->authorizationGateway->authorize($actor, self::PERMISSION);

        $inScope = Driver::query()
            ->scopedTo($actor, self::SCOPE_RESOURCE)
            ->whereKey($driver->id)
            ->exists();

        if (! $inScope) {
            throw new AuthorizationException('This driver is outside your authorized scope.');
        }
    }
}
