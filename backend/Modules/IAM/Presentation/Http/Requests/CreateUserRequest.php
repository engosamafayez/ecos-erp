<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D2/D3 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): company_id is deliberately NOT a
 * validated field here — it is never accepted from the client, on create or update. The
 * controller derives it server-side via TenantOwnershipResolver.
 */
final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level Gate::authorize('create', User::class) is the real check
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'avatar_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
