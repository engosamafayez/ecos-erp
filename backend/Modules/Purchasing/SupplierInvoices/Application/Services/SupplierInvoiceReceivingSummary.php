<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Application\Services;

use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService;

/**
 * TASK-ECOS-PROCUREMENT-INVOICE-FIRST-RECEIVING-FLOW-014.
 *
 * The receiving/reconciliation read-model for a Supplier Invoice — DERIVED, never stored,
 * mirroring {@see SupplierInvoicePaymentSummary}'s own convention exactly (no new invoice
 * status engine, per §10: "add derived receiving/readiness information where needed").
 *
 * "Accepted" quantity per line is TASK-ECOS-V1-REMEDIATION-PROCUREMENT-035A's
 * {@see InvoiceReceiptAnchorService::physicallyAcceptedQuantity()} — deliberately NOT the
 * posted-only {@see InvoiceReceiptAnchorService::reconciledQuantity()} that feeds the actual AP
 * posting basis. A quantity the warehouse has confirmed on a still-Draft invoice-first receipt
 * is physically real immediately; the UI (and the Warehouse-Full-Rejection safety guard built
 * on this summary) must reflect that right away, not only once the receipt eventually posts.
 * The money still waits for `reconciledQuantity()`'s posted-only truth — see that method's own
 * docblock for why they are allowed to disagree while a receipt is still Draft.
 *
 * Purely a READ surface: creates nothing, edits nothing, posts nothing. Receipt creation/sync
 * is {@see InvoiceReceivingLinkService}; posting/inventory stays the existing, untouched
 * `PostGoodsReceiptAction`.
 */
final class SupplierInvoiceReceivingSummary
{
    /** No linked receipt exists — a legacy, manually-anchored invoice or a Mode-3 company. */
    public const NOT_APPLICABLE = 'not_applicable';

    /** The linked receipt exists but nothing has been accepted against any line yet. */
    public const AWAITING = 'awaiting';

    /** Some, but not all, lines have an accepted quantity recorded. */
    public const PARTIALLY_RECEIVED = 'partially_received';

    /** The linked receipt has posted — every accepted quantity is final and authoritative. */
    public const RECONCILED = 'reconciled';

    private const EPSILON = 0.0001;

    public function __construct(
        private readonly InvoiceReceiptAnchorService $anchors,
    ) {}

    /**
     * @return array{
     *   status: string,
     *   receipt_id: string|null,
     *   receipt_number: string|null,
     *   receipt_status: string|null,
     *   ready_to_post: bool,
     *   lines: list<array{
     *     line_id: string, product_id: string, product_name: string|null, sku: string|null,
     *     expected_qty: float, accepted_qty: float, variance: float,
     *     unit_price: float, final_landed_unit_cost: float|null
     *   }>
     * }
     */
    public function for(SupplierInvoice $invoice): array
    {
        $invoice->loadMissing(['lines.product', 'autoReceipt']);

        $receipt = $invoice->autoReceipt;

        if ($receipt === null) {
            return [
                'status' => self::NOT_APPLICABLE,
                'receipt_id' => null,
                'receipt_number' => null,
                'receipt_status' => null,
                'ready_to_post' => true,
                'lines' => [],
            ];
        }

        $lines = $invoice->lines
            ->filter(fn (SupplierInvoiceLine $l): bool => (float) $l->quantity > 0)
            ->map(function (SupplierInvoiceLine $l): array {
                $expected = round((float) $l->quantity, 4);
                $accepted = $this->anchors->physicallyAcceptedQuantity($l);

                return [
                    'line_id' => (string) $l->id,
                    'product_id' => (string) $l->product_id,
                    'product_name' => $l->product?->name,
                    'sku' => $l->product?->sku,
                    'expected_qty' => $expected,
                    'accepted_qty' => $accepted,
                    'variance' => round($accepted - $expected, 4),
                    'unit_price' => (float) $l->unit_price,
                    'final_landed_unit_cost' => $l->landed_unit_cost !== null ? (float) $l->landed_unit_cost : null,
                ];
            })
            ->values()
            ->all();

        $status = $this->status($receipt->status, $lines);

        return [
            'status' => $status,
            'receipt_id' => (string) $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'receipt_status' => $receipt->status->value,
            // §10 — a Supplier Invoice must never show "Ready to Post" while receiving is
            // incomplete. Mirrors exactly what InvoiceReceiptAnchorService::resolve() will
            // itself enforce at validate()/post() time — this is the SAME fact, surfaced for
            // display before the user attempts the action, not a second gate.
            'ready_to_post' => $status === self::RECONCILED,
            'lines' => $lines,
        ];
    }

    /**
     * @param  list<array{accepted_qty: float}>  $lines
     */
    private function status(GoodsReceiptStatus $receiptStatus, array $lines): string
    {
        if ($receiptStatus === GoodsReceiptStatus::Posted) {
            return self::RECONCILED;
        }

        foreach ($lines as $line) {
            if ($line['accepted_qty'] > self::EPSILON) {
                return self::PARTIALLY_RECEIVED;
            }
        }

        return self::AWAITING;
    }
}
