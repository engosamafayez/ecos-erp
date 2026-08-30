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
use Modules\Inventory\Products\Domain\Enums\InventoryClass;
use Modules\Inventory\Products\Domain\Models\Product;

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

        $event = null;

        $result = DB::transaction(function () use ($dto, &$event) {
            $item = $this->inventory->findByWarehouseProductAndCompany(
                $dto->warehouse_id,
                $dto->product_id,
                $dto->company_id,
            );

            if ($item === null) {
                throw new InvalidInventoryMovementException('No inventory record found for the given warehouse and product');
            }

            $locked = $this->inventory->lockForUpdate($item->id);

            if ($locked === null) {
                throw new InvalidInventoryMovementException('InventoryItem disappeared during transaction');
            }

            $onHandBefore = (float) $locked->on_hand_qty;
            $reservedBefore = (float) $locked->reserved_qty;

            // P07 (ADR-027 v1.1): allow_negative_stock is an EXECUTION PERMISSION. A
            // product that carries it may be issued below on_hand — the balance goes
            // negative and a later receipt offsets it naturally (−10 + 20 = +10). This
            // makes ShipStock symmetric with the rest of the family: ReserveStockAction
            // already lets the commitment go negative on the way IN, and
            // DirectIssueStockAction already honours the same flag at issuance (C1).
            // ShipStock was the missing member, so an overdraft that could be RESERVED
            // could never be SHIPPED. The product flag is the SINGLE authority — there
            // is no ship-specific negative-stock override, and the arithmetic below is
            // unchanged. When on_hand already covers the quantity this lookup changes
            // nothing: the guard only ever fired for on_hand < quantity.
            $allowNegative = (bool) Product::query()
                ->where('id', $dto->product_id)
                ->value('allow_negative_stock');

            if (! $allowNegative && $onHandBefore < $dto->quantity) {
                throw new InsufficientStockException(
                    $dto->product_id,
                    $dto->warehouse_id,
                    $dto->quantity,
                    $onHandBefore,
                );
            }

            // Consume-reservation semantics are deliberately UNCHANGED: a shipment
            // consumes an existing reservation, so it must not exceed what is reserved.
            // This guard governs the COMMITMENT, not physical stock, so allow_negative
            // does not relax it — a negative-stock product still ships only what was
            // reserved for it.
            if ($reservedBefore < $dto->quantity) {
                throw new InvalidInventoryMovementException(
                    'Cannot ship stock that is not reserved',
                );
            }

            $onHandAfter = $onHandBefore - $dto->quantity;
            $reservedAfter = $reservedBefore - $dto->quantity;

            $locked->on_hand_qty = $onHandAfter;
            $locked->reserved_qty = $reservedAfter;
            $this->inventory->save($locked);

            $this->inventory->recordEntry([
                'inventory_item_id' => $locked->id,
                'warehouse_id' => $dto->warehouse_id,
                'product_id' => $dto->product_id,
                'company_id' => $dto->company_id,
                'movement_type' => LedgerMovementType::SalesIssue->value,
                'quantity' => $dto->quantity,
                'on_hand_before' => $onHandBefore,
                'on_hand_after' => $onHandAfter,
                'reserved_before' => $reservedBefore,
                'reserved_after' => $reservedAfter,
                'reference_type' => $dto->reference_type,
                'reference_id' => $dto->reference_id,
                'notes' => $dto->notes,
            ]);

            $locked->refresh();

            // Outbound stock is valued at what it cost to acquire — the
            // canonical Inventory cost for this product. Unlike an increase,
            // this value is discoverable: the stock was bought at a price.
            $product = Product::query()->find($dto->product_id);

            $event = new InventoryStockShipped(
                inventoryItemId: $locked->id,
                warehouseId: $dto->warehouse_id,
                productId: $dto->product_id,
                companyId: $dto->company_id,
                quantityShipped: $dto->quantity,
                onHandBefore: $onHandBefore,
                onHandAfter: $onHandAfter,
                reservedBefore: $reservedBefore,
                reservedAfter: $reservedAfter,
                inventoryClass: InventoryClass::fromProductType($product?->product_type, $dto->product_id),
                unitCost: (float) ($product?->current_fifo_cost ?? $product?->average_cost ?? 0.0),
                referenceType: $dto->reference_type,
                referenceId: $dto->reference_id,
            );

            return $locked;
        });

        // ── Guarantee publish fires only after the outermost transaction commits ─
        DB::connection()->afterCommit(function () use ($event): void {
            $this->eventBus->publish($event);
        });

        return OperationResult::success($result, 'Stock shipped successfully.');
    }
}
