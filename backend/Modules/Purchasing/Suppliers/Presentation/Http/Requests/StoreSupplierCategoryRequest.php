<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class StoreSupplierCategoryRequest extends FormRequest
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
                'required',
                'string',
                'max:50',
                Rule::unique('supplier_categories', 'code')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
