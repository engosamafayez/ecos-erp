<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Application\Services;

use Modules\Finance\Payables\Domain\Models\PaymentAllocation;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;

/**
 * TASK-PROCUREMENT-SUPPLIER-INVOICE-COMMERCIAL-CONTRACT-001 §9–§12 — the payment read-model.
 *
 * Paid / Remaining / Payment-Status are DERIVED from the canonical AP settlement authority, never
 * stored on the invoice. The one AP bill for an invoice is resolved by the same convention its
 * writer uses ({@see \Modules\Purchasing\SupplierInvoices\Application\Services\PostSupplierInvoiceService}
 * sets the bill `number` to `'SI-'.$invoice->id`). "Paid" is the sum of immutable
 * {@see \Modules\Finance\Payables\Domain\Models\PaymentAllocation} rows via
 * `SupplierBill::allocatedAmount()`; "Remaining" is `SupplierBill::outstanding()`. This deliberately
 * does NOT reproduce the legacy `goods_receipts.paid_amount` anti-pattern (a hand-entered, cash-unlinked
 * figure the AP subledger was built to replace) — there is no editable paid/remaining column here.
 *
 * Payment status is a read-model fact kept SEPARATE from the invoice document `status` (§12).
 *
 * TASK-PROCUREMENT-SUPPLIER-INVOICE-AP-PAYMENT-INTEGRATION-001 — the read model additionally surfaces
 * the invoice Total, its Due date, and the canonical payment HISTORY: each posted payment applied to
 * this invoice's bill through the AP {@see \Modules\Finance\Payables\Domain\Models\PaymentAllocation}
 * authority (append-only, immutable). This is the READ half of the AP integration — it makes a payment
 * recorded through the canonical Finance/AP flow visible on the invoice. It is NOT a payment writer:
 * nothing here creates, approves, posts, or allocates a payment, and no paid/remaining figure is stored.
 */
final class SupplierInvoicePaymentSummary
{
    public const UNPAID = 'unpaid';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID = 'paid';

    private const EPSILON = 0.0001;

    /**
     * @return array{total: float, paid: float, remaining: float, payment_status: string, billed: bool, bill_number: string|null, due_date: string|null, history: list<array{payment_number: string|null, payment_date: string|null, amount: float, payment_status: string|null}>}
     */
    public function for(SupplierInvoice $invoice): array
    {
        $companyId = (string) ($invoice->company_id ?? $invoice->warehouse?->company_id ?? '');
        $invoiceTotal = round((float) $invoice->grand_total, 4);
        $dueDate = $invoice->due_date?->toDateString();

        $bill = $companyId === ''
            ? null
            : SupplierBill::query()
                ->where('company_id', $companyId)
                ->where('number', 'SI-'.$invoice->id)
                ->first();

        // No AP bill yet: the invoice has not posted a payable (unposted, or Mode-1 with unanchored
        // lines where the payable is skipped by design). Nothing has been paid through the canonical
        // allocation authority, so nothing is fabricated — Paid = 0, Remaining = the invoice total,
        // and the payment history is empty (there is no bill to have received an allocation).
        if ($bill === null) {
            return [
                'total' => $invoiceTotal,
                'paid' => 0.0,
                'remaining' => $invoiceTotal,
                'payment_status' => $this->status(0.0, $invoiceTotal),
                'billed' => false,
                'bill_number' => null,
                'due_date' => $dueDate,
                'history' => [],
            ];
        }

        $paid = $bill->allocatedAmount();
        $reference = round((float) $bill->total, 4);
        $remaining = $bill->isPosted() ? $bill->outstanding() : round($reference - $paid, 4);

        return [
            'total' => $invoiceTotal,
            'paid' => $paid,
            'remaining' => $remaining,
            'payment_status' => $this->status($paid, $reference),
            'billed' => $bill->isPosted(),
            'bill_number' => $bill->number,
            'due_date' => $dueDate,
            'history' => $this->history($bill),
        ];
    }

    /**
     * The canonical, read-only payment history for a bill: one row per immutable allocation, each the
     * amount of a supplier payment applied to THIS invoice's payable. Never written here.
     *
     * @return list<array{payment_number: string|null, payment_date: string|null, amount: float, payment_status: string|null}>
     */
    private function history(SupplierBill $bill): array
    {
        return $bill->allocations()
            ->with('payment')
            ->orderBy('allocated_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (PaymentAllocation $allocation): array => [
                'payment_number' => $allocation->payment?->number,
                'payment_date' => $allocation->payment?->payment_date?->toDateString(),
                'amount' => round((float) $allocation->amount, 4),
                'payment_status' => $allocation->payment?->status?->value,
            ])
            ->values()
            ->all();
    }

    private function status(float $paid, float $total): string
    {
        if ($paid <= self::EPSILON) {
            return self::UNPAID;
        }

        if ($total > 0 && $paid + self::EPSILON < $total) {
            return self::PARTIALLY_PAID;
        }

        return self::PAID;
    }
}
