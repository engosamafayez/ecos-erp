<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * D2/D3 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): company_id is deliberately NOT a
 * validated field here — it is never accepted from the client, on create or update. The
 * controller derives it server-side via TenantOwnershipResolver.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §6/§8/§9/§10 adds four optional inputs so
 * one Create User submission can produce a COMPLETE, usable account instead of a draft that
 * needs three more round trips:
 *
 *   password        — the initial credential (§10). EXACTLY the rule
 *                     AdminResetPasswordRequest applies: `confirmed` plus
 *                     Password::defaults(), the one canonical strength baseline (D5). Not
 *                     weakened, not a second rule; omit the field and behaviour is
 *                     unchanged.
 *   role_templates  — the roles to assign (§8). Assignment still runs through
 *                     UserRoleAssignmentService, so it is template-mediated, self-
 *                     authorizing and audited — never frontend-only state.
 *   organizations   — the organization scope (§9), as real entity references rather than a
 *                     typed-in id triple. Validated against the canonical org tables.
 *   activate        — activate immediately after provisioning (§10), through the canonical
 *                     lifecycle transition, not a raw status write.
 *
 * `username` is now a LOGIN IDENTIFIER (§7), so its uniqueness — including against other
 * users' emails — is enforced server-side in UserIdentityService::assertUniqueIdentity().
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
            // A login identifier: no spaces, no '@' (which would make it look like an email
            // and collide with the email branch of the identifier lookup).
            'username' => ['sometimes', 'nullable', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'avatar_path' => ['sometimes', 'nullable', 'string', 'max:2048'],

            'password' => ['sometimes', 'nullable', 'string', 'confirmed', Password::defaults()],
            'require_password_change' => ['sometimes', 'boolean'],

            'role_templates' => ['sometimes', 'array'],
            'role_templates.*' => ['string', 'max:191'],
            'primary_role_template' => ['sometimes', 'nullable', 'string', 'max:191'],

            'organizations' => ['sometimes', 'array'],
            'organizations.*.org_type' => ['required', 'string', 'max:32'],
            'organizations.*.org_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'organizations.*.label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'organizations.*.primary' => ['sometimes', 'boolean'],

            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
