<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Actions;

use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\StockLedger\Application\Actions\AddManualStockAction;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use RuntimeException;

/**
 * TASK-...-026 §9 — LIVE Opening Inventory, established ONLY through the existing canonical
 * Inventory authority ({@see AddManualStockAction} -> {@see \Modules\Inventory\InventoryItems\Application\Actions\AdjustmentInAction}).
 * No direct `on_hand_qty` mutation anywhere in this class — it is a thin per-line loop over an
 * already-transactional, already-valuation-enforcing, already-FIFO-layering action. Handles a
 * brand-new product/warehouse pair with no existing InventoryItem row transparently: that action's
 * own repository does `findOrCreate` (seeds `on_hand_qty = 0`) before adjusting it up — nothing
 * special is needed here for that case.
 */
final class EstablishOpeningInventoryAction
{
    public function __construct(private readonly AddManualStockAction $addManualStock) {}

    /**
     * @param  list<array{warehouse_id: string, product_id: string, quantity: float, unit_cost: float, notes?: string|null}>  $lines
     * @return list<array{warehouse_id: string, product_id: string, quantity: float, status: string}>
     */
    public function execute(string $companyId, array $lines, ?string $actorId = null): array
    {
        $results = [];

        foreach ($lines as $line) {
            $warehouse = Warehouse::query()->findOrFail($line['warehouse_id']);
            $product = Product::query()->findOrFail($line['product_id']);

            if ((string) $warehouse->company_id !== $companyId) {
                throw new RuntimeException("Warehouse [{$warehouse->id}] does not belong to company [{$companyId}].");
            }

            $quantity = (float) $line['quantity'];
            if ($quantity <= 0) {
                throw new RuntimeException('Opening quantity must be greater than zero for warehouse/product '.$warehouse->id.'/'.$product->id.'.');
            }

            $this->addManualStock->execute($product, $warehouse, $quantity, [
                'unit_cost' => (float) $line['unit_cost'],
                'reference_type' => 'opening_stock',
                'notes' => $line['notes'] ?? 'Go-Live opening inventory',
                'updated_by' => $actorId,
            ]);

            $results[] = [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'status' => 'applied',
            ];
        }

        return $results;
    }
}
