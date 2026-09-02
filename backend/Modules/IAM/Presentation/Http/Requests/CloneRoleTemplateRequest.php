<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CloneRoleTemplateRequest extends FormRequest
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
            'new_key' => ['required', 'string', 'max:191', 'alpha_dash'],
            'new_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
