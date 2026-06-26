<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\DomainEvents\Contracts\DomainEventBus;
use Modules\Inventory\DomainEvents\Events\InventoryStockShipped;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InvalidInventoryMovementException;

/**
 * Records physical shipment of stock out of a warehouse.
 *
 * Decreases on_hand_qty. Also decreases reserved_qty by the same amount
 * (clamped to 0 if the shipment was not pre-reserved).
 *
 * Publishes InventoryStockShipped AFTER the transaction commits successfully.
 */
final class ShipStockAction extends BaseAction
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventory,
        private readonly DomainEventBus $eventBus,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof StockOperationDTO) {
            throw new InvalidArgumentException('ShipStockAction::execute expects a StockOperationDTO.');
        }

        if ($dto->quantity <= 0) {
            throw new InvalidInventoryMovementException('Quantity must be greater than zero');
        }

        $onHandBefore   = null;
        $onHandAfter    = null;
        $reservedBefore = null;
        $reservedAfter  = null;

        $result = DB::transaction(function () use ($dto, &$onHandBefore, &$onHandAfter, &$reservedBefore, &$reservedAfter) {
            $item = $this->inventory->findByWarehouseAndProduct(
                $dto->warehouse_id,
                $dto->product_id,
            );

            if ($item === null) {
                throw new InvalidInventoryMovementException('No inventory record found for the given warehouse and product');
            }

            $locked = $this->inventory->lockForUpdate($item->id);

            if ($locked === null) {
                throw new InvalidInventoryMovementException('InventoryItem disappeared during transaction');
            }

            $onHandBefore   = (float) $locked->on_hand_qty;
            $reservedBefore = (float) $locked->reserved_qty;

            if ($onHandBefore < $dto->quantity) {
                throw new InsufficientStockException(
                    $dto->product_id,
                    $dto->warehouse_id,
                    $dto->quantity,
                    $onHandBefore,
                );
            }

            if ($reservedBefore < $dto->quantity) {
                throw new InvalidInventoryMovementException(
                    'Cannot ship stock that is not reserved'
                );
            }

            $onHandAfter   = $onHandBefore - $dto->quantity;
            $reservedAfter = $reservedBefore - $dto->quantity;

            $locked->on_hand_qty  = $onHandAfter;
            $locked->reserved_qty = $reservedAfter;
            $this->inventory->save($locked);

            $this->inventory->recordEntry([
                'inventory_item_id' => $locked->id,
                'warehouse_id'      => $dto->warehouse_id,
                'product_id'        => $dto->product_id,
                'company_id'        => $dto->company_id,
                'movement_type'     => LedgerMovementType::SalesIssue->value,
                'quantity'          => $dto->quantity,
                'on_hand_before'    => $onHandBefore,
                'on_hand_after'     => $onHandAfter,
                'reserved_before'   => $reservedBefore,
                'reserved_after'    => $reservedAfter,
                'reference_type'    => $dto->reference_type,
                'reference_id'      => $dto->reference_id,
                'notes'             => $dto->notes,
            ]);

            $locked->refresh();

            return $locked;
        });

        // ── Publish after commit ─────────────────────────────────────────────
        $this->eventBus->publish(new InventoryStockShipped(
            inventoryItemId: $result->id,
            warehouseId:     $dto->warehouse_id,
            productId:       $dto->product_id,
            companyId:       $dto->company_id,
            quantityShipped: $dto->quantity,
            onHandBefore:    $onHandBefore    ?? 0.0,
            onHandAfter:     $onHandAfter     ?? 0.0,
            reservedBefore:  $reservedBefore  ?? 0.0,
            reservedAfter:   $reservedAfter   ?? 0.0,
            referenceType:   $dto->reference_type,
            referenceId:     $dto->reference_id,
        ));

        return OperationResult::success($result, 'Stock shipped successfully.');
    }
}
