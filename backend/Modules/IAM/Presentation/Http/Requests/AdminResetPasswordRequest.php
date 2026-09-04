<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * D5 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): one canonical password-strength
 * rule — Laravel's Password::defaults() V1 baseline, no existing canonical project wrapper
 * was found. Applied here for admin reset; the same rule must be applied consistently
 * wherever else a password is set (self-service reset, invitation activation) — out of this
 * request's direct control, but documented so drift is visible.
 */
final class AdminResetPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'require_password_change' => ['sometimes', 'boolean'],
        ];
    }
}
