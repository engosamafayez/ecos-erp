<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\GoodsReceipts\Application\Actions\ConfirmReceiptQuantitiesAction;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use RuntimeException;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIER-INVOICE-FINAL-LIFECYCLE-020 §12/§13.
 *
 * "Warehouse Full Rejection" — the case where nothing on an invoice-first receipt is accepted
 * at all, as opposed to a partial receipt (which is never cancellation, see §13 and
 * {@see SupplierInvoiceReceivingSummary}). Deliberately NOT a new GoodsReceiptStatus case —
 * that enum stays Draft/Posted (its own two real states); a receipt that will never post is
 * simply left Draft forever, with every line explicitly confirmed at zero, which is itself
 * enough to make "no inventory is received" true by construction (only PostGoodsReceiptAction
 * moves inventory, and a Draft receipt is never posted). The user-visible outcome lives
 * entirely on the invoice: Cancelled, with the reason recorded in its own `internal_notes`
 * (no new column — the field already existed and was already free-text).
 *
 * Reuses two existing, unmodified authorities rather than building a parallel one:
 * {@see ConfirmReceiptQuantitiesAction} (exact same locking/clamping every warehouse
 * confirmation goes through, called here with every line at 0) and the invoice's own Cancelled
 * status (exact same state a plain Cancel produces — this is not a new status engine).
 */
final class RejectInvoiceReceivingService
{
    public function __construct(
        private readonly ConfirmReceiptQuantitiesAction $confirmQuantities,
        private readonly SupplierInvoiceReceivingSummary $receivingSummary,
    ) {}

    public function execute(SupplierInvoice $invoice, string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A rejection reason is required.');
        }

        if ($invoice->status !== SupplierInvoiceStatus::Validated) {
            throw new RuntimeException(
                "Only a commercially-approved invoice pending receiving can be rejected (status: {$invoice->status->value}).",
            );
        }

        $invoice->loadMissing('autoReceipt');

        if ($invoice->autoReceipt === null) {
            throw new RuntimeException('This invoice has no linked Goods Receipt to reject.');
        }

        // §13 — once anything has been accepted, this is a partial/variance case, never a full
        // rejection. Checked here (informational, before the lock) and re-derived from the
        // authoritative source inside the transaction below — never trusted twice from the same
        // stale read.
        $summary = $this->receivingSummary->for($invoice);

        if ($summary['status'] !== SupplierInvoiceReceivingSummary::AWAITING) {
            throw new RuntimeException(
                'Cannot fully reject — some quantity has already been received against this invoice.',
            );
        }

        DB::transaction(function () use ($invoice, $reason): void {
            /** @var SupplierInvoice|null $locked */
            $locked = SupplierInvoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== SupplierInvoiceStatus::Validated) {
                throw new RuntimeException(
                    'Invoice state changed before the rejection could be applied — reload and try again.',
                );
            }

            $locked->loadMissing('autoReceipt');
            $receipt = $locked->autoReceipt;

            if ($receipt === null) {
                throw new RuntimeException('This invoice has no linked Goods Receipt to reject.');
            }

            // ConfirmReceiptQuantitiesAction re-locks the receipt row itself and re-verifies it
            // is still Draft before writing anything — this pre-fetch is only to build the
            // payload, never trusted as the final word on receipt state.
            $lineIds = GoodsReceiptLine::query()
                ->where('goods_receipt_id', $receipt->id)
                ->pluck('id');

            $zeroLines = $lineIds->map(fn (string $lineId): array => ['line_id' => $lineId, 'accepted_qty' => 0.0])->all();

            if ($zeroLines !== []) {
                $this->confirmQuantities->execute((string) $receipt->id, $zeroLines);
            }

            $stamped = 'Warehouse rejected (full receiving rejection) — '.trim($reason);
            $existingNotes = trim((string) ($locked->internal_notes ?? ''));

            $locked->update([
                'status' => SupplierInvoiceStatus::Cancelled,
                'internal_notes' => $existingNotes === '' ? $stamped : $existingNotes."\n".$stamped,
            ]);
        });
    }
}
