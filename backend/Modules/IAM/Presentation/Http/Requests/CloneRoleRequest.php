<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Clone Role (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11).
 *
 * Cloning is the sanctioned way to get an editable copy of a protected role — a system
 * role or one compiled from an immutable ECOS system template (§15: system templates are
 * View + Clone). The clone always lands as a company-scoped custom role, so the source is
 * never modified.
 */
final class CloneRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'company_id' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
