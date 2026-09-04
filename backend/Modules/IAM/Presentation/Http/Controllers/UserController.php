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
use Illuminate\Support\Facades\Gate;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Application\Services\UserOrganizationAssignmentService;
use Modules\IAM\Application\Services\UserPasswordService;
use Modules\IAM\Application\Services\UserProfileService;
use Modules\IAM\Application\Services\UserRepository;
use Modules\IAM\Application\Services\UserRoleAssignmentService;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\IAM\Presentation\Http\Requests\AdminResetPasswordRequest;
use Modules\IAM\Presentation\Http\Requests\AssignTemplateRequest;
use Modules\IAM\Presentation\Http\Requests\CreateUserRequest;
use Modules\IAM\Presentation\Http\Requests\TransitionReasonRequest;
use Modules\IAM\Presentation\Http\Requests\UpdateUserRequest;

/**
 * IAM / Admin / Users (TASK-ECOS-IAM-SECURE-ADMIN-API-002, §6/§18). Business operations over
 * the EXISTING canonical User Management authority — UserIdentityService, UserLifecycleService,
 * UserPasswordService, UserRoleAssignmentService, UserOrganizationAssignmentService. This
 * controller stays thin: authorization is a Gate::authorize()/self-authorizing-service call,
 * validation lives in Form Requests, mutation lives in the Application services, and every
 * tenant/lifecycle/security rule enforced below was CTO-ratified in Task 1, not invented here.
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

        $user = $this->identity->createDraft($request->validated(), $companyId, $request->user()?->getKey());

        return $this->created($this->serialize($user, detailed: true));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $user = $this->identity->updateIdentity($user, $request->validated(), $request->user()?->getKey());

        return $this->updated($this->serialize($user, detailed: true));
    }

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

    /** D1 (CTO-ratified full lifecycle rule) is enforced inside UserPasswordService::adminReset(). */
    public function resetPassword(AdminResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->passwords->adminReset(
            $user,
            (string) $request->validated('password'),
            $request->user()?->getKey(),
            $request->boolean('require_password_change'),
        );

        return $this->success(null, 'Password reset.');
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
        $base = [
            'id' => $user->getKey(),
            'name' => $user->name,
            'display_name' => $user->resolvedDisplayName(),
            'email' => $user->email,
            'employee_number' => $user->employee_number,
            'status' => $user->statusEnum()->value,
            'status_label' => $user->statusEnum()->label(),
            'company_id' => $user->company_id,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'last_activity_at' => $user->last_activity_at?->toIso8601String(),
            'trashed' => $user->trashed(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'username' => $user->username,
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'employment_type' => $user->employment_type,
            'manager_id' => $user->manager_id,
            'hire_date' => $user->hire_date?->toDateString(),
            'templates' => $user->templateAssignments()->with('template')->get()
                ->map(fn ($a) => ['key' => $a->template?->key, 'name' => $a->template?->name, 'is_primary' => (bool) $a->is_primary])
                ->values()->all(),
            'organizations' => $user->organizationAssignments()->get()
                ->map(fn ($a) => ['type' => $a->org_type, 'id' => $a->org_id, 'label' => $a->org_label, 'is_primary' => (bool) $a->is_primary])
                ->values()->all(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
