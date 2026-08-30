<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Warehouse "receive now" quantities entered against a Purchase Order from the Receiving Center
 * (TASK-PROCUREMENT-PO-DRIVEN-RECEIVING-CENTER-001).
 *
 * The client supplies ONLY the actual quantity received per line; expected quantity, product and
 * price are taken from the PO server-side. `receive_now >= 0` (§10); the over-receipt ceiling is
 * enforced downstream by the canonical PostGoodsReceiptAction, never relaxed here.
 */
final class ReceiveAgainstPurchaseOrderRequest extends FormRequest
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid', 'exists:purchase_order_lines,id'],
            // >= 0 per §10. Zero lines are simply not received; the action requires at least one
            // positive line and refuses an all-zero submission.
            'lines.*.receive_now' => ['required', 'numeric', 'min:0'],
        ];
    }
}
