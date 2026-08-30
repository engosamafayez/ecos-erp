<?php

declare(strict_types=1);

namespace Modules\Organization\Companies\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for creating a company.
 * Code is optional — auto-generated as COM-000001 if omitted.
 */
final class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:20', 'unique:companies,code'],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'commercial_registration' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:500'],
            'currency' => ['nullable', 'string', 'max:8'],
            // Must be a real IANA identifier (G7) so the tenant clock can never be
            // set to an invalid zone that would break presentation-boundary conversion.
            'timezone' => ['nullable', 'string', 'timezone'],
            'language' => ['nullable', 'string', 'max:10'],
            'locale' => ['nullable', 'string', 'max:20'],
            'date_format' => ['nullable', 'string', 'max:20'],
            'number_format' => ['nullable', 'string', 'max:20'],
            'week_start' => ['nullable', 'string', 'in:Saturday,Sunday,Monday'],
            'fiscal_year_start' => ['nullable', 'date_format:Y-m-d'],
            'fiscal_year_end' => ['nullable', 'date_format:Y-m-d', 'after:fiscal_year_start'],
            'description' => ['nullable', 'string', 'max:5000'],
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'logo' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }
}
