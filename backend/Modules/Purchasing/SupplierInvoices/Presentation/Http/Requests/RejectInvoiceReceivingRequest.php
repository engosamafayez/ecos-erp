<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** TASK-...-020 §12 — "rejection reason required where canonical pattern supports it." */
class RejectInvoiceReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
