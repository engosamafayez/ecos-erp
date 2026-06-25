<?php

declare(strict_types=1);

namespace Modules\Inventory\CountSessions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\CountSessions\Domain\Enums\CountSessionStatus;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountLine;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountSession;
use Modules\Inventory\InventoryItems\Application\Actions\AdjustmentInAction;
use Modules\Inventory\InventoryItems\Application\Actions\AdjustmentOutAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\ReceiptLayers\Application\Services\InventoryLayerConsumptionService;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Inventory\Products\Domain\Models\Product;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Posts inventory adjustments for a completed count session.
 *
 * Positive variance: ReceiveStockAction (AdjustmentIn movement type)
 * Negative variance: DirectIssueStockAction + FIFO layer consumption
 *
 * Requires approval; must be in 'completed' status.
 */
final class ApproveCountSessionAction
{
    public function __construct(
        private readonly AdjustmentInAction $adjustmentIn,
        private readonly AdjustmentOutAction $adjustmentOut,
        private readonly InventoryLayerConsumptionService $layerConsumption,
        private readonly InventoryItemRepositoryInterface $inventory,
    ) {}

    public function execute(InventoryCountSession $session, ?string $approvedBy = null): InventoryCountSession
    {
        if (! $session->status->canTransitionTo(CountSessionStatus::Approved)) {
            throw new UnprocessableEntityHttpException(
                "Count session [{$session->count_number}] cannot be approved from status [{$session->status->value}]."
            );
        }

        return DB::transaction(function () use ($session, $approvedBy): InventoryCountSession {
            $session->loadMissing('lines.product', 'warehouse');

            $companyId   = $session->warehouse->company_id;
            $warehouseId = $session->warehouse_id;

            foreach ($session->lines as $line) {
                /** @var InventoryCountLine $line */
                if ($line->counted_qty === null || (float) $line->variance_qty === 0.0) {
                    continue;
                }

                $varianceQty = (float) $line->variance_qty;
                $dto = new StockOperationDTO(
                    warehouse_id:   $warehouseId,
                    product_id:     $line->product_id,
                    company_id:     $companyId,
                    quantity:       abs($varianceQty),
                    reference_type: 'inventory_count',
                    reference_id:   $session->id,
                    notes:          "Adjustment from count {$session->count_number}",
                );

                if ($varianceQty > 0) {
                    // Positive: more stock than system shows → adjustment in
                    $this->adjustmentIn->execute($dto);

                    // Create a new receipt layer for the adjustment in (no real GR or supplier)
                    $unitCost = (float) ($line->product?->average_cost ?? $line->product?->last_purchase_cost ?? 0);
                    InventoryReceiptLayer::query()->create([
                        'supplier_id'           => $line->product?->last_supplier_id,
                        'product_id'            => $line->product_id,
                        'goods_receipt_id'      => null,
                        'goods_receipt_line_id' => null,
                        'warehouse_id'          => $warehouseId,
                        'received_qty'          => $varianceQty,
                        'remaining_qty'         => $varianceQty,
                        'landed_unit_cost'      => $unitCost,
                        'sale_price_snapshot'   => $line->product?->sale_price,
                        'receipt_date'          => now()->toDateString(),
                    ]);
                } else {
                    // Negative: less stock than system shows → adjustment out
                    $absQty = abs($varianceQty);

                    $this->adjustmentOut->execute($dto);

                    // FIFO layer consumption for the adjustment out
                    $inventoryItem = $this->inventory->findByWarehouseAndProduct($warehouseId, $line->product_id);
                    if ($inventoryItem !== null) {
                        $this->layerConsumption->consume(
                            inventoryItemId: $inventoryItem->id,
                            productId:       $line->product_id,
                            warehouseId:     $warehouseId,
                            companyId:       $companyId,
                            quantity:        $absQty,
                            orderId:         null,
                            orderLineId:     null,
                        );
                    }
                }

                // Update current FIFO cost after each adjustment
                $this->refreshFifoCost($line->product_id);
            }

            $session->update([
                'status'      => CountSessionStatus::Approved,
                'approved_by' => $approvedBy,
            ]);

            return $session->refresh();
        });
    }

    private function refreshFifoCost(string $productId): void
    {
        $oldestLayer = InventoryReceiptLayer::query()
            ->where('product_id', $productId)
            ->where('remaining_qty', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        Product::query()
            ->where('id', $productId)
            ->update(['current_fifo_cost' => $oldestLayer?->landed_unit_cost]);
    }
}
