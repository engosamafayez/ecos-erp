<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRoleTemplateRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', 'max:32'],
            'is_composable' => ['sometimes', 'boolean'],
            'definition' => ['sometimes', 'array'],
            'definition.permissions' => ['sometimes', 'array'],
            'definition.permissions.*' => ['string'],
            'change_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
