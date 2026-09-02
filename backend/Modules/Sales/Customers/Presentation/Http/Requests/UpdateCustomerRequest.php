<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCustomerRequest extends FormRequest
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
        $customerId = (string) $this->route('customer');

        return [
            // brand_id is NOT accepted on update — brand relationships are managed
            // through the customer-brands relationship, not the customer update endpoint.
            // company_id is NOT accepted — immutable after creation.
            // Tenant-scoped, matching the (company_id, code) composite unique constraint
            // (migration 2026_09_10_100001_scope_customers_code_uniqueness_to_company) —
            // a global unique check here would reject a code another company already
            // legitimately uses, even though the database itself now allows it.
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('customers', 'code')
                    ->where(fn ($query) => $query->where('company_id', $this->user()?->company_id))
                    ->ignore($customerId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }
}
