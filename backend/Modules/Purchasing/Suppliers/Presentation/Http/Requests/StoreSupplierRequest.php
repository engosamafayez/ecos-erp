<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a supplier. `code` is normally omitted — the backend
 * generates it (SupplierCodeGeneratorService) — but may be supplied explicitly
 * (e.g. import/seed tooling); when supplied it must be unique within the company.
 */
final class StoreSupplierRequest extends FormRequest
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
        $companyId = Auth::user()?->company_id;

        return [
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('suppliers', 'code')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'supplier_category_id' => [
                'nullable',
                'uuid',
                Rule::exists('supplier_categories', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true)),
            ],
            // Multiple Categories (TASK-...-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §A.1) — the
            // canonical many-to-many replacement for supplier_category_id above. Same tenant +
            // active-only guard as the legacy singular field.
            'supplier_category_ids' => ['array'],
            'supplier_category_ids.*' => [
                'uuid',
                Rule::exists('supplier_categories', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true)),
            ],
            // Supply Capabilities (TASK-...-SUPPLY-CAPABILITIES-003) — backend-authoritative:
            // a Raw Material must belong to THIS company and actually be raw-material typed;
            // a Category must exist in the shared, non-tenant catalog with an appropriate scope.
            'raw_material_ids' => ['array'],
            'raw_material_ids.*' => [
                'uuid',
                Rule::exists('products', 'id')->where(fn ($q) => $q
                    ->where('company_id', $companyId)
                    ->where('product_type', 'raw_material')),
            ],
            'product_category_ids' => ['array'],
            'product_category_ids.*' => [
                'uuid',
                Rule::exists('categories', 'id')->where(fn ($q) => $q
                    ->whereIn('category_scope', ['product', 'material'])
                    ->where('is_active', true)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'google_maps_url' => ['nullable', 'string', 'max:1000'],
            'opening_balance_amount' => ['nullable', 'numeric', 'min:0'],
            'opening_balance_type' => ['nullable', 'in:debit,credit'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }
}
