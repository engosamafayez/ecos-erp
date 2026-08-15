<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\DomainEvents\Contracts\DomainEventBus;
use Modules\Inventory\DomainEvents\Events\InventoryStockReserved;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InvalidInventoryMovementException;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Allocates stock for an unfulfilled order.
 *
 * Increases reserved_qty after verifying available_qty is sufficient.
 * Throws InsufficientStockException if the request cannot be satisfied.
 *
 * Publishes InventoryStockReserved AFTER the transaction commits successfully.
 */
final class ReserveStockAction extends BaseAction
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventory,
        private readonly DomainEventBus $eventBus,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof StockOperationDTO) {
            throw new InvalidArgumentException('ReserveStockAction::execute expects a StockOperationDTO.');
        }

        if ($dto->quantity <= 0) {
            throw new InvalidInventoryMovementException('Quantity must be greater than zero');
        }

        $event = null;

        $result = DB::transaction(function () use ($dto, &$event) {
            $item = $this->inventory->findOrCreate(
                $dto->warehouse_id,
                $dto->product_id,
                $dto->company_id,
            );

            $locked = $this->inventory->lockForUpdate($item->id);

            if ($locked === null) {
                throw new InvalidInventoryMovementException('InventoryItem disappeared during transaction');
            }

            $onHandBefore = (float) $locked->on_hand_qty;
            $reservedBefore = (float) $locked->reserved_qty;
            $available = $locked->availableQty();

            // Allow Negative Stock is an EXECUTION PERMISSION, and this is the single
            // place the reservation domain enforces it.
            //
            // It does not change the arithmetic: `reserved` still rises by exactly the
            // requested quantity and `available` remains `on_hand − reserved`, which is
            // now free to go negative. What it changes is whether the commitment is
            // ALLOWED when physical stock cannot cover it.
            //
            // Without this the flag was unreachable from reservation: every attempt to
            // reserve beyond available threw, so `reserved_qty` stayed 0 and `available`
            // could never become negative — the reported symptom.
            //
            // Same shape and same source as DirectIssueStockAction, which already
            // consults the flag at issuance (ADR-027 v1.1 P07); this closes the
            // equivalent gap on the reservation side.
            $allowNegative = (bool) Product::query()
                ->where('id', $dto->product_id)
                ->value('allow_negative_stock');

            if (! $allowNegative && $available < $dto->quantity) {
                throw new InsufficientStockException(
                    $dto->product_id,
                    $dto->warehouse_id,
                    $dto->quantity,
                    $available,
                );
            }

            $reservedAfter = $reservedBefore + $dto->quantity;

            $locked->reserved_qty = $reservedAfter;
            $this->inventory->save($locked);

            $this->inventory->recordEntry([
                'inventory_item_id' => $locked->id,
                'warehouse_id' => $dto->warehouse_id,
                'product_id' => $dto->product_id,
                'company_id' => $dto->company_id,
                'movement_type' => LedgerMovementType::Reservation->value,
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

            $event = new InventoryStockReserved(
                inventoryItemId: $locked->id,
                warehouseId: $dto->warehouse_id,
                productId: $dto->product_id,
                companyId: $dto->company_id,
                quantityReserved: $dto->quantity,
                reservedBefore: $reservedBefore,
                reservedAfter: $reservedAfter,
                onHandQty: $onHandBefore,
                referenceType: $dto->reference_type,
                referenceId: $dto->reference_id,
            );

            return $locked;
        });

        // ── Guarantee publish fires only after the outermost transaction commits ─
        DB::connection()->afterCommit(function () use ($event): void {
            $this->eventBus->publish($event);
        });

        return OperationResult::success($result, 'Stock reserved successfully.');
    }
}
