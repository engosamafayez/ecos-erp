<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreSupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The acting user's company — the tenant boundary every anchor must fall inside. */
    private function actorCompanyId(): ?string
    {
        $companyId = $this->user()?->company_id;

        return $companyId === null ? null : (string) $companyId;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_invoice_ref' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'delivery_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'freight_amount' => ['nullable', 'numeric', 'min:0'],
            'additional_costs' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:50'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],

            // ── The receipt line this invoice line settles (V-5) ──────────────
            //
            // Optional at the contract boundary because not every invoice settles a receipt: a
            // Mode 3 invoice IS the inbound and anchors nothing. Where the anchor IS required —
            // Mode 1, to clear GRNI at the valuation the receipt committed — that requirement is
            // enforced by InvoiceReceiptAnchorService at POSTING time, which is the only place
            // that knows the company's goods-inward mode. Making it `required` here would refuse
            // Mode 3 invoices outright and change a certified contract.
            //
            // TENANT-SCOPED EXISTENCE, not a bare `exists:`. The receipt line must belong to a
            // Goods Receipt owned by the actor's company — the same shape already used for
            // `purchase_material_line_id` in StoreGoodsReceiptRequest, and for the same reason:
            // a scope-blind global lookup is exactly how the legacy purchase_order_line_id became
            // a cross-tenant edge. Without this, an invoice could anchor to another company's
            // receipt line and read its `landed_unit_cost` back through the GRNI and PPV legs.
            //
            // Fails closed: a null actor company yields no matching row, so the anchor is refused
            // rather than silently accepted. The message is Laravel's generic invalid-selection
            // text, so a foreign id is not distinguished from a non-existent one.
            'lines.*.goods_receipt_line_id' => [
                'nullable',
                'uuid',
                Rule::exists('goods_receipt_lines', 'id')->where(
                    fn ($q) => $q->whereIn(
                        'goods_receipt_id',
                        DB::table('goods_receipts')->select('id')->where('company_id', $this->actorCompanyId()),
                    ),
                ),
            ],
            'lines.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.uom_id_snapshot' => ['nullable', 'string'],
            'lines.*.uom_name_snapshot' => ['nullable', 'string', 'max:50'],
            'lines.*.uom_symbol_snapshot' => ['nullable', 'string', 'max:20'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
