<?php

declare(strict_types=1);

namespace Modules\Inventory\Transfer\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;

/**
 * Validation for creating a warehouse-to-warehouse stock transfer.
 *
 * Authorization is enforced by the route's `permission:inventory.transfers.create`
 * middleware, not here (matches the established pattern, e.g. StoreWarehouseRequest).
 *
 * Existence/visibility of the referenced warehouses and product is checked via
 * their own Eloquent models rather than a raw `exists:` rule, so a foreign-company
 * warehouse or product — invisible under the model's own tenant global scope —
 * is rejected the same way it would be if it genuinely did not exist, instead of
 * leaking that the row exists somewhere outside the caller's company.
 */
final class StoreWarehouseTransferRequest extends FormRequest
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
            'source_warehouse_id' => ['required', 'uuid'],
            'destination_warehouse_id' => ['required', 'uuid', 'different:source_warehouse_id'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $v->errors()->has('source_warehouse_id')
                && Warehouse::query()->find($this->input('source_warehouse_id')) === null) {
                $v->errors()->add('source_warehouse_id', 'The selected source warehouse is invalid.');
            }

            if (! $v->errors()->has('destination_warehouse_id')
                && Warehouse::query()->find($this->input('destination_warehouse_id')) === null) {
                $v->errors()->add('destination_warehouse_id', 'The selected destination warehouse is invalid.');
            }

            if (! $v->errors()->has('product_id')
                && Product::query()->find($this->input('product_id')) === null) {
                $v->errors()->add('product_id', 'The selected product is invalid.');
            }
        });
    }
}
