<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use Modules\Purchasing\GoodsReceipts\Domain\Contracts\GoodsReceiptRepositoryInterface;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptNotEditableException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptNotFoundException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseMaterialReceivingException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;

/**
 * TASK-ECOS-PROCUREMENT-INVOICE-FIRST-RECEIVING-FLOW-014.
 *
 * Records the warehouse's actual accepted quantity against an invoice-first Goods
 * Receipt's still-Draft lines — the step between "the invoice auto-created this
 * receipt with expected quantities" and "Post" (which is what actually moves
 * inventory; untouched by this action). Deliberately scoped to invoice-anchored
 * receipts ONLY: the legacy PO- and Purchase-Material-driven flows already record
 * their actual quantity at creation time (the warehouse clerk types what they
 * counted directly into the create form) and have no such intermediate step to
 * extend — this does not change that, and is not reachable for those receipts.
 *
 * Deliberately NOT the generic `UpdateGoodsReceiptAction`: that action's own
 * request (`UpdateGoodsReceiptRequest`) hard-requires `purchase_order_id` and
 * `lines.*.purchase_order_line_id`, and its line-mapping drops both the
 * Purchase-Material and Supplier-Invoice anchors on every write. Reusing it here
 * would either be rejected outright or silently erase this receipt's own anchor —
 * a new, narrowly-scoped action is the safer, smaller change.
 */
final class ConfirmReceiptQuantitiesAction extends BaseAction
{
    public function __construct(private readonly GoodsReceiptRepositoryInterface $receipts) {}

    /**
     * @param  list<array{line_id: string, accepted_qty: float}>  $acceptedLines
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $acceptedLines = $arguments[1] ?? [];

        $receipt = $this->receipts->findById($id);

        if ($receipt === null) {
            throw new GoodsReceiptNotFoundException($id);
        }

        if (! $receipt->status->isEditable()) {
            throw new GoodsReceiptNotEditableException($receipt->receipt_number);
        }

        $receipt->loadMissing('lines');

        $linesById = $receipt->lines->keyBy('id');

        foreach ($linesById as $line) {
            /** @var GoodsReceiptLine $line */
            if ($line->supplier_invoice_line_id === null) {
                // Scope guard: this action never touches a PO- or Purchase-Material-anchored
                // line, even one sitting alongside an (impossible, per the create-time XOR)
                // mixed receipt — fail loudly rather than silently skip.
                throw PurchaseMaterialReceivingException::mixedAnchors();
            }
        }

        DB::transaction(function () use ($id, $linesById, $acceptedLines): void {
            /** @var GoodsReceipt|null $locked */
            $locked = GoodsReceipt::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new GoodsReceiptNotFoundException($id);
            }

            if (! $locked->status->isEditable()) {
                throw new GoodsReceiptNotEditableException($locked->receipt_number);
            }

            foreach ($acceptedLines as $accepted) {
                $lineId = (string) ($accepted['line_id'] ?? '');
                $line = $linesById->get($lineId);

                if ($line === null) {
                    continue; // Not a line of THIS receipt — silently ignored, never guessed.
                }

                $qty = max(0.0, (float) ($accepted['accepted_qty'] ?? 0));
                $qty = min($qty, (float) $line->ordered_quantity); // §9 — no silent over-receipt.

                GoodsReceiptLine::query()->where('id', $lineId)->update([
                    'received_quantity' => $qty,
                    'gross_received_quantity' => $qty,
                    'net_received_quantity' => $qty,
                    'variance_quantity' => round($qty - (float) $line->ordered_quantity, 4),
                ]);
            }
        });

        return OperationResult::success(
            $this->receipts->findById($id),
            'Receiving quantities confirmed.',
        );
    }
}
