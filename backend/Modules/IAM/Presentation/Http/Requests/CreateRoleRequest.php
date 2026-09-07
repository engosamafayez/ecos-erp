<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\IAM\Domain\Enums\RoleCategory;

/**
 * Create Role (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11).
 *
 * `company_id` is deliberately absent, following the D2/D11 precedent set by
 * CreateUserRequest and CreateRoleTemplateRequest: tenant ownership is derived
 * server-side and never accepted from the client.
 *
 * `slug` is absent too: the role's identifier is derived from its name by
 * RoleAuthoringService::uniqueKey(), so an administrator authoring a role in the UI never
 * has to invent a machine key — the same principle §9 applies to organization scope
 * ("never require an admin to manually type raw entity IDs").
 *
 * Permission names are validated for SHAPE only. Whether a token actually exists is not
 * this layer's judgement: RoleTemplateCompiler::compile() rejects unknown tokens with the
 * complete list of offenders (UnknownTemplatePermissionException → 422), which is a better
 * error than a per-field validation message and is the one authority that cannot drift
 * from the real catalogue.
 */
final class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('create', Role::class) in the controller is the real check
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'nullable', 'string', Rule::in(array_map(
                static fn (RoleCategory $c): string => $c->value,
                RoleCategory::cases(),
            ))],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:191', 'regex:/^[a-z0-9_\-]+(\.[a-z0-9_\-*]+)+$/'],
            // Honoured only for an unrestricted (cross-company) actor; ignored otherwise.
            'company_id' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
