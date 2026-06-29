<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Products\Domain\Enums\CostSource;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Validation for updating a product. The unique `sku` rule ignores the product
 * being updated.
 */
final class UpdateProductRequest extends FormRequest
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
        $productId = (string) $this->route('product');

        return [
            'sku'                  => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($productId)],
            'barcode'              => ['nullable', 'string', 'max:100'],
            'name'                 => ['required', 'string', 'max:255'],
            'description'          => ['nullable', 'string', 'max:1000'],
            'category_id'          => ['required', 'uuid', 'exists:categories,id'],
            'unit_id'              => ['required', 'uuid', 'exists:units,id'],
            'product_type'         => ['required', Rule::in(Product::TYPES)],
            'cost_source'          => ['required', Rule::enum(CostSource::class)],
            'is_active'            => ['boolean'],
            'can_manufacture'      => ['boolean'],
            'can_disassemble'      => ['boolean'],
            'allow_negative_stock' => ['boolean'],
            'image_url'            => ['nullable', 'string', 'url', 'max:2048'],
            'regular_price'        => ['nullable', 'numeric', 'min:0'],
            'sale_price'           => ['nullable', 'numeric', 'min:0'],
            'short_description'    => ['nullable', 'string', 'max:500'],
            'long_description'     => ['nullable', 'string'],
            'stock_status'         => ['nullable', Rule::in(['instock', 'outofstock', 'onbackorder'])],
        ];
    }
}
