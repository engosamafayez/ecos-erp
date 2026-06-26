<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\DomainEvents\Contracts\DomainEventBus;
use Modules\Inventory\DomainEvents\Events\InventoryStockAdjusted;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InvalidInventoryMovementException;

/**
 * Positive inventory adjustment — used when physical count exceeds system quantity.
 *
 * Increases on_hand_qty. Records AdjustmentIn movement type.
 *
 * Publishes InventoryStockAdjusted (type=in) AFTER the transaction commits.
 *
 * IMPORTANT — nested transaction caveat (Phase A):
 * When this action is called by ApproveCountSessionAction, it runs inside
 * a savepoint (nested transaction). The event is published after the savepoint
 * releases, while the outer transaction is still open. Phase B will introduce
 * a PendingDomainEvents collector to guarantee post-outermost-commit semantics.
 * For Phase A (Shadow Mode, log only), this is acceptable.
 */
final class AdjustmentInAction extends BaseAction
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventory,
        private readonly DomainEventBus $eventBus,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof StockOperationDTO) {
            throw new InvalidArgumentException('AdjustmentInAction::execute expects a StockOperationDTO.');
        }

        if ($dto->quantity <= 0) {
            throw new InvalidInventoryMovementException('Quantity must be greater than zero');
        }

        $onHandBefore = null;

        $result = DB::transaction(function () use ($dto, &$onHandBefore) {
            $item = $this->inventory->findOrCreate(
                $dto->warehouse_id,
                $dto->product_id,
                $dto->company_id,
            );

            $locked = $this->inventory->lockForUpdate($item->id);

            if ($locked === null) {
                throw new InvalidInventoryMovementException('InventoryItem disappeared during transaction');
            }

            $onHandBefore  = (float) $locked->on_hand_qty;
            $reservedBefore = (float) $locked->reserved_qty;
            $onHandAfter   = $onHandBefore + $dto->quantity;

            $locked->on_hand_qty = $onHandAfter;
            $this->inventory->save($locked);

            $this->inventory->recordEntry([
                'inventory_item_id' => $locked->id,
                'warehouse_id'      => $dto->warehouse_id,
                'product_id'        => $dto->product_id,
                'company_id'        => $dto->company_id,
                'movement_type'     => LedgerMovementType::AdjustmentIn->value,
                'quantity'          => $dto->quantity,
                'on_hand_before'    => $onHandBefore,
                'on_hand_after'     => $onHandAfter,
                'reserved_before'   => $reservedBefore,
                'reserved_after'    => $reservedBefore,
                'reference_type'    => $dto->reference_type,
                'reference_id'      => $dto->reference_id,
                'notes'             => $dto->notes,
            ]);

            $locked->refresh();

            return $locked;
        });

        // ── Publish after commit (or savepoint release in nested context) ────
        $this->eventBus->publish(new InventoryStockAdjusted(
            inventoryItemId: $result->id,
            warehouseId:     $dto->warehouse_id,
            productId:       $dto->product_id,
            companyId:       $dto->company_id,
            adjustmentType:  InventoryStockAdjusted::TYPE_IN,
            quantity:        $dto->quantity,
            onHandBefore:    $onHandBefore ?? 0.0,
            onHandAfter:     (float) $result->on_hand_qty,
            referenceType:   $dto->reference_type,
            referenceId:     $dto->reference_id,
        ));

        return OperationResult::success($result, 'Adjustment in applied successfully.');
    }
}
