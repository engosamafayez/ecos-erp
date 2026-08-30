<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-PAYMENT-PROOF-LIFECYCLE-001 §6 — a rejection reason is required. A validated
 * free-text reason (no fixed enum — none exists in an approved contract). Route
 * middleware enforces the reject permission.
 */
class RejectPaymentProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
