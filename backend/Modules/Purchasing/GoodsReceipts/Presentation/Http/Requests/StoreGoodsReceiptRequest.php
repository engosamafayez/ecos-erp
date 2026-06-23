<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGoodsReceiptRequest extends FormRequest
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
            'purchase_order_id' => ['required', 'uuid', 'exists:purchase_orders,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'receipt_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid', 'exists:purchase_order_lines,id'],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'lines.*.ordered_quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.received_quantity' => ['required', 'numeric', 'min:0', 'lte:lines.*.ordered_quantity'],
        ];
    }
}
