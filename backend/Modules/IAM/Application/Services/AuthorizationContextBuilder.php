<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Models\UserTemplateAssignment;

/**
 * Builds the complete authorization context delivered to the frontend (TASK-IAM-005 /
 * ADR-041). This is the SINGLE payload the UI consumes — permissions, visibility, scope,
 * policies, navigation, dashboard, landing, feature flags, effective templates — so the
 * client never issues extra authorization requests. Backend stays authoritative; this is
 * for UX only.
 */
class AuthorizationContextBuilder
{
    public function __construct(
        private readonly PermissionServiceInterface $permissions,
        private readonly UserRoleAssignmentService $roles,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(User $user): array
    {
        $isSystem = $this->permissions->userHasSystemRole($user);
        $profile = $this->roles->effectiveProfile($user);

        $assignments = UserTemplateAssignment::with('template.role')
            ->where('user_id', $user->getKey())
            ->orderByDesc('is_primary')
            ->get();

        $templates = $assignments
            ->map(fn (UserTemplateAssignment $a): ?array => $a->template === null ? null : [
                'key' => $a->template->key,
                'name' => $a->template->name,
                'category' => $a->template->category,
                'is_primary' => $a->is_primary,
            ])
            ->filter()
            ->values()
            ->all();

        return [
            'is_system' => $isSystem,
            'permissions' => $this->permissions->getUserPermissions($user),
            'visibility' => ['hidden_fields' => $profile->hiddenFields],
            'scopes' => $profile->scopes,
            'policies' => $profile->policies,
            'navigation' => $profile->navigation,
            'navigation_overrides' => $this->mergeNavigationOverrides($assignments),
            'dashboard' => $profile->dashboard,
            'landing_page' => $profile->landingPage,
            'preferences' => $profile->preferences,
            'quick_actions' => $profile->quickActions,
            'templates' => $templates,
            // Org-level feature flags. Empty map = everything enabled (forward-compatible;
            // a future org feature-flag store fills this without a frontend change).
            'feature_flags' => $this->featureFlags($user),
        ];
    }

    /**
     * User-review remediation (Batch 02, item I): merge every held role's UX-only nav
     * overrides into the one map the frontend applies. A role holder can hold several
     * roles, and access across them is already unioned everywhere else in this system, so
     * hiding an item requires EVERY role that opines on it to say 'hidden'; any one role
     * saying 'visible' wins. This can only ever affect whether an already-PERMITTED item is
     * shown — it is computed entirely independently of `$profile`/permissions above, and
     * nothing here is read by any authorization decision.
     *
     * @param  \Illuminate\Support\Collection<int,UserTemplateAssignment>  $assignments
     * @return array<string,string>
     */
    private function mergeNavigationOverrides($assignments): array
    {
        // Two passes, order-independent by construction: a single pass that applied
        // 'visible' the moment it was seen could still be overwritten back to 'hidden' by
        // a LATER role in the same loop, silently breaking "any one role saying 'visible'
        // wins" depending on iteration order alone. Collecting each state into its own set
        // first, then applying 'hidden' and only afterward 'visible' (so it always
        // overwrites last), makes the result the same regardless of which role is
        // processed first.
        $visible = [];
        $hidden = [];

        foreach ($assignments as $assignment) {
            $overrides = $assignment->template?->role?->navigation_overrides ?? [];
            foreach ($overrides as $key => $state) {
                if ($state === 'visible') {
                    $visible[$key] = true;
                } elseif ($state === 'hidden') {
                    $hidden[$key] = true;
                }
            }
        }

        $merged = [];
        foreach ($hidden as $key => $_) {
            $merged[$key] = 'hidden';
        }
        foreach ($visible as $key => $_) {
            $merged[$key] = 'visible';
        }

        return $merged;
    }

    /**
     * @return array<string,bool>
     */
    private function featureFlags(User $user): array
    {
        // No per-organization feature-flag store yet; default to all-enabled. The frontend
        // treats an absent/true flag as enabled, so this is safe to expand later.
        return [];
    }
}
