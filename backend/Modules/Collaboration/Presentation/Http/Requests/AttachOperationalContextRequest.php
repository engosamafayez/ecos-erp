<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `attached_to_type` accepts only conversation/message in Task 2 — 'task'
 * exists on the enum for Task 4 but has no attachable entity yet.
 */
final class AttachOperationalContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attached_to_type' => ['required', 'in:conversation,message'],
            'attached_to_id' => ['required', 'uuid'],
            'context_type' => ['required', 'in:order,distribution_group,trip,driver'],
            'context_id' => ['required', 'string', 'max:64'],
        ];
    }
}
