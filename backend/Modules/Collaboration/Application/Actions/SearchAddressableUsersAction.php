<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\IAM\Application\Services\UserRepository;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\Logistics\Drivers\Domain\Models\Driver;

/**
 * TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-EXPOSURE-CLOSURE-005 — CTO ruling: the
 * one new "discover somebody to newly address" surface this task needs. Every other
 * name-resolution need in Collaboration (sender, participants, task creator/assignee,
 * comment author, activity actor) is satisfied by embedding an ALREADY existing
 * Eloquent relation on an ALREADY authorized resource — see ConversationResource,
 * MessageResource, TaskResource, TaskCommentResource, TaskActivityResource. This
 * action exists only for the genuinely new case: finding somebody the actor has no
 * established Collaboration relationship with yet (new direct conversation, new
 * group member, task assignee/reassignment).
 *
 * Reuses Modules\IAM\Application\Services\UserRepository::search() verbatim — the
 * canonical, already-built (ADR-040) IAM user query surface — rather than a second,
 * Collaboration-owned user repository or search engine. Two Collaboration-specific
 * rules apply on top of it:
 *
 *  1. Hard company/tenant scoping (never cross a company boundary) and self-exclusion
 *     (searching to find somebody else to address, never yourself).
 *  2. A driver-linked candidate is included ONLY if the actor both holds
 *     `collaboration.conversations.message_drivers` AND the driver is within the
 *     actor's IAM data scope for `logistics.drivers` — the exact same two-part check
 *     DriverMessagingAuthorizer enforces at the point of actually starting a
 *     conversation or assigning a task, applied here too so an unauthorized driver is
 *     never even revealed as a search result ("do not leak unauthorized drivers
 *     through search"). This does NOT change, weaken, or replace
 *     DriverMessagingAuthorizer — every mutation endpoint that can address a driver
 *     still independently enforces it itself; this is defense-in-depth against
 *     information disclosure at search time, on top of that, not instead of it.
 */
final class SearchAddressableUsersAction extends BaseAction
{
    private const DRIVER_MESSAGE_PERMISSION = 'collaboration.conversations.message_drivers';

    private const DRIVER_SCOPE_RESOURCE = 'logistics.drivers';

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthorizationGatewayInterface $authorizationGateway,
    ) {}

    /** @param  mixed  ...$arguments  [User $actor, string $query, int $limit] */
    public function execute(mixed ...$arguments): Collection
    {
        $actor = $arguments[0] ?? null;
        $searchQuery = $arguments[1] ?? null;
        $limit = $arguments[2] ?? 20;

        if (! $actor instanceof User || ! is_string($searchQuery) || trim($searchQuery) === '') {
            throw new InvalidArgumentException('SearchAddressableUsersAction::execute expects (User $actor, string $query, int $limit).');
        }

        /** @var Collection<int, User> $candidates */
        $candidates = collect($this->users->search([
            'q' => $searchQuery,
            'company_id' => $actor->company_id,
            // Architecture report §8/§16 (bucket B): UserRepository::query() already
            // supports a status filter, this call site simply never passed it before —
            // "active IAM users... as the primary source" means a draft/invited/
            // inactive/suspended/locked/archived account should not surface as an
            // addressable candidate even though it isn't soft-deleted.
            'status' => UserStatus::ACTIVE->value,
        ], $limit)->items())
            ->reject(fn (User $u): bool => $u->id === $actor->id)
            ->values();

        return $this->filterAddressableDrivers($actor, $candidates);
    }

    /** @param  Collection<int, User>  $candidates
     *  @return Collection<int, User> */
    private function filterAddressableDrivers(User $actor, Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $driversByUserId = Driver::query()
            ->whereIn('user_id', $candidates->pluck('id'))
            ->get()
            ->keyBy('user_id');

        if ($driversByUserId->isEmpty()) {
            return $candidates->each(fn (User $u) => $u->setAttribute('is_driver', false))->values();
        }

        // inspect() (not can()): an is_system actor must reach the same driver
        // visibility DriverMessagingAuthorizer grants it at write time (see that
        // class's own docblock) — can()/authorize() do not carry the platform's
        // is_system bypass, only inspect()/decision() do (ADR-038 Part 1).
        $canMessageDrivers = $this->authorizationGateway->inspect($actor, self::DRIVER_MESSAGE_PERMISSION)->isAllowed();

        $inScopeDriverIds = $canMessageDrivers
            ? Driver::query()
                ->scopedTo($actor, self::DRIVER_SCOPE_RESOURCE)
                ->whereIn('id', $driversByUserId->pluck('id'))
                ->pluck('id')
                ->all()
            : [];

        return $candidates
            ->reject(function (User $u) use ($driversByUserId, $inScopeDriverIds): bool {
                $driver = $driversByUserId->get($u->id);

                return $driver !== null && ! in_array($driver->id, $inScopeDriverIds, true);
            })
            ->each(fn (User $u) => $u->setAttribute('is_driver', $driversByUserId->has($u->id)))
            ->values();
    }
}
