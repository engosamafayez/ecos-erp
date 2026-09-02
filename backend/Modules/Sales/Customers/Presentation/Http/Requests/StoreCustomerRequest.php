<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCustomerRequest extends FormRequest
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
        return [
            // Commerce context — company_id is injected server-side; only brand_id is accepted from client.
            'brand_id' => [
                'required',
                'uuid',
                Rule::exists('brands', 'id')->where(function ($query): void {
                    $query->where('company_id', $this->user()?->company_id)
                        ->whereNull('deleted_at');
                }),
            ],
            // Optional: omitted (or blank), the backend generates one
            // (CustomerCodeGeneratorService) — never typed in the create UI. If a caller
            // does supply one, it must still be unique within THIS company, not globally
            // (customers.code is now (company_id, code)-unique — see migration
            // 2026_09_10_100001_scope_customers_code_uniqueness_to_company).
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('customers', 'code')->where(
                    fn ($query) => $query->where('company_id', $this->user()?->company_id),
                ),
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
