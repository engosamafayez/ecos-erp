<?php

declare(strict_types=1);

namespace Modules\Hr\Workforce\Domain\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\Hr\Workforce\Domain\Models\ReportingLine;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Contracts\ScopeResolverInterface;
use Modules\IAM\Domain\Enums\DataScope;

/**
 * The FIN-01 performance visibility contract: which employees a given acting
 * employee may see, and may act on, for the 'hr.performance' resource.
 *
 * ┌─ THE PERMISSION ANSWERS "MAY I" · THIS ANSWERS "WHICH ONES" ────────────┐
 * │ hr.performance.view/.manage/.review only gate whether the endpoint may     │
 * │ be called at all — enforced by route middleware, unchanged by this class.  │
 * │ This class answers the separate question the architecture review found     │
 * │ nothing currently answers: of the employees in this company, which ones    │
 * │ may THIS caller see or write to? The answer is always                      │
 * │                                                                            │
 * │     IAM's canonical scope for 'hr.performance'  ∩  the caller's own         │
 * │     reporting-line subtree (self + every primary, current descendant)      │
 * │                                                                            │
 * │ — reusing IAM's ScopeResolver rather than replacing it, and reusing the     │
 * │ existing ReportingLine graph rather than inventing a new hierarchy.        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ WHY "COMPANY" BYPASSES THE SUBTREE NARROWING AND BARE "ALL" DOES NOT ──┐
 * │ role_permissions.data_scope defaults to 'all' at the schema level — so a   │
 * │ resolved scope of ALL is ambiguous: it might be a deliberate "this role     │
 * │ sees everyone" grant, or it might simply be that nobody has ever narrowed   │
 * │ hr.performance's scope for this role. A resolved scope of COMPANY, by       │
 * │ contrast, can ONLY ever come from an administrator explicitly setting it —  │
 * │ it is never the passive default — so it is trustworthy evidence of intent   │
 * │ in a way bare ALL is not. Genuine system roles are asked directly instead   │
 * │ of relying on ScopeResolver's own is_system fast path returning ALL, for     │
 * │ the same reason: that path also yields ALL, indistinguishable from the       │
 * │ passive default once you're holding just the constraint.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class ManagerScopeService
{
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver,
        private readonly PermissionServiceInterface $permissions,
    ) {}

    /**
     * Apply the full visibility contract to an Employee query. The caller is
     * still responsible for the company_id filter — company boundary is
     * always applied first, ahead of this.
     *
     * $actingEmployee is nullable: an IAM user with no linked Employee row
     * (e.g. a pure admin account) has no reporting-line subtree at all. Such
     * a caller sees nothing UNLESS their IAM grant itself bypasses narrowing
     * (system role, or a deliberate COMPANY grant) — fail closed, never open.
     */
    public function scopeEmployeeQuery(Builder $query, User $user, ?Employee $actingEmployee): Builder
    {
        /** @var Builder $query */
        $query = $query->scopedTo($user, 'hr.performance');

        if ($this->bypassesSubtreeNarrowing($user)) {
            return $query;
        }

        if ($actingEmployee === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $this->visibleEmployeeIds($actingEmployee));
    }

    /**
     * A plain employee-id restriction for callers that need an array rather
     * than a query object (e.g. to hand into an aggregate-computing service
     * so the aggregate itself never touches out-of-scope employees). Returns
     * null for "no restriction" (bypass case) rather than the full company
     * roster, so a caller that doesn't need the ids at all can skip fetching
     * them entirely.
     *
     * @return array<int, string>|null
     */
    public function visibleEmployeeIdsOrNull(User $user, ?Employee $actingEmployee): ?array
    {
        if ($this->bypassesSubtreeNarrowing($user)) {
            return null;
        }

        return $actingEmployee === null ? [] : $this->visibleEmployeeIds($actingEmployee);
    }

    /**
     * Whether the acting user may see/act on one specific target employee.
     * Used for detail/mutation endpoints, where a direct id must not bypass
     * the same boundary a list endpoint enforces.
     */
    public function canView(User $user, ?Employee $actingEmployee, Employee $target): bool
    {
        if ($this->bypassesSubtreeNarrowing($user)) {
            return (string) $target->company_id === (string) ($user->company_id ?? '');
        }

        if ($actingEmployee === null) {
            return false;
        }

        if ((string) $target->company_id !== (string) $actingEmployee->company_id) {
            return false;
        }

        return in_array((string) $target->id, $this->visibleEmployeeIds($actingEmployee), true);
    }

    /**
     * The acting employee's own id plus every (primary, current) descendant,
     * direct and indirect — deduplicated, cycle-safe, one query regardless of
     * company size or tree depth (breadth-first over an in-memory adjacency
     * map, the same shape OrganizationChartService::build() already uses).
     *
     * @return array<int, string>
     */
    public function visibleEmployeeIds(Employee $actingEmployee): array
    {
        $companyId = (string) $actingEmployee->company_id;

        $childrenByManager = ReportingLine::query()
            ->where('company_id', $companyId)
            ->where('is_primary', true)
            ->whereNull('effective_to')
            ->get(['employee_id', 'manager_employee_id'])
            ->groupBy(fn (ReportingLine $line): string => (string) $line->manager_employee_id);

        $rootId = (string) $actingEmployee->id;
        $visited = [$rootId => true];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $managerId) {
                foreach ($childrenByManager->get($managerId, collect()) as $line) {
                    $childId = (string) $line->employee_id;

                    // Cycle/duplicate-edge guard: the schema carries no unique
                    // constraint over these columns (see ReportingLineService
                    // reconciliation notes), so this walk must not trust the
                    // data to already be a tree.
                    if (isset($visited[$childId])) {
                        continue;
                    }

                    $visited[$childId] = true;
                    $next[] = $childId;
                }
            }

            $frontier = $next;
        }

        return array_keys($visited);
    }

    private function bypassesSubtreeNarrowing(User $user): bool
    {
        if ($this->permissions->userHasSystemRole($user)) {
            return true;
        }

        return $this->scopeResolver->resolve($user, 'hr.performance')->scope === DataScope::COMPANY;
    }
}
