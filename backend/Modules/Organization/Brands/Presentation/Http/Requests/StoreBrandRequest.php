<?php

declare(strict_types=1);

namespace Modules\Organization\Brands\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A blank slug/code means "generate it" — normalise '' to absent so the optional
     * rules below skip a blank value instead of failing its format/unique check, and
     * the action's canonical auto-generation runs (slug from name, code from sequence).
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['slug', 'code'] as $key) {
            $value = $this->input($key);
            if (is_string($value) && trim($value) === '') {
                $normalized[$key] = null;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $companyId = (string) $this->input('company_id');

        return [
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('brands', 'code')->where(
                    fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'),
                ),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('brands', 'slug')->where(
                    fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'),
                ),
            ],
            'logo' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'default_target_margin' => ['nullable', 'numeric', 'min:0', 'max:99.9999'],
            'default_markup' => ['nullable', 'numeric', 'min:0'],
            'default_discount_pct' => ['nullable', 'numeric', 'min:0', 'max:99.9999'],
        ];
    }
}
