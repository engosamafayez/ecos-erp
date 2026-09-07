<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Save the editable Role permission matrix
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §14).
 *
 * `permissions` is the COMPLETE desired set, not a delta — the matrix shows the whole
 * catalogue with checked/unchecked state, so submitting it is submitting the whole
 * picture. `present` rather than `required` on purpose: clearing every permission from a
 * role is a legitimate (if drastic) administrative act, and `required` would reject the
 * empty array that expresses it.
 *
 * Shape validation only. Existence is the compiler's call —
 * UnknownTemplatePermissionException returns 422 with every offending token listed, and it
 * reads the live `permissions` table, so it can never disagree with the real catalogue the
 * way a hardcoded rule list would.
 */
final class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'max:191', 'regex:/^[a-z0-9_\-]+(\.[a-z0-9_\-*]+)+$/'],
        ];
    }
}
