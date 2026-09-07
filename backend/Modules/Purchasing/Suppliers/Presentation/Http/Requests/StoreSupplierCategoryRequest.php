<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        return [
            // §3 — code is always server-generated (CreateSupplierCategoryAction /
            // SupplierCategoryCodeGeneratorService), never taken from client input, so it is
            // deliberately not validated/accepted here any more.
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
