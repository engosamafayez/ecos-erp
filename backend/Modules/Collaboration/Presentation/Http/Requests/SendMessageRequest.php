<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `type` accepts only 'text' — image/file/voice are schema-ready
 * (Domain\Enums\MessageType) but their upload/storage pipeline is Task 3's
 * scope, not Task 2's (architecture report §10/§17, brief §21).
 */
final class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'in:text'],
            'body' => ['required', 'string', 'max:10000'],
            'reply_to_message_id' => ['nullable', 'uuid', Rule::exists('collaboration_messages', 'id')],
            'mentioned_user_ids' => ['sometimes', 'array'],
            'mentioned_user_ids.*' => ['integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Only text messages are supported until voice/image/file delivery ships (Task 3).',
        ];
    }
}
