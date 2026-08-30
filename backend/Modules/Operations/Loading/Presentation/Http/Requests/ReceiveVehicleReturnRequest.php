<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Warehouse return receipt input (TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001, §11).
 * The operator counts what physically came back and splits it into accepted (good) vs
 * damaged. Expected return / shortage are derived canonically — never entered.
 */
final class ReceiveVehicleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'quantity_accepted' => ['required', 'numeric', 'min:0'],
            'quantity_damaged' => ['required', 'numeric', 'min:0'],
            'damage_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
