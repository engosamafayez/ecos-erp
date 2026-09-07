<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HasApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\IAM\Application\Services\EmployeeDirectory;
use Modules\IAM\Application\Services\OrganizationScopeDirectory;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Application\Services\UserOrganizationAssignmentService;
use Modules\IAM\Application\Services\UserPasswordService;
use Modules\IAM\Application\Services\UserProfileService;
use Modules\IAM\Application\Services\UserRepository;
use Modules\IAM\Application\Services\UserRoleAssignmentService;
use Modules\IAM\Domain\Catalog\BusinessRoleCatalog;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\IAM\Presentation\Http\Requests\AdminResetPasswordRequest;
use Modules\IAM\Presentation\Http\Requests\AssignTemplateRequest;
use Modules\IAM\Presentation\Http\Requests\CreateUserRequest;
use Modules\IAM\Presentation\Http\Requests\SyncOrganizationScopeRequest;
use Modules\IAM\Presentation\Http\Requests\TransitionReasonRequest;
use Modules\IAM\Presentation\Http\Requests\UpdateUserRequest;

/**
 * IAM / Admin / Users (TASK-ECOS-IAM-SECURE-ADMIN-API-002, §6/§18). Business operations over
 * the EXISTING canonical User Management authority — UserIdentityService, UserLifecycleService,
 * UserPasswordService, UserRoleAssignmentService, UserOrganizationAssignmentService. This
 * controller stays thin: authorization is a Gate::authorize()/self-authorizing-service call,
 * validation lives in Form Requests, mutation lives in the Application services, and every
 * tenant/lifecycle/security rule enforced below was CTO-ratified in Task 1, not invented here.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001 adds, WITHOUT introducing a second engine:
 *
 *   store()                — one submission now provisions a COMPLETE account: identity,
 *                            initial password (§10), role assignments (§8), organization
 *                            scope (§9) and optional immediate activation (§10). Each part
 *                            still runs through its own canonical service, in one
 *                            transaction, so a partial failure provisions nothing.
 *   syncOrganizationScope()— the multi-select hierarchical scope save (§9), replacing the
 *                            type/id/label triple for normal admin use. The single-assignment
 *                            endpoint is retained for compatibility.
 *   employeeDirectory()    — searchable lookup over EXISTING employees (§6).
 *   organizationDirectory()— the canonical Company → Brand → … hierarchy (§9).
 *   serialize()            — now carries the lifecycle capability flags the UI needs to
 *                            render an explicit, discoverable Activate action and to stop
 *                            offering a password reset that the backend will refuse (§10).
 */
final class UserController extends Controller
{
    use HasApiResponse;

    private const TERMINAL_STATUSES = [UserStatus::ARCHIVED->value, UserStatus::DELETED->value];

    public function __construct(
        private readonly UserRepository $repository,
        private readonly UserIdentityService $identity,
        private readonly UserProfileService $profile,
        private readonly UserOrganizationAssignmentService $organization,
        private readonly UserRoleAssignmentService $roles,
        private readonly UserLifecycleService $lifecycle,
        private readonly UserPasswordService $passwords,
        private readonly TenantOwnershipResolver $tenant,
        private readonly EmployeeDirectory $employees,
        private readonly OrganizationScopeDirectory $orgDirectory,
    ) {}

    /**
     * STOP 3 (never decision-gated — pure implementation requirement): every list call is
     * mandatorily company-scoped, bypassed only for an unrestricted (is_system) actor.
     * D8: archived/deleted are excluded unless `include_archived=1` is explicitly passed.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->only(['q', 'template', 'org_type', 'org_id']);

        if (! $this->tenant->isUnrestricted()) {
            $companyId = $this->tenant->companyId();
            if ($companyId === null) {
                // Fail closed: an actor with no company and no system role owns nothing to list.
                return $this->success(['data' => [], 'meta' => ['total' => 0]]);
            }
            $filters['company_id'] = $companyId;
        } elseif ($request->filled('company_id')) {
            // Unrestricted actors may explicitly narrow to one company; never required.
            $filters['company_id'] = $request->string('company_id')->toString();
        }

        if (! $request->boolean('include_archived')) {
            $filters['status'] = array_values(array_diff(
                array_map(fn (UserStatus $s) => $s->value, UserStatus::cases()),
                self::TERMINAL_STATUSES,
            ));
        }
        $filters['with_trashed'] = $request->boolean('include_archived');

        $page = $this->repository->search($filters, (int) $request->integer('per_page', 25));

        return $this->success([
            'data' => array_map($this->serialize(...), $page->items()),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return $this->success($this->serialize($user, detailed: true));
    }

    /**
     * Create a user.
     *
     * §10's dead-end existed partly because provisioning was split across four requests
     * (create → set password → assign role → assign scope) and the second one was refused
     * for a DRAFT account. All four now happen here, atomically, each through its own
     * canonical service:
     *
     *   identity + initial password → UserIdentityService::createDraft()
     *   roles                       → UserRoleAssignmentService::assignTemplate()  (self-authorizing)
     *   organization scope          → UserOrganizationAssignmentService::sync()    (validated)
     *   activation                  → UserLifecycleService::activate()             (transition map)
     *
     * The transaction matters: a role assignment that fails authorization must not leave a
     * half-provisioned account behind, which is the state §10 describes as "stuck".
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        // D2: server-derived company ownership. Unrestricted actors may target an explicit
        // company (there is no "acting company" for a cross-company actor otherwise); every
        // other actor is bound to their own — the request body has no company_id field at all.
        $companyId = $this->tenant->isUnrestricted() && $request->filled('company_id')
            ? $request->string('company_id')->toString()
            : $this->tenant->companyId();

        if ($companyId === null) {
            return $this->error('Cannot create a user without a resolvable company.', 422);
        }

        $validated = $request->validated();
        $actorId = $request->user()?->getKey();

        // User-review remediation (Batch 02, item B): the administrator no longer invents an
        // initial password — omit `password` entirely (the normal path) and one is generated
        // securely, server-side, unless a caller explicitly supplied its own (kept for the
        // rare integration that still wants to set one itself; never surfaced in the UI).
        $hasExplicitPassword = isset($validated['password']) && is_string($validated['password']) && $validated['password'] !== '';
        if (! $hasExplicitPassword) {
            $validated['auto_generate_password'] = true;
        }

        $generatedPassword = null;
        $user = DB::transaction(function () use ($validated, $companyId, $actorId, $request, &$generatedPassword): User {
            $user = $this->identity->createDraft($validated, $companyId, $actorId, $generatedPassword);

            $templates = array_values(array_filter((array) ($validated['role_templates'] ?? [])));
            $primary = $validated['primary_role_template'] ?? null;

            foreach ($templates as $templateKey) {
                $this->roles->assignTemplate(
                    $user,
                    (string) $templateKey,
                    $primary !== null && (string) $templateKey === (string) $primary,
                    $actorId,
                );
            }

            $organizations = (array) ($validated['organizations'] ?? []);
            if ($organizations !== []) {
                $this->organization->sync($user, $organizations, $actorId);
            }

            // Activation is the canonical transition, not a status write: DRAFT → ACTIVE is
            // in UserStatus::allowedTransitions(), and going through the state machine is
            // what makes it validated, timestamped and audited.
            if ($request->boolean('activate')) {
                $this->lifecycle->activate($user, $request->user());
            }

            return $user;
        });

        $payload = $this->serialize($user->refresh(), detailed: true);
        if ($generatedPassword !== null) {
            // Shown to the authorized creator exactly once, in this single create response.
            // Never persisted in plaintext, never logged, never returned by any read endpoint.
            $payload['generated_password'] = $generatedPassword;
        }

        return $this->created($payload);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $user = $this->identity->updateIdentity($user, $request->validated(), $request->user()?->getKey());

        return $this->updated($this->serialize($user, detailed: true));
    }

    /**
     * Single organization assignment — RETAINED unchanged for compatibility and for the
     * two org-unit types that have no canonical table (department, cost_center). Normal
     * admin use goes through syncOrganizationScope() (§9), which is entity-driven.
     */
    public function assignOrganization(Request $request, User $user): JsonResponse
    {
        Gate::authorize('assignOrganization', $user);

        $request->validate([
            'org_type' => ['required', 'string'],
            'org_id' => ['sometimes', 'nullable', 'string'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'primary' => ['sometimes', 'boolean'],
        ]);

        $this->organization->assign(
            $user,
            (string) $request->input('org_type'),
            $request->input('org_id'),
            $request->input('label'),
            $request->boolean('primary'),
            $request->user()?->getKey(),
        );

        return $this->updated($this->serialize($user, detailed: true));
    }

    /**
     * §9 — save the whole organization scope in one authorized, validated, audited call.
     *
     * The submitted list is the complete desired scope. Every entry's entity is verified
     * against its canonical organization table BEFORE anything is written, so backend scope
     * validation is authoritative rather than advisory.
     */
    public function syncOrganizationScope(SyncOrganizationScopeRequest $request, User $user): JsonResponse
    {
        Gate::authorize('assignOrganization', $user);

        $this->organization->sync(
            $user,
            (array) $request->validated('assignments'),
            $request->user()?->getKey(),
        );

        return $this->updated($this->serialize($user->refresh(), detailed: true));
    }

    /** Template-mediated only (ADR-039/040) — self-authorizes inside UserRoleAssignmentService. */
    public function assignTemplate(AssignTemplateRequest $request, User $user, string $templateKey): JsonResponse
    {
        $this->roles->assignTemplate($user, $templateKey, $request->boolean('primary'), $request->user()?->getKey());

        return $this->updated($this->serialize($user, detailed: true));
    }

    public function revokeTemplate(User $user, string $templateKey): JsonResponse
    {
        $this->roles->removeTemplate($user, $templateKey);

        return $this->updated($this->serialize($user, detailed: true));
    }

    public function activate(TransitionReasonRequest $request, User $user): JsonResponse
    {
        return $this->transition($request, $user, 'activate', fn () => $this->lifecycle->activate($user, $request->user()));
    }

    public function suspend(TransitionReasonRequest $request, User $user): JsonResponse
    {
        return $this->transition($request, $user, 'suspend', fn () => $this->lifecycle->suspend($user, $request->user(), $request->input('reason')));
    }

    public function deactivate(TransitionReasonRequest $request, User $user): JsonResponse
    {
        return $this->transition($request, $user, 'deactivate', fn () => $this->lifecycle->deactivate($user, $request->user(), $request->input('reason')));
    }

    public function lock(TransitionReasonRequest $request, User $user): JsonResponse
    {
        return $this->transition($request, $user, 'lock', fn () => $this->lifecycle->lock($user, $request->user(), $request->input('reason')));
    }

    public function unlock(TransitionReasonRequest $request, User $user): JsonResponse
    {
        return $this->transition($request, $user, 'unlock', fn () => $this->lifecycle->unlock($user, $request->user()));
    }

    public function archive(TransitionReasonRequest $request, User $user): JsonResponse
    {
        return $this->transition($request, $user, 'archive', fn () => $this->lifecycle->archive($user, $request->user(), $request->input('reason')));
    }

    /** STOP 5 is fixed (UserLifecycleService::restore()) — safe to expose for both Archived and truly Deleted. */
    public function restore(User $user): JsonResponse
    {
        Gate::authorize('restore', $user);

        $user = $this->lifecycle->restore($user, request()->user());

        return $this->updated($this->serialize($user, detailed: true));
    }

    /**
     * D1 (CTO-ratified full lifecycle rule) is enforced inside UserPasswordService::adminReset().
     *
     * §10: that rule now distinguishes SETTING a first credential (DRAFT / INVITED /
     * PENDING_ACTIVATION) from RESETTING an existing one, so this endpoint serves both and
     * a draft account is no longer a dead end. Password strength is unchanged —
     * AdminResetPasswordRequest still applies the single canonical Password::defaults()
     * baseline (D5).
     */
    public function resetPassword(AdminResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->passwords->adminReset(
            $user,
            (string) $request->validated('password'),
            $request->user()?->getKey(),
            $request->boolean('require_password_change'),
        );

        $wasInitial = $user->statusEnum()->allowsAdminPasswordSet();

        return $this->success(
            $this->serialize($user->refresh(), detailed: true),
            $wasInitial ? 'Initial password set.' : 'Password reset.',
        );
    }

    /**
     * §6 — searchable EXISTING-employee lookup for the employee link field.
     *
     * Gated by route middleware on `iam.users.view`: this is a lookup used while
     * administering users, and `iam.users.view` is the minimum token any IAM administrator
     * who can reach the Create/Edit drawer already holds. It reads `hr_employees` (the HR
     * module's own authority) and writes nothing — see EmployeeDirectory for why it does
     * not proxy the `hr.employees.view`-gated HR endpoint.
     */
    public function employeeDirectory(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $companyId = $this->tenant->isUnrestricted()
            ? ($request->filled('company_id') ? $request->string('company_id')->toString() : null)
            : $this->tenant->companyId();

        return $this->success([
            'available' => $this->employees->available(),
            'data' => $this->employees->search(
                $request->filled('q') ? $request->string('q')->toString() : null,
                $companyId,
                $request->boolean('only_unlinked'),
                (int) $request->integer('limit', 50),
            ),
        ]);
    }

    /**
     * §9 — the canonical organization hierarchy for the scope picker.
     *
     * Every level is read from the organization table that owns it. A level whose table
     * does not exist in this installation is reported `available: false` so the UI hides
     * it instead of offering a picker for an entity this model does not have.
     */
    public function organizationDirectory(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $companyId = $this->tenant->isUnrestricted()
            ? ($request->filled('company_id') ? $request->string('company_id')->toString() : null)
            : $this->tenant->companyId();

        return $this->success([
            'levels' => $this->orgDirectory->hierarchy(
                $companyId,
                $request->filled('q') ? $request->string('q')->toString() : null,
                (int) $request->integer('limit', 200),
            ),
            // The remaining free-form types, so the UI can still expose them where needed
            // without pretending they are entity-backed.
            'free_form_types' => array_values(array_diff(
                UserOrganizationAssignmentService::TYPES,
                OrganizationScopeDirectory::types(),
            )),
        ]);
    }

    private function transition(TransitionReasonRequest $request, User $user, string $ability, Closure $run): JsonResponse
    {
        Gate::authorize($ability, $user);

        $user = $run();

        return $this->updated($this->serialize($user, detailed: true));
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(User $user, bool $detailed = false): array
    {
        $status = $user->statusEnum();

        $base = [
            'id' => $user->getKey(),
            'name' => $user->name,
            'display_name' => $user->resolvedDisplayName(),
            'email' => $user->email,
            'username' => $user->username,
            'employee_number' => $user->employee_number,
            'status' => $status->value,
            'status_label' => $status->label(),
            'company_id' => $user->company_id,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'last_activity_at' => $user->last_activity_at?->toIso8601String(),
            'trashed' => $user->trashed(),

            // User-review remediation (Batch 02, item G): the administrator must see assigned
            // roles directly on the Users list, not only after opening the detail drawer.
            // `templateAssignments.template` is already eager-loaded by UserRepository::query()
            // for every list call, so reading the loaded relation here (never a fresh query)
            // costs nothing extra per row.
            'roles' => $user->relationLoaded('templateAssignments')
                ? $user->templateAssignments->map(function ($a) {
                    $key = $a->template?->key;
                    $display = $key !== null ? BusinessRoleCatalog::displayFor($key) : null;

                    return [
                        'key' => $key,
                        'name' => $a->template?->name,
                        'name_ar' => $display['name_ar'] ?? $a->template?->name,
                        'is_primary' => (bool) $a->is_primary,
                    ];
                })->values()->all()
                : [],

            // §10 — lifecycle capability flags, read from the canonical UserStatus authority
            // so the UI cannot drift from what the backend will actually allow. This is what
            // makes Activate "explicit and discoverable" and stops the UI offering a password
            // action the server is going to refuse.
            'lifecycle' => [
                'is_pre_activation' => $status->isPreActivation(),
                'can_activate' => $status->canTransitionTo(UserStatus::ACTIVE),
                'can_suspend' => $status->canTransitionTo(UserStatus::SUSPENDED),
                'can_deactivate' => $status->canTransitionTo(UserStatus::INACTIVE),
                'can_lock' => $status->canTransitionTo(UserStatus::LOCKED),
                'can_unlock' => $status === UserStatus::LOCKED,
                'can_archive' => $status->canTransitionTo(UserStatus::ARCHIVED),
                'can_restore' => $status === UserStatus::ARCHIVED || $user->trashed(),
                'can_set_initial_password' => $status->allowsAdminPasswordSet(),
                'can_reset_password' => $status->allowsAdminPasswordReset(),
                'can_authenticate' => $status->canAuthenticate(),
                'requires_password_change' => (bool) $user->require_password_change,
                'has_credential' => $user->password_changed_at !== null,
            ],
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'employment_type' => $user->employment_type,
            'manager_id' => $user->manager_id,
            'hire_date' => $user->hire_date?->toDateString(),
            'templates' => $user->templateAssignments()->with('template')->get()
                ->map(function ($a) {
                    $key = $a->template?->key;
                    $display = $key !== null ? BusinessRoleCatalog::displayFor($key) : null;

                    return [
                        'key' => $key,
                        'name' => $a->template?->name,
                        // Arabic role name where the role is part of the approved catalogue (§5).
                        'name_ar' => $display['name_ar'] ?? $a->template?->name,
                        'is_primary' => (bool) $a->is_primary,
                        'scope_expectation' => $key !== null
                            ? BusinessRoleCatalog::scopeExpectationFor($key)
                            : [],
                    ];
                })
                ->values()->all(),
            'organizations' => $user->organizationAssignments()->get()
                ->map(fn ($a) => [
                    'type' => $a->org_type,
                    'id' => $a->org_id,
                    'label' => $a->org_label,
                    'is_primary' => (bool) $a->is_primary,
                    // Whether this assignment points at a canonical entity or is one of the
                    // remaining free-form types — the UI renders them differently.
                    'canonical' => $this->orgDirectory->isCanonical((string) $a->org_type),
                ])
                ->values()->all(),
            'employee' => $user->employee_number !== null
                ? $this->employees->findByNumber((string) $user->employee_number)
                : null,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
