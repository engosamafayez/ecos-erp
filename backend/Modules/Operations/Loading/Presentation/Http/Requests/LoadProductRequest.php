<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class LoadProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pool_entry_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'sku_snapshot' => ['required', 'string', 'max:100'],
            'name_snapshot' => ['required', 'string', 'max:255'],
            'preparation_wave_id' => ['required', 'uuid'],
            'quantity_planned' => ['required', 'numeric', 'min:0.001'],
            // Supplementary defense-in-depth: over-load is authoritatively refused
            // server-side in LoadProductAction (fail closed). This lte rule gives a
            // clean field-level 422 for the common client mistake.
            'quantity_loaded' => ['required', 'numeric', 'min:0', 'lte:quantity_planned'],
            'requires_refrigeration' => ['boolean'],
            'short_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
