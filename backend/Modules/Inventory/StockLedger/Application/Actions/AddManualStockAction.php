<?php

declare(strict_types=1);

namespace Modules\Inventory\StockLedger\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\CostManagement\Domain\Enums\CostUpdateSource;
use Modules\CostManagement\Domain\Services\MaterialCostService;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\StockLedger\Domain\Enums\MovementType;
use Modules\Inventory\StockLedger\Domain\Models\StockMovement;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;

/**
 * Records a manual stock adjustment (adjustment_in) for a raw material.
 *
 * Creates an immutable StockMovement ledger entry and, when a unit cost is
 * provided, delegates to MaterialCostService to update the material cost and
 * trigger the full downstream cascade (recipe_cost → product_cost → pricing review).
 */
final class AddManualStockAction extends BaseAction
{
    public function __construct(
        private readonly MaterialCostService $materialCostService,
    ) {}

    /**
     * @param  mixed ...$arguments [Product, Warehouse, float $quantity, array $meta]
     * @param  array{
     *   unit_cost?: float|null,
     *   notes?: string|null,
     *   updated_by?: string|null,
     * } $meta
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var Product $product */
        $product = $arguments[0];
        /** @var Warehouse $warehouse */
        $warehouse = $arguments[1];
        $quantity  = (float) ($arguments[2] ?? 0);
        $meta      = (array) ($arguments[3] ?? []);

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        $movement = DB::transaction(function () use ($product, $warehouse, $quantity, $meta): StockMovement {
            // Derive running balance from the last ledger entry for this product+warehouse
            $last = StockMovement::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            $balanceBefore = $last !== null ? (float) $last->balance_after : 0.0;
            $balanceAfter  = $balanceBefore + $quantity;

            $movement = StockMovement::query()->create([
                'product_id'     => $product->id,
                'warehouse_id'   => $warehouse->id,
                'movement_type'  => MovementType::AdjustmentIn,
                'quantity'       => round($quantity, 4),
                'balance_before' => round($balanceBefore, 4),
                'balance_after'  => round($balanceAfter, 4),
                'reference_type' => null,
                'reference_id'   => null,
                'movement_date'  => now()->toDateString(),
                'notes'          => $meta['notes'] ?? null,
            ]);

            // When a unit cost is supplied, update the material's active cost and
            // trigger the cascade (recipe_cost → product_cost → pricing review).
            $unitCost = isset($meta['unit_cost']) && is_numeric($meta['unit_cost'])
                ? (float) $meta['unit_cost']
                : null;

            if ($unitCost !== null && $unitCost >= 0) {
                $this->materialCostService->update(
                    $product,
                    $unitCost,
                    CostUpdateSource::Manual,
                    ['updated_by' => $meta['updated_by'] ?? null],
                );
            }

            return $movement;
        });

        return OperationResult::success($movement->load(['warehouse', 'product']));
    }
}
