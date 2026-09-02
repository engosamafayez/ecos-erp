<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TransitionTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:todo,in_progress,done,cancelled'],
        ];
    }
}
