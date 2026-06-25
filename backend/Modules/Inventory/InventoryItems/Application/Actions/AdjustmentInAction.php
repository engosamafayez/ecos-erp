<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InvalidInventoryMovementException;

/**
 * Positive inventory adjustment — used when physical count exceeds system quantity.
 *
 * Increases on_hand_qty. Records AdjustmentIn movement type.
 */
final class AdjustmentInAction extends BaseAction
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventory,
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

        $result = DB::transaction(function () use ($dto) {
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

        return OperationResult::success($result, 'Adjustment in applied successfully.');
    }
}
