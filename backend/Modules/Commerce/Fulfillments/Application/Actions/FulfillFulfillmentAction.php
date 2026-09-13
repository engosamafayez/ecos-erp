<?php

declare(strict_types=1);

namespace Modules\Commerce\Fulfillments\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Fulfillments\Domain\Contracts\FulfillmentRepositoryInterface;
use Modules\Commerce\Fulfillments\Domain\Enums\FulfillmentStatus;
use Modules\Commerce\Fulfillments\Domain\Exceptions\FulfillmentNotFoundException;
use Modules\Commerce\Fulfillments\Domain\Exceptions\FulfillmentNotFulfillableException;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001 (fifth-track fulfillment-inventory-authority closure) —
 * marks a commercial Fulfillment record as fulfilled. It performs NO physical stock mutation.
 *
 * WHY (source evidence): physical warehouse stock in ECOS is owned exclusively by InventoryItem
 * and the canonical Inventory actions. A sales order's physical issue runs through
 * ShipOrderInventoryAction → ShipStockAction, which decrements InventoryItem.on_hand_qty/
 * reserved_qty, consumes FIFO layers (InventoryLayerConsumptionService), stamps COGS/margin on
 * the order, and is guarded against double issue by Order.inventory_shipped_at
 * (OrderAlreadyShippedException).
 *
 * This action previously deducted `StockBalance` (Purchasing\GoodsReceipts) and logged a
 * StockMovement — a SEPARATE, competing physical ledger that InventoryItem, reservations, OPS
 * reporting, and ProductCommerceAvailabilityService never read. `StockBalance` is written and read
 * by nothing else in the system (its only other appearance is a test asserting it drives no Woo
 * sync, and a provider comment calling it the "legacy, unreconciled StockBalance table"); it is
 * never populated with positive stock, so this branch would in fact have thrown InsufficientStock
 * for any real order. It therefore never performed a real physical issue against the authoritative
 * ledger — it maintained a competing physical truth that could diverge from InventoryItem for the
 * same order.
 *
 * The competing StockBalance mutation is retired. No canonical Inventory deduction is added in its
 * place: doing so would make this standalone CRUD endpoint a SECOND physical-issue entrypoint
 * parallel to canonical dispatch, and — with no cross-guard — a source of double-deduction against
 * InventoryItem for an already-dispatched order. Physical issue stays solely with the canonical
 * dispatch path; this action keeps only its commercial responsibility (recording the fulfillment).
 * Re-fulfilment is still blocked by the Pending-status guard below.
 */
final class FulfillFulfillmentAction extends BaseAction
{
    public function __construct(
        private readonly FulfillmentRepositoryInterface $fulfillments,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $fulfillment = $this->fulfillments->findById($id);

        if ($fulfillment === null) {
            throw new FulfillmentNotFoundException($id);
        }

        if ($fulfillment->status !== FulfillmentStatus::Pending) {
            throw new FulfillmentNotFulfillableException($fulfillment->status->value);
        }

        $fulfillment->update(['status' => FulfillmentStatus::Fulfilled->value]);

        return OperationResult::success(
            $this->fulfillments->findById($id),
            'Fulfillment completed. Physical stock is issued by the canonical dispatch flow (InventoryItem); this record is commercial only.',
        );
    }
}
