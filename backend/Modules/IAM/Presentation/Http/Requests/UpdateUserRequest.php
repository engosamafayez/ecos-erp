<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * D3 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): company_id is deliberately NOT a
 * validated field — non-writable through normal update, on this or any other IAM Admin
 * endpoint. A future company-transfer workflow, if ever built, is its own explicit contract.
 */
final class UpdateUserRequest extends FormRequest
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
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            // §7 — same login-identifier shape as CreateUserRequest. Uniqueness (including
            // against other users' emails) is enforced server-side in
            // UserIdentityService::assertUniqueIdentity().
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'avatar_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
