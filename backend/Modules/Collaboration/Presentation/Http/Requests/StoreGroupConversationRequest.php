<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGroupConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'participant_user_ids' => ['required', 'array', 'min:1'],
            'participant_user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'team_id' => ['nullable', 'uuid', Rule::exists('teams', 'id')],
        ];
    }
}
