<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IAM\Domain\Enums\RoleCategory;

/**
 * Edit Role metadata (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11).
 *
 * Metadata only. `slug`, `is_system` and `permissions` are all deliberately unwritable
 * here: slug is the stable identifier every existing grant and audit row refers to,
 * `is_system` is a platform invariant no API may flip, and permissions have their own
 * endpoint (UpdateRolePermissionsRequest) because a grant change must go through the
 * compiler and be audited as a grant change rather than as a rename.
 */
final class UpdateRoleRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'nullable', 'string', Rule::in(array_map(
                static fn (RoleCategory $c): string => $c->value,
                RoleCategory::cases(),
            ))],
        ];
    }
}
