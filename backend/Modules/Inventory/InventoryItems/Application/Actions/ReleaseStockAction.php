<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\DomainEvents\Contracts\DomainEventBus;
use Modules\Inventory\DomainEvents\Events\InventoryStockReleased;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InvalidInventoryMovementException;
use Modules\Inventory\InventoryItems\Domain\Exceptions\NegativeInventoryException;

/**
 * Releases a prior reservation (e.g. order cancelled before fulfilment).
 *
 * Decreases reserved_qty. Does not touch on_hand_qty.
 *
 * Publishes InventoryStockReleased AFTER the transaction commits successfully.
 */
final class ReleaseStockAction extends BaseAction
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventory,
        private readonly DomainEventBus $eventBus,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof StockOperationDTO) {
            throw new InvalidArgumentException('ReleaseStockAction::execute expects a StockOperationDTO.');
        }

        if ($dto->quantity <= 0) {
            throw new InvalidInventoryMovementException('Quantity must be greater than zero');
        }

        $event = null;

        $result = DB::transaction(function () use ($dto, &$event) {
            $item = $this->inventory->findByWarehouseProductAndCompany(
                $dto->warehouse_id,
                $dto->product_id,
                $dto->company_id,
            );

            // TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001 (D3) — releasing what was
            // never physically held is a NO-OP, not an error.
            //
            // This used to throw, which is how a customer/address-only edit of a
            // made-to-order order surfaced as HTTP 422 "No inventory record found for the
            // given warehouse and product". Reserve is now symmetric so new orders always
            // have a row, but orders committed before that fix — and any product whose
            // inventory row was never materialised — must still be releasable.
            //
            // No row means nothing is reserved at this warehouse for this product, so the
            // post-condition the caller wants ("this reservation is no longer held") is
            // already true. Nothing is written and no event is published, because nothing
            // moved. A genuine reservation always has a row, so this cannot silently
            // swallow a real release.
            if ($item === null) {
                return null;
            }

            $locked = $this->inventory->lockForUpdate($item->id);

            if ($locked === null) {
                throw new InvalidInventoryMovementException('InventoryItem disappeared during transaction');
            }

            $onHandBefore = (float) $locked->on_hand_qty;
            $reservedBefore = (float) $locked->reserved_qty;
            $reservedAfter = $reservedBefore - $dto->quantity;

            if ($reservedAfter < 0) {
                throw new NegativeInventoryException('reserved_qty', $reservedAfter);
            }

            $locked->reserved_qty = $reservedAfter;
            $this->inventory->save($locked);

            $this->inventory->recordEntry([
                'inventory_item_id' => $locked->id,
                'warehouse_id' => $dto->warehouse_id,
                'product_id' => $dto->product_id,
                'company_id' => $dto->company_id,
                'movement_type' => LedgerMovementType::ReservationRelease->value,
                'quantity' => $dto->quantity,
                'on_hand_before' => $onHandBefore,
                'on_hand_after' => $onHandBefore,
                'reserved_before' => $reservedBefore,
                'reserved_after' => $reservedAfter,
                'reference_type' => $dto->reference_type,
                'reference_id' => $dto->reference_id,
                'notes' => $dto->notes,
            ]);

            $locked->refresh();

            $event = new InventoryStockReleased(
                inventoryItemId: $locked->id,
                warehouseId: $dto->warehouse_id,
                productId: $dto->product_id,
                companyId: $dto->company_id,
                quantityReleased: $dto->quantity,
                reservedBefore: $reservedBefore,
                reservedAfter: $reservedAfter,
                onHandQty: $onHandBefore,
                referenceType: $dto->reference_type,
                referenceId: $dto->reference_id,
            );

            return $locked;
        });

        // Nothing was held, so nothing moved: no ledger entry, no event, no failure.
        if ($result === null) {
            return OperationResult::success(null, 'No reservation held for this warehouse and product; nothing to release.');
        }

        // ── Guarantee publish fires only after the outermost transaction commits ─
        DB::connection()->afterCommit(function () use ($event): void {
            $this->eventBus->publish($event);
        });

        return OperationResult::success($result, 'Stock reservation released.');
    }
}
