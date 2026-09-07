<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\UserOrganizationAssignment;

/**
 * Assigns users to organization units (ADR-040). Additive — a user may belong to many
 * units of many types. Unit types without a table yet (department/cost_center) are stored
 * by type+id and become FK-backed when those modules land.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §9. Two changes, both about making the
 * BACKEND authoritative:
 *
 *  1. EXISTENCE VALIDATION. `org_id` was a nullable, entirely unvalidated string, so a
 *     request could persist a scope assignment naming a warehouse that does not exist —
 *     and the scope engine would then resolve a constraint against a phantom id. Every
 *     assignment is now checked against the canonical organization table through
 *     `OrganizationScopeDirectory::exists()` BEFORE anything is written. §9's "Backend
 *     scope validation must remain authoritative" is now true rather than aspirational.
 *
 *  2. SYNC. The UI in §9 is a multi-select hierarchy: an administrator picks several
 *     companies, brands, warehouses and combinations at once and saves. Driving that
 *     through the single-assignment endpoint would mean the client computing its own diff
 *     and issuing N requests, with no transaction and no way to REMOVE a scope. `sync()`
 *     accepts the complete desired set and reconciles it in one transaction.
 *
 * The label is resolved from the canonical entity rather than accepted from the client
 * (§9: "Never require an admin to manually type raw entity IDs" — nor their names). A
 * client-supplied label is honoured only for a type with no canonical table.
 */
class UserOrganizationAssignmentService
{
    public const TYPES = [
        'company', 'branch', 'warehouse', 'department', 'business_unit',
        'region', 'channel', 'team', 'cost_center',
    ];

    public function __construct(
        private readonly UserAuditService $audit,
        private readonly OrganizationScopeDirectory $directory,
    ) {}

    public function assign(User $user, string $orgType, ?string $orgId, ?string $label = null, bool $primary = false, ?int $actorId = null): UserOrganizationAssignment
    {
        $this->assertValidType($orgType);
        $this->assertEntityExists($orgType, $orgId);

        $assignment = UserOrganizationAssignment::firstOrNew([
            'user_id' => $user->getKey(),
            'org_type' => $orgType,
            'org_id' => $orgId,
        ]);
        $assignment->org_label = $this->resolveLabel($orgType, $orgId, $label);
        $assignment->is_primary = $primary;
        $assignment->assigned_by = $actorId;
        $assignment->assigned_at = now();
        $assignment->save();

        if ($primary) {
            UserOrganizationAssignment::where('user_id', $user->getKey())
                ->where('org_type', $orgType)
                ->where('id', '!=', $assignment->id)
                ->update(['is_primary' => false]);
        }

        $this->audit->log('organization_assigned', $user, [], ['type' => $orgType, 'id' => $orgId, 'primary' => $primary]);

        return $assignment;
    }

    /**
     * Reconcile a user's organization scope to exactly the submitted set.
     *
     * Validation happens for EVERY entry before the first write, so a partially-valid
     * payload changes nothing at all — a rejected scope save must not leave a user with
     * half a scope.
     *
     * @param  list<array{org_type:string,org_id?:?string,label?:?string,primary?:bool}>  $assignments
     * @return list<array<string,mixed>>  the user's assignments after reconciliation
     */
    public function sync(User $user, array $assignments, ?int $actorId = null): array
    {
        $desired = [];

        foreach ($assignments as $entry) {
            $type = (string) ($entry['org_type'] ?? '');
            $id = isset($entry['org_id']) && $entry['org_id'] !== '' ? (string) $entry['org_id'] : null;

            $this->assertValidType($type);
            $this->assertEntityExists($type, $id);

            // Keyed so a duplicated (type, id) in the payload collapses instead of
            // colliding on the table's own uniqueness.
            $desired[$type.'|'.($id ?? '')] = [
                'org_type' => $type,
                'org_id' => $id,
                'label' => $this->resolveLabel($type, $id, $entry['label'] ?? null),
                'primary' => (bool) ($entry['primary'] ?? false),
            ];
        }

        $before = UserOrganizationAssignment::where('user_id', $user->getKey())->get()
            ->map(fn (UserOrganizationAssignment $a): string => $a->org_type.'|'.((string) ($a->org_id ?? '')))
            ->values()->all();

        DB::transaction(function () use ($user, $desired, $actorId): void {
            $keep = [];

            foreach ($desired as $entry) {
                $assignment = UserOrganizationAssignment::firstOrNew([
                    'user_id' => $user->getKey(),
                    'org_type' => $entry['org_type'],
                    'org_id' => $entry['org_id'],
                ]);
                $assignment->org_label = $entry['label'];
                $assignment->is_primary = $entry['primary'];
                $assignment->assigned_by = $actorId;
                $assignment->assigned_at = $assignment->assigned_at ?? now();
                $assignment->save();

                $keep[] = $assignment->getKey();
            }

            // Anything not in the submitted set is withdrawn. This is the only way §9's
            // multi-select picker can express "remove this warehouse".
            UserOrganizationAssignment::where('user_id', $user->getKey())
                ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
                ->delete();
        });

        $after = UserOrganizationAssignment::where('user_id', $user->getKey())->get();

        $this->audit->log(
            'organization_scope_synced',
            $user,
            ['assignments' => $before],
            ['assignments' => $after->map(fn ($a) => $a->org_type.'|'.((string) ($a->org_id ?? '')))->values()->all()],
            ['actor_id' => $actorId, 'count' => $after->count()],
        );

        return $after->map(fn (UserOrganizationAssignment $a): array => [
            'type' => $a->org_type,
            'id' => $a->org_id,
            'label' => $a->org_label,
            'is_primary' => (bool) $a->is_primary,
        ])->values()->all();
    }

    public function unassign(User $user, string $orgType, ?string $orgId): void
    {
        UserOrganizationAssignment::where('user_id', $user->getKey())
            ->where('org_type', $orgType)
            ->where('org_id', $orgId)
            ->delete();

        $this->audit->log('organization_unassigned', $user, ['type' => $orgType, 'id' => $orgId], []);
    }

    private function assertValidType(string $orgType): void
    {
        if (! in_array($orgType, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown organization unit type '{$orgType}'.");
        }
    }

    /**
     * §9's authoritative server-side scope validation. `exists()` returns true for a type
     * with no canonical table (department / cost_center), preserving those as documented
     * forward-compatible free-form assignments rather than retroactively invalidating them.
     */
    private function assertEntityExists(string $orgType, ?string $orgId): void
    {
        if (! $this->directory->exists($orgType, $orgId)) {
            throw new \InvalidArgumentException(
                "No {$orgType} exists with the identifier '{$orgId}'. Organization scope must reference a real entity."
            );
        }
    }

    /**
     * Prefer the canonical entity's own name; fall back to a client-supplied label only
     * where there is no canonical entity to read a name from.
     */
    private function resolveLabel(string $orgType, ?string $orgId, ?string $supplied): ?string
    {
        $canonical = $this->directory->labelFor($orgType, $orgId);

        return $canonical ?? $supplied;
    }
}
