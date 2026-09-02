<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D11 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): company_id is deliberately NOT a
 * validated field — every custom template is server-derived to the actor's own company,
 * exactly like User creation (D2). A client cannot create a template owned by another company.
 */
final class CreateRoleTemplateRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:191', 'alpha_dash'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:32'],
            'is_composable' => ['sometimes', 'boolean'],
            'definition' => ['required', 'array'],
            'definition.permissions' => ['sometimes', 'array'],
            'definition.permissions.*' => ['string'],
        ];
    }
}
