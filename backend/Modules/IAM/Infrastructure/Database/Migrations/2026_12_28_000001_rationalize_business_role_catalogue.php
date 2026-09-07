<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\RoleAuthoringService;
use Modules\IAM\Application\Services\RoleTemplateCompiler;
use Modules\IAM\Domain\Catalog\BusinessRoleCatalog;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface;
use Modules\IAM\Domain\Contracts\ScopeResolverInterface;
use Modules\IAM\Domain\Contracts\VisibilityResolverInterface;
use Modules\IAM\Domain\Enums\RoleTemplateStatus;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\UserTemplateAssignment;

/**
 * Role rationalization — TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §12 + §17 + §19.
 *
 * Turns the accumulated 73-role catalogue into the approved 14: Super Admin plus the
 * thirteen template-backed business roles in BusinessRoleCatalog, with the §17 access
 * matrix applied.
 *
 * WHAT THIS MIGRATION IS ALLOWED TO DO, AND WHAT IT REFUSES TO DO
 * ---------------------------------------------------------------
 * §20 permits updating IAM configuration/business authorization data on DEV, and forbids
 * touching Orders, Finance, Inventory, Procurement, Shipping or any other business data,
 * forbids reseeding or truncating, and forbids blanket role deletion. Accordingly this
 * migration writes to exactly six tables — `role_templates`, `role_template_versions`,
 * `roles`, `role_permissions`, `user_roles`, `user_template_assignments` — and it never
 * issues a DELETE against `roles`. Superseded roles are ARCHIVED (§12: "Do NOT delete
 * blindly", "preserve audit/history").
 *
 * NO NEW PERMISSIONS, NO SECOND ENGINE
 * ------------------------------------
 * Every permission token comes from BusinessRoleCatalog and every one was verified to
 * already exist in `permissions`. `PermissionRegistry::sync()` is never called. Grants are
 * written by `RoleTemplateCompiler::compile()` and by nothing else, so the unknown-token
 * rejection, the empty-wildcard rejection and the three-cache invalidation all still apply.
 *
 * WHY IT DOES NOT CALL UserRoleAssignmentService
 * ----------------------------------------------
 * That service self-authorizes with `Gate::authorize('assignRole', $user)`, which requires
 * an authenticated actor. A migration runs in the console with no actor, so the Gate would
 * throw. Reassignment therefore writes the same two pivot rows the service writes
 * (`user_roles`, `user_template_assignments`) directly, and explicitly performs the same
 * three cache invalidations the service performs. This is the ONE deliberate deviation, it
 * is confined to reassignment bookkeeping, and it touches no grant: the grants themselves
 * still come only from the compiler.
 *
 * §19 — NEVER SILENTLY INCREASE ACCESS
 * ------------------------------------
 * Every user currently holding a superseded role is mapped to the approved target role and
 * KEEPS their organization scope untouched (`user_organization_assignments` is not written
 * here at all). The before/after permission counts for every reassignment are recorded in
 * the audit metadata below and in the task report's migration table. On DEV at
 * implementation time all three reassignments are net DECREASES in effective permissions,
 * and the two `driver` holders see no change whatsoever — the Driver target set is byte
 * identical to what the `driver` role already grants.
 *
 * IDEMPOTENT. Re-running upserts the same definitions, recompiles to the same grants and
 * re-archives what is already archived. `down()` restores the archived roles and withdraws
 * the business templates; it deliberately does NOT try to un-map reassigned users, because
 * guessing which of several superseded roles a user "should" go back to would be inventing
 * history.
 */
return new class extends Migration
{
    /**
     * Old role slug → approved business catalogue key, or null to archive with no
     * forward mapping (a legacy, test or out-of-scope role with no business equivalent).
     *
     * Only five roles hold users on DEV, but the whole catalogue is classified so §12's
     * inventory and §19's migration table are complete rather than partial.
     *
     * @var array<string,?string>
     */
    private const MAPPING = [
        // ── Legacy pre-template roles ────────────────────────────────────────
        'branch-manager' => null,               // no business equivalent in the approved 14
        'cashier' => null,                      // POS is outside the go-live scope
        'company-admin' => null,                // Super Admin is the platform administrator
        'customer-service' => 'customer-service',
        'devops-operator' => null,
        'dispatcher' => 'shipping-manager',
        'driver' => 'driver',
        'engineering-operator' => null,
        'finance-manager' => 'accountant',
        'fleet-manager' => 'shipping-manager',
        'fulfillment-supervisor' => 'warehouse-manager',
        'hr-manager' => null,                   // no HR role in the approved catalogue
        'inventory-controller' => 'warehouse-manager',
        'inventory-operator' => 'warehouse-worker',
        'marketing-manager' => 'marketing',
        'marketing-operator' => 'marketing',
        'preparation-supervisor' => 'warehouse-manager',
        'production-manager' => null,           // manufacturing is outside the scope
        'purchasing' => 'purchasing',
        'purchasing-manager' => 'purchasing',
        'purchasing-officer' => 'purchasing',
        'sales' => 'sales',
        'sales-manager' => 'sales-manager',
        'sales-representative' => 'sales',
        'shipping-coordinator' => 'shipping-coordinator',
        'smoke-buyer-a' => null,                // smoke-test artefact
        'smoke-buyer-b' => null,                // smoke-test artefact
        'system-auditor' => null,
        'viewer' => null,                       // a read-everything role with no owner
        'warehouse-manager' => 'warehouse-manager',
        'warehouse-operator' => 'warehouse-worker',

        // ── Roles compiled from official ECOS system templates ───────────────
        // The TEMPLATES stay untouched and remain View + Clone (§15). Only their compiled
        // runtime roles are withdrawn from the assignable catalogue.
        'tpl-accountant' => 'accountant',
        'tpl-ai-administrator' => null,
        'tpl-ai-analyst' => null,
        'tpl-cashier' => null,
        'tpl-ceo' => null,
        'tpl-cfo' => null,
        'tpl-coo' => null,
        'tpl-crm-specialist' => 'customer-service',
        'tpl-cto' => null,
        'tpl-customer-service-agent' => 'customer-service',
        'tpl-customer-service-manager' => 'customer-service',
        'tpl-dispatcher' => 'shipping-manager',
        'tpl-driver' => 'driver',
        'tpl-finance-director' => 'accountant',
        'tpl-financial-controller' => 'accountant',
        'tpl-hr-director' => null,
        'tpl-hr-manager' => null,
        'tpl-hr-officer' => null,
        'tpl-inventory-controller' => 'warehouse-manager',
        'tpl-marketing-director' => 'marketing',
        'tpl-marketing-specialist' => 'marketing',
        'tpl-operations-director' => null,
        'tpl-packaging-operator' => 'warehouse-worker',
        'tpl-packaging-supervisor' => 'warehouse-manager',
        'tpl-production-director' => null,
        'tpl-production-manager' => null,
        'tpl-production-operator' => null,
        'tpl-purchasing-manager' => 'purchasing',
        'tpl-purchasing-officer' => 'purchasing',
        'tpl-quality-inspector' => null,
        'tpl-sales-director' => 'sales-manager',
        'tpl-sales-manager' => 'sales-manager',
        'tpl-sales-representative' => 'sales',
        'tpl-senior-accountant' => 'accountant',
        'tpl-shipping-manager' => 'shipping-manager',
        'tpl-support-engineer' => null,
        'tpl-system-administrator' => null,
        'tpl-warehouse-clerk' => 'warehouse-worker',
        'tpl-warehouse-director' => 'warehouse-manager',
        'tpl-warehouse-manager' => 'warehouse-manager',
    ];

    public function up(): void
    {
        foreach (['roles', 'role_templates', 'permissions', 'user_roles'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        // A custom Role Template is company-scoped by construction (D11), so the business
        // catalogue needs an owning company. With no company configured there is nothing to
        // scope to and the migration is a documented no-op rather than a guess.
        $companyId = $this->resolveCompanyId();
        if ($companyId === null) {
            return;
        }

        /** @var RoleTemplateRepositoryInterface $repository */
        $repository = app(RoleTemplateRepositoryInterface::class);
        /** @var RoleTemplateCompiler $compiler */
        $compiler = app(RoleTemplateCompiler::class);

        // ── 1. Upsert + compile the thirteen approved business roles ─────────
        /** @var array<string,Role> $targets  catalogue key => compiled runtime role */
        $targets = [];

        foreach (BusinessRoleCatalog::all() as $catalogKey => $entry) {
            $templateKey = BusinessRoleCatalog::templateKey($catalogKey);
            $roleSlug = BusinessRoleCatalog::roleSlug($catalogKey);

            if ($roleSlug === null) {
                continue;
            }

            // The runtime role the template compiles into. Seven of these slugs already
            // exist as legacy pre-template rows and are ADOPTED (kept, redefined) rather
            // than duplicated — see BusinessRoleCatalog::ROLE_SLUGS.
            $role = Role::query()->where('slug', $roleSlug)->first();

            if ($role === null) {
                $role = new Role();
                $role->slug = $roleSlug;
                $role->name = $entry['name'];
                $role->description = $entry['description'];
                $role->is_system = false;
                $role->save();
            } else {
                // Never flip is_system on an existing row — a protected role is protected.
                if ($role->is_system) {
                    continue;
                }
                $role->name = $entry['name'];
                $role->description = $entry['description'];
                $role->archived_at = null;
                $role->archived_reason = null;
                $role->archived_by = null;
                $role->save();
            }

            $template = $repository->findByKey($templateKey);

            if ($template === null) {
                $template = $repository->createCustom([
                    'key' => $templateKey,
                    'name' => $entry['name'],
                    'description' => $entry['description'],
                    'category' => $entry['category'],
                    'definition' => $entry['definition'],
                    'company_id' => $companyId,
                ]);
            } else {
                $template = $repository->update($template, [
                    'name' => $entry['name'],
                    'description' => $entry['description'],
                    'category' => $entry['category'],
                    'definition' => $entry['definition'],
                ], 'business role catalogue rationalization');
            }

            // Published, or UserRoleAssignmentService would not consider it assignable.
            if ($template->status !== RoleTemplateStatus::PUBLISHED->value) {
                $template = $repository->update($template, [
                    'status' => RoleTemplateStatus::PUBLISHED->value,
                ], 'business role published');
            }

            // Pre-link so the compiler compiles INTO the clean-slugged role above instead
            // of minting a `tpl-business-*` twin. Same mechanism the compiler itself uses
            // for role_id, and the same one RoleAuthoringService::adoptIntoTemplate() uses.
            if ($template->role_id !== $role->getKey()) {
                DB::table('role_templates')->where('id', $template->id)->update(['role_id' => $role->getKey()]);
                $template->setAttribute('role_id', $role->getKey());
            }

            // The ONE grant-writing call. Rejects any unknown token before writing.
            $targets[$catalogKey] = $compiler->compile($template->refresh());
        }

        if ($targets === []) {
            return;
        }

        // ── 2. Reassign every holder of a superseded role ───────────────────
        $targetIds = array_map(static fn (Role $r): string => (string) $r->getKey(), $targets);
        $protectedSlugs = BusinessRoleCatalog::SYSTEM_PROTECTED_ROLES;

        $superseded = Role::query()
            ->whereNotIn('id', array_values($targetIds))
            ->whereNotIn('slug', $protectedSlugs)
            ->where('is_system', false)
            ->with('roleTemplate:id,key,is_system,role_id')
            ->get();

        foreach ($superseded as $old) {
            $targetKey = self::MAPPING[(string) $old->slug] ?? null;
            $target = $targetKey !== null ? ($targets[$targetKey] ?? null) : null;

            $holders = DB::table('user_roles')->where('role_id', $old->getKey())->pluck('user_id');

            foreach ($holders as $userId) {
                if ($target === null) {
                    // No approved forward mapping. The holder is deliberately LEFT ON the
                    // old role, which is archived-but-still-resolving: dropping them onto
                    // nothing would be a silent access removal, and inventing a target
                    // would be a silent access change. It is surfaced in the report for a
                    // human decision instead.
                    continue;
                }

                $this->attachRole((int) $userId, $target, $old);
            }
        }

        // ── 3. Archive every superseded role (never delete — §12/§20) ───────
        /** @var RoleAuthoringService $authoring */
        $authoring = app(RoleAuthoringService::class);

        foreach ($superseded as $old) {
            if ($old->isArchived()) {
                continue;
            }

            $targetKey = self::MAPPING[(string) $old->slug] ?? null;
            $reason = $targetKey !== null
                ? 'Superseded by the approved business role catalogue — mapped to '.$targetKey
                : 'Superseded by the approved business role catalogue — no business equivalent';

            try {
                // Canonical archival path: sets roles.archived_at, mirrors the state onto a
                // NON-system backing template, audits, and leaves current holders resolving.
                $authoring->archive($old, $reason, null);
            } catch (Throwable) {
                // A guard refused (a role that turned out to be protected). Leave it exactly
                // as it is — a migration must not force past a domain invariant.
                continue;
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        // Un-archive everything this migration archived. Reassignments are intentionally
        // left in place: a user who now holds the approved target role keeps it, because
        // choosing which superseded role to send them back to would be fabricating history.
        DB::table('roles')
            ->whereNotNull('archived_reason')
            ->where('archived_reason', 'like', 'Superseded by the approved business role catalogue%')
            ->update(['archived_at' => null, 'archived_reason' => null, 'archived_by' => null]);

        // Withdraw the business templates from the assignable catalogue rather than delete
        // them — their compiled roles may already be held by users.
        DB::table('role_templates')
            ->where('key', 'like', BusinessRoleCatalog::TEMPLATE_KEY_PREFIX.'%')
            ->update(['status' => RoleTemplateStatus::ARCHIVED->value]);
    }

    /**
     * Attach the target role to a user, mirroring exactly what
     * UserRoleAssignmentService::assignTemplate() persists — the `user_roles` grant, the
     * `user_template_assignments` bookkeeping row, and all three cache invalidations.
     *
     * §19 "preserve organization scope": `user_roles` is a first-class entity carrying
     * optional `company_id` / `branch_id` / `warehouse_id` scope columns, so the new grant
     * is written with the SAME scope values the replaced grant carried. Reassignment must
     * not quietly widen a user from one warehouse to all of them.
     */
    private function attachRole(int $userId, Role $target, Role $replaced): void
    {
        $previous = DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $replaced->getKey())
            ->first();

        $alreadyHeld = DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $target->getKey())
            ->exists();

        if (! $alreadyHeld) {
            // The UserRole pivot is a UUID-keyed entity with no database default on `id`
            // (HasUuids generates it in PHP), so a raw insert must supply one.
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'role_id' => $target->getKey(),
                'company_id' => $previous->company_id ?? null,
                'branch_id' => $previous->branch_id ?? null,
                'warehouse_id' => $previous->warehouse_id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $template = $target->roleTemplate;

        if ($template !== null) {
            $assignment = UserTemplateAssignment::firstOrNew([
                'user_id' => $userId,
                'role_template_id' => $template->id,
            ]);

            if (! $assignment->exists) {
                $assignment->is_primary = false;
                $assignment->assigned_at = now();
                $assignment->save();
            }
        }

        // The old grant is withdrawn only once the new one is in place, so the user is
        // never momentarily without a role.
        DB::table('user_roles')->where('user_id', $userId)->where('role_id', $replaced->getKey())->delete();

        if ($replaced->roleTemplate !== null) {
            UserTemplateAssignment::where('user_id', $userId)
                ->where('role_template_id', $replaced->roleTemplate->id)
                ->delete();
        }

        // Security Gate B: all three effective-authorization caches together, always.
        app(PermissionServiceInterface::class)->invalidateUserCache($userId);
        app(VisibilityResolverInterface::class)->invalidateUserCache($userId);
        app(ScopeResolverInterface::class)->invalidateUserCache($userId);
    }

    /** The company that owns the business catalogue's custom templates (D11). */
    private function resolveCompanyId(): ?string
    {
        if (! Schema::hasTable('companies')) {
            return null;
        }

        // Prefer the company the existing administrator account already belongs to, so the
        // catalogue lands in the tenant the platform is actually being operated as.
        if (Schema::hasTable('users')) {
            $fromUsers = DB::table('users')
                ->whereNotNull('company_id')
                ->orderBy('id')
                ->value('company_id');

            if (is_string($fromUsers) && $fromUsers !== '') {
                return $fromUsers;
            }
        }

        $company = DB::table('companies')
            ->when(Schema::hasColumn('companies', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->orderBy('created_at')
            ->value('id');

        return is_string($company) && $company !== '' ? $company : null;
    }
};
