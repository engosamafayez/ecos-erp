<?php

declare(strict_types=1);

namespace Modules\POS\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OpenSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'terminal_id'        => ['required', 'string', 'uuid'],
            'cashier_id'         => ['required', 'string', 'uuid'],
            'device_fingerprint' => ['required', 'string', 'max:255'],
            'device_type'        => ['required', 'string', 'max:100'],
            'ip_address'         => ['required', 'ip'],
        ];
    }
}
