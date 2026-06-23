<?php

declare(strict_types=1);

namespace Modules\MasterData\Warehouses\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a warehouse. The unique `code` rule is scoped to the
 * company and ignores the warehouse being updated.
 */
final class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $companyId = (string) $this->input('company_id');
        $warehouseId = (string) $this->route('warehouse');

        return [
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'))
                    ->ignore($warehouseId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
