<?php

declare(strict_types=1);

namespace App\Core\Company;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;

/**
 * The single server-side authority for tenant (company) ownership.
 *
 * TASK-GOLIVE-RC6-REPAIR-001. RC-6 existed because two different sources
 * answered "which company owns this row?": the create path took `company_id`
 * from the client payload, while every read path took it from the authenticated
 * user. A record written under one answer was invisible to the other.
 *
 * Ownership is resolved here and nowhere else, so write and read cannot disagree.
 *
 * Cross-company access is NOT inferred from a null `company_id`. It is granted
 * only by an is_system role — the mechanism config/permissions.php already
 * documents as the platform's privilege flag ("is_system = true → role bypasses
 * all permission checks via Gate::before(). Never gate-bypass on slug"). A user
 * who simply has no company affiliation and no privileged role is unprivileged,
 * not unrestricted.
 */
final class TenantOwnershipResolver
{
    /**
     * Collaborators are resolved lazily rather than injected.
     *
     * This class is constructed from inside Eloquent global scopes, which run on
     * every query — including during migrations and console commands, before
     * every module provider has necessarily registered its bindings. Keeping the
     * constructor empty means building the resolver can never fail; the IAM
     * binding is only touched when a privilege question is actually asked.
     */
    private function permissions(): PermissionServiceInterface
    {
        return app(PermissionServiceInterface::class);
    }

    private function currentCompany(): CurrentCompanyService
    {
        return app(CurrentCompanyService::class);
    }

    /**
     * The company that owns anything this actor creates, and the only company
     * whose rows they may read — unless {@see isUnrestricted()} is true.
     *
     * Null means the actor has no company context. For an unprivileged actor
     * that is a closed door, not an open one; see {@see appliesTo()}.
     */
    public function companyId(): ?string
    {
        return $this->currentCompany()->id();
    }

    /**
     * True only for an actor explicitly authorized to operate across companies.
     *
     * Deliberately does not treat "no company" as privilege — that conflation is
     * what made RC-6's sibling defects fail open.
     */
    public function isUnrestricted(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return $this->permissions()->userHasSystemRole($user);
    }

    /**
     * Whether company scoping applies to the current request at all.
     *
     * False for unauthenticated execution — console commands, queue workers,
     * seeders and migrations run without an actor and must not be silently
     * filtered. This preserves the pre-existing `! Auth::check()` behaviour of
     * the model scopes; it is not a request-time bypass, because HTTP traffic
     * always has an authenticated actor behind `auth:sanctum`.
     */
    public function appliesTo(): bool
    {
        return Auth::check();
    }

    /**
     * Whether this actor may own or address rows belonging to $companyId.
     */
    public function owns(?string $companyId): bool
    {
        if ($this->isUnrestricted()) {
            return true;
        }

        $own = $this->companyId();

        return $own !== null && $companyId === $own;
    }
}
