<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

final class InviteUserRequest extends \Illuminate\Foundation\Http\FormRequest
{
    public function authorize(): bool
    {
        return true; // route-level Gate::authorize('invite', $user) is the real check
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
        ];
    }
}
