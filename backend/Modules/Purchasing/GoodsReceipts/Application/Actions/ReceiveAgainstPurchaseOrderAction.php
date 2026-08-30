<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use RuntimeException;

/**
 * TASK-PROCUREMENT-PO-DRIVEN-RECEIVING-CENTER-001 — receive actual quantities against a
 * Purchase Order from the Receiving Center.
 *
 * This is an ORCHESTRATOR, not a new receiving or inventory authority. It composes the two
 * CERTIFIED actions the manual Goods-Receipt path already uses — {@see CreateGoodsReceiptAction}
 * (draft receipt from the PO + the operator's actual quantities) and {@see PostGoodsReceiptAction}
 * (the sole canonical inventory posting, with its over-receipt ceiling and locked idempotency) —
 * inside a SINGLE transaction so a failed post never leaves an orphan draft. It introduces no new
 * inventory movement, no new stock action, and no new inbound mode; `goods_inward_mode` is
 * untouched and the Supplier Invoice remains the downstream financial document.
 *
 * The Warehouse supplies only "receive now" per line; expected quantity and unit price come from
 * the Purchase Order, never from the client. Only lines with a positive quantity are received, so
 * a partial receipt naturally leaves the PO's remaining quantity receivable by a later run.
 */
final class ReceiveAgainstPurchaseOrderAction extends BaseAction
{
    public function __construct(
        private readonly CreateGoodsReceiptAction $create,
        private readonly PostGoodsReceiptAction $post,
    ) {}

    /**
     * @param  PurchaseOrder  ...$arguments  [0] the PO, [1] list<array{purchase_order_line_id:string, receive_now:float}>
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $po = $arguments[0] ?? null;
        /** @var array<int, array{purchase_order_line_id: string, receive_now: float|int|string}> $receiveLines */
        $receiveLines = $arguments[1] ?? [];

        if (! $po instanceof PurchaseOrder) {
            throw new InvalidArgumentException('ReceiveAgainstPurchaseOrderAction expects a PurchaseOrder.');
        }

        // The receiving warehouse comes from the PO — the PO is the receiving document (§5/§7).
        // A PO with no warehouse cannot be received without inventing a destination.
        if ($po->warehouse_id === null || $po->warehouse_id === '') {
            throw new RuntimeException('This purchase order has no receiving warehouse; it cannot be received.');
        }

        // Index the PO's own lines — expected quantity, product and price are taken from here,
        // never from the request payload.
        $poLines = $po->lines->keyBy('id');

        $dtoLines = [];
        foreach ($receiveLines as $entry) {
            $lineId = (string) ($entry['purchase_order_line_id'] ?? '');
            $receiveNow = round((float) ($entry['receive_now'] ?? 0), 4);

            // Zero / blank lines are skipped, not received — a partial receipt names only the
            // lines actually arriving now.
            if ($receiveNow <= 0.0) {
                continue;
            }

            $poLine = $poLines->get($lineId);
            if ($poLine === null) {
                throw new RuntimeException("Purchase order line [{$lineId}] does not belong to this purchase order.");
            }

            $dtoLines[] = [
                'purchase_order_line_id' => (string) $poLine->id,
                'product_id' => (string) $poLine->product_id,
                // Expected quantity is the PO line's ordered quantity — the client never sets it.
                'ordered_quantity' => (float) $poLine->quantity,
                // Actual accepted quantity = the operator's "receive now". gross == net here: this
                // flow captures received quantity, and the accepted/damaged split is a documented
                // deferred gap (no canonical disposition contract on goods_receipt_lines).
                'gross_received_quantity' => $receiveNow,
                'net_received_quantity' => $receiveNow,
                // Price comes from the PO line inside CreateGoodsReceiptAction; passed for completeness.
                'unit_price' => (float) $poLine->unit_price,
            ];
        }

        if ($dtoLines === []) {
            throw new RuntimeException('Enter a quantity to receive on at least one line.');
        }

        $dto = GoodsReceiptDTO::fromArray([
            'purchase_order_id' => (string) $po->id,
            'warehouse_id' => (string) $po->warehouse_id,
            'receipt_date' => now()->toDateString(),
            'lines' => $dtoLines,
        ]);

        // ONE transaction wrapping both certified actions: CreateGoodsReceiptAction validates the
        // PO is receivable (Approved / PartiallyReceived) and builds the draft; PostGoodsReceiptAction
        // performs the canonical inventory posting (over-receipt ceiling + idempotency). A failure in
        // either rolls the whole thing back — no orphan draft, no partial inventory.
        return DB::transaction(function () use ($dto): OperationResult {
            /** @var GoodsReceipt $receipt */
            $receipt = $this->create->execute($dto)->data();

            $posted = $this->post->execute((string) $receipt->id)->data();

            return OperationResult::success($posted, 'Goods received against purchase order.');
        });
    }
}
