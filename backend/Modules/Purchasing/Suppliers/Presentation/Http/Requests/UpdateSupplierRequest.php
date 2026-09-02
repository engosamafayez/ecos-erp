<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a supplier. `code` is accepted-but-ignored by
 * UpdateSupplierAction (backend-owned, never regenerated on edit); the rule
 * here stays permissive so the field is harmless whether or not a client
 * still sends the existing value back.
 */
final class UpdateSupplierRequest extends FormRequest
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
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'supplier_category_id' => [
                'nullable',
                'uuid',
                Rule::exists('supplier_categories', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId)->where('is_active', true)),
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
