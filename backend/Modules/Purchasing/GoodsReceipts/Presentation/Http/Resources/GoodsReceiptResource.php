<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\PaymentMethod;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\PaymentStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;

/**
 * @mixin GoodsReceipt
 */
final class GoodsReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $totalLandedCosts = (float) $this->freight_amount
            + (float) $this->tax_amount
            + (float) $this->additional_costs;

        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,

            // Purchase Order
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
                'supplier' => $this->purchaseOrder->relationLoaded('supplier') && $this->purchaseOrder->supplier !== null
                    ? ['id' => $this->purchaseOrder->supplier->id, 'name' => $this->purchaseOrder->supplier->name]
                    : null,
            ]),

            // Warehouse
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->id,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),

            // Dates and status
            'receipt_date' => $this->receipt_date?->toDateString(),
            'status' => $this->status->value,
            'notes' => $this->notes,

            // Supplier invoice
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'supplier_invoice_date' => $this->supplier_invoice_date?->toDateString(),
            'invoice_attachment_path' => $this->invoice_attachment_path,
            'invoice_attachment_url' => $this->invoice_attachment_path
                ? Storage::url($this->invoice_attachment_path)
                : null,

            // Invoice financials
            'invoice_total_amount' => (float) $this->invoice_total_amount,
            'paid_amount' => (float) $this->paid_amount,
            'outstanding_amount' => $this->outstandingAmount(),
            'freight_amount' => (float) $this->freight_amount,
            'tax_amount' => (float) $this->tax_amount,
            'additional_costs' => (float) $this->additional_costs,
            'total_landed_costs' => $totalLandedCosts,

            // Payment tracking
            'payment_status' => $this->payment_status instanceof PaymentStatus
                ? $this->payment_status->value
                : ($this->payment_status ?? PaymentStatus::Unpaid->value),
            'payment_status_label' => $this->payment_status instanceof PaymentStatus
                ? $this->payment_status->label()
                : PaymentStatus::Unpaid->label(),
            'payment_method' => $this->payment_method instanceof PaymentMethod
                ? $this->payment_method->value
                : $this->payment_method,
            'payment_method_label' => $this->payment_method instanceof PaymentMethod
                ? $this->payment_method->label()
                : null,
            'payment_terms_days' => $this->payment_terms_days,
            'payment_due_date' => $this->payment_due_date?->toDateString(),

            // Posting info
            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toIso8601String(),

            // TASK-...-014 — invoice-first origin. `is_invoice_originated` lets the frontend
            // decide whether to show the confirm-quantities flow instead of the generic PO/PM
            // edit form (which cannot represent this anchor type at all — see the action's own
            // docblock). `supplier_invoice` is the reverse link (Goods Receipt → Open Supplier
            // Invoice), derived from whichever line carries the anchor — every line on an
            // invoice-originated receipt names the SAME invoice, by construction.
            'is_invoice_originated' => $this->whenLoaded(
                'lines',
                fn (): bool => $this->lines->contains(fn ($line) => $line->supplier_invoice_line_id !== null),
            ),
            'supplier_invoice' => $this->whenLoaded('lines', function () {
                $invoice = $this->lines
                    ->map(fn ($line) => $line->supplierInvoiceLine?->supplierInvoice)
                    ->first(fn ($inv) => $inv !== null);

                return $invoice === null ? null : [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ];
            }),

            // Lines
            'lines' => GoodsReceiptLineResource::collection($this->whenLoaded('lines')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
