<?php

declare(strict_types=1);

namespace Modules\Organization\Brands\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-WAREHOUSE-BRAND-PAYMENT-IMPLEMENTATION-001 §A — Brand → Warehouse coverage.
 *
 * `warehouse_ids` is the COMPLETE set of warehouses that should serve the brand
 * (a checkbox list save). `present` allows an empty array — clearing coverage so
 * the brand is served by no warehouse. Route middleware enforces the permission;
 * the controller enforces same-company ownership of each id.
 */
class UpdateBrandWarehouseCoverageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_ids' => ['present', 'array'],
            'warehouse_ids.*' => ['uuid', 'exists:warehouses,id'],
        ];
    }
}
