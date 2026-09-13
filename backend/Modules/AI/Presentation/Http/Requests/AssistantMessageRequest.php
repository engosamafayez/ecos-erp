<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * §26/§27 — every bound enforced here BEFORE anything reaches AIAssistantService:
 * message length, recent-history count, and the shape of each history entry.
 */
final class AssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Permission is enforced inside AIAssistantService/AIToolInvoker, not here.
    }

    public function rules(): array
    {
        $maxMessageLength = (int) config('ai.max_message_length', 2000);
        $maxRecentMessages = (int) config('ai.max_recent_messages', 12);

        return [
            'message' => ['required', 'string', 'max:'.$maxMessageLength],
            'route' => ['nullable', 'string', 'max:255'],
            'module' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'string', 'max:100'],
            'brand_id' => ['nullable', 'string', 'max:100'],
            'history' => ['sometimes', 'array', 'max:'.$maxRecentMessages],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:'.$maxMessageLength],
        ];
    }
}
