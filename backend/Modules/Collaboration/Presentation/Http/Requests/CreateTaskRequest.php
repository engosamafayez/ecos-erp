<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTaskRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'due_at' => ['nullable', 'date'],
            'team_id' => ['nullable', 'uuid', Rule::exists('teams', 'id')],
            // Message -> Create Task (brief §12).
            'source_message_id' => ['nullable', 'uuid', Rule::exists('collaboration_messages', 'id')],
        ];
    }
}
