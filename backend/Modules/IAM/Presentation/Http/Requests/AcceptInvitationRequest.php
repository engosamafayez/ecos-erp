<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Public, unauthenticated request (CORE-02 Task 1) — the invitee has no account/session yet.
 * Deliberately carries NO role/company/permission field of any kind: acceptance only ever sets
 * a password on the pre-provisioned account the token identifies (UserInvitationService::activate()).
 * There is no field here an invitee could use to choose or influence their own access.
 */
final class AcceptInvitationRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'require_password_change' => ['sometimes', 'boolean'],
        ];
    }
}
