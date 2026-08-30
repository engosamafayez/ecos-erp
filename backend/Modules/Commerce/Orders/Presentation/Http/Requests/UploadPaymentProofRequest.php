<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-PAYMENT-PROOF-LIFECYCLE-001 §16 — file validation reuses the platform's
 * existing upload convention (MediaController): image or PDF, max 10 MB. The
 * `file`/`mimes` rules validate the ACTUAL file, so the client-provided MIME type
 * is never trusted on its own. Route middleware enforces the upload permission.
 */
class UploadPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,gif,pdf'],
        ];
    }
}
