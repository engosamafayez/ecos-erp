<?php

declare(strict_types=1);

namespace Modules\Inventory\CountSessions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\CostManagement\Domain\Enums\PricingTriggerReason;
use Modules\CostManagement\Domain\Events\FinishedProductCostChanged;
use Modules\Inventory\CountSessions\Domain\Enums\CountSessionStatus;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountLine;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountSession;
use Modules\Inventory\DomainEvents\Contracts\DomainEventBus;
use Modules\Inventory\DomainEvents\Events\InventoryCountApproved;
use Modules\Inventory\InventoryItems\Application\Actions\AdjustmentInAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Inventory\WarehouseLiabilities\Domain\Models\WarehouseLiability;
use Modules\Inventory\WasteInvestigations\Domain\Models\WasteInvestigation;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Posts inventory adjustments for a completed count session.
 *
 * Positive overstock  → AdjustmentIn immediately (counted_qty > system_qty)
 * Shortage (shortage_qty > 0) → WarehouseLiability (pending). Deducted on approval.
 * Damaged (damaged_qty > 0)   → WasteInvestigation (pending). Deducted on resolution.
 *
 * No automatic AdjustmentOut for shortage or damage — those go through their
 * respective accountability workflows before inventory is touched.
 */
final class ApproveCountSessionAction
{
    private const SCALE_QTY   = 4;
    private const SCALE_VALUE = 2;

    public function __construct(
        private readonly AdjustmentInAction $adjustmentIn,
        private readonly InventoryItemRepositoryInterface $inventory,
        private readonly DomainEventBus $eventBus,
    ) {}

    public function execute(InventoryCountSession $session, ?string $approvedBy = null): InventoryCountSession
    {
        if (! $session->status->canTransitionTo(CountSessionStatus::Approved)) {
            throw new UnprocessableEntityHttpException(
                "Count session [{$session->count_number}] cannot be approved from status [{$session->status->value}]."
            );
        }

        $session->loadMissing(['lines.product', 'warehouse']);

        $companyId   = $session->warehouse->company_id;
        $warehouseId = $session->warehouse_id;
        $month       = now()->format('Y-m');

        // ── Zero-cost guard for positive overstock adjustments ────────────────
        foreach ($session->lines as $line) {
            /** @var InventoryCountLine $line */
            if ($line->counted_qty === null) {
                continue;
            }
            $countedStr = (string) $line->counted_qty;
            $systemStr  = (string) $line->system_qty;
            if (bccomp($countedStr, $systemStr, self::SCALE_QTY) <= 0) {
                continue; // only check cost for adjustment-in lines
            }
            $product = $line->product;
            $hasCost = $product?->average_cost !== null
                || $product?->last_purchase_cost !== null
                || $product?->current_fifo_cost !== null;

            if (! $hasCost) {
                throw new UnprocessableEntityHttpException(
                    "Cannot approve: {$product?->name} ({$product?->sku}) has no valid inventory cost."
                );
            }
        }

        $linesAdjusted         = 0;
        $liabilitiesCreated    = 0;
        $investigationsCreated = 0;
        $fifoCostChanges       = [];

        $result = DB::transaction(function () use (
            $session, $approvedBy, $companyId, $warehouseId, $month,
            &$linesAdjusted, &$liabilitiesCreated, &$investigationsCreated, &$fifoCostChanges
        ): InventoryCountSession {

            foreach ($session->lines as $line) {
                /** @var InventoryCountLine $line */
                if ($line->counted_qty === null) {
                    continue;
                }

                $countedStr  = (string) $line->counted_qty;
                $systemStr   = (string) $line->system_qty;
                $damagedStr  = (string) ($line->damaged_qty ?? '0');
                $shortageStr = (string) ($line->shortage_qty ?? '0');

                $product   = $line->product;
                $unitCost  = (float) (
                    $product?->average_cost
                    ?? $product?->last_purchase_cost
                    ?? $product?->current_fifo_cost
                    ?? 0
                );

                // Freeze unit cost on the line for historical report accuracy
                $line->update(['unit_cost_snapshot' => $unitCost]);

                // ── Case A: Overstock (counted > system) → AdjustmentIn immediately
                if (bccomp($countedStr, $systemStr, self::SCALE_QTY) > 0) {
                    $overQtyStr = bcsub($countedStr, $systemStr, self::SCALE_QTY);

                    $dto = new StockOperationDTO(
                        warehouse_id:   $warehouseId,
                        product_id:     $line->product_id,
                        company_id:     $companyId,
                        quantity:       (float) $overQtyStr,
                        reference_type: 'inventory_count',
                        reference_id:   $session->id,
                        notes:          "Overstock adjustment from count {$session->count_number}",
                    );
                    $this->adjustmentIn->execute($dto);

                    InventoryReceiptLayer::query()->create([
                        'supplier_id'           => $product?->last_supplier_id,
                        'product_id'            => $line->product_id,
                        'goods_receipt_id'      => null,
                        'goods_receipt_line_id' => null,
                        'warehouse_id'          => $warehouseId,
                        'received_qty'          => $overQtyStr,
                        'remaining_qty'         => $overQtyStr,
                        'landed_unit_cost'      => (string) $unitCost,
                        'sale_price_snapshot'   => $product?->sale_price,
                        'receipt_date'          => now()->toDateString(),
                    ]);

                    [$oldFifo, $newFifo] = $this->refreshFifoCost($line->product_id);
                    if ($oldFifo !== $newFifo) {
                        $fifoCostChanges[] = [
                            'product_id' => $line->product_id,
                            'company_id' => $companyId,
                            'old_cost'   => $oldFifo,
                            'new_cost'   => $newFifo,
                        ];
                    }
                    $linesAdjusted++;
                }

                // ── Case B: Shortage > 0 → WarehouseLiability (pending)
                if (bccomp($shortageStr, '0', self::SCALE_QTY) > 0) {
                    $shortageQty   = (float) $shortageStr;
                    $shortageTotal = round($shortageQty * $unitCost, 2);

                    WarehouseLiability::query()->create([
                        'company_id'        => $companyId,
                        'warehouse_id'      => $warehouseId,
                        'product_id'        => $line->product_id,
                        'count_session_id'  => $session->id,
                        'count_line_id'     => $line->id,
                        'liability_type'    => 'inventory_shortage',
                        'quantity'          => $shortageQty,
                        'unit_cost'         => $unitCost,
                        'total_cost'        => $shortageTotal,
                        'status'            => 'pending',
                        'month'             => $month,
                    ]);

                    $liabilitiesCreated++;
                }

                // ── Case C: Damaged > 0 → WasteInvestigation (pending)
                if (bccomp($damagedStr, '0', self::SCALE_QTY) > 0) {
                    $damagedQty   = (float) $damagedStr;
                    $damagedTotal = round($damagedQty * $unitCost, 2);

                    WasteInvestigation::query()->create([
                        'company_id'       => $companyId,
                        'warehouse_id'     => $warehouseId,
                        'count_session_id' => $session->id,
                        'count_line_id'    => $line->id,
                        'product_id'       => $line->product_id,
                        'quantity'         => $damagedQty,
                        'unit_cost'        => $unitCost,
                        'total_cost'       => $damagedTotal,
                        'damage_reason'    => $line->damage_reason,
                        'status'           => 'pending_investigation',
                        'month'            => $month,
                    ]);

                    $investigationsCreated++;
                }
            }

            $session->update([
                'status'      => CountSessionStatus::Approved,
                'approved_by' => $approvedBy,
            ]);

            return $session->refresh();
        });

        $this->eventBus->publish(new InventoryCountApproved(
            countSessionId: $result->id,
            countNumber:    $result->count_number,
            warehouseId:    $result->warehouse_id,
            companyId:      $companyId,
            linesAdjusted:  $linesAdjusted,
            approvedBy:     $approvedBy,
        ));

        foreach ($fifoCostChanges as $change) {
            $old  = (float) $change['old_cost'];
            $new  = (float) $change['new_cost'];
            $diff = round($new - $old, 4);
            $pct  = $old > 0 ? round(($diff / $old) * 100, 4) : 0.0;

            FinishedProductCostChanged::dispatch(
                productId:         $change['product_id'],
                companyId:         $change['company_id'],
                oldCost:           $old,
                newCost:           $new,
                difference:        $diff,
                differencePercent: $pct,
                triggerReason:     PricingTriggerReason::Other,
                triggerSource:     'inventory_count',
                occurredAt:        now()->toIso8601String(),
            );
        }

        return $result;
    }

    /**
     * @return array{float, float} [oldFifoCost, newFifoCost]
     */
    private function refreshFifoCost(string $productId): array
    {
        $oldCost = (float) (Product::query()->where('id', $productId)->value('current_fifo_cost') ?? 0);

        $oldestLayer = InventoryReceiptLayer::query()
            ->where('product_id', $productId)
            ->where('remaining_qty', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $newCost = (float) ($oldestLayer?->landed_unit_cost ?? 0);

        Product::query()
            ->where('id', $productId)
            ->update(['current_fifo_cost' => $oldestLayer?->landed_unit_cost]);

        return [$oldCost, $newCost];
    }
}
