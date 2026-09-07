<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IAM\Application\Services\UserOrganizationAssignmentService;

/**
 * Save a user's whole organization scope
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §9).
 *
 * `assignments` is the COMPLETE desired scope — the picker is a multi-select over the real
 * hierarchy, so saving it means saving the whole selection. `present` rather than
 * `required`: an empty array is the legitimate way to express "this user has no
 * organization scope", and `required` would reject it.
 *
 * `org_type` is validated against the canonical type list. Whether the referenced ENTITY
 * exists is validated one layer down, in
 * UserOrganizationAssignmentService::assertEntityExists(), for two reasons: it needs a
 * database lookup per entry against a different table per type, and putting it in the
 * service means a caller that bypasses this request (an internal service, a future
 * console command) still cannot persist a phantom scope. §9's requirement is that the
 * BACKEND is authoritative, not that one HTTP request class is.
 */
final class SyncOrganizationScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('assignOrganization', $user) in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'assignments' => ['present', 'array', 'max:500'],
            'assignments.*.org_type' => ['required', 'string', Rule::in(UserOrganizationAssignmentService::TYPES)],
            'assignments.*.org_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'assignments.*.label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'assignments.*.primary' => ['sometimes', 'boolean'],
        ];
    }
}
