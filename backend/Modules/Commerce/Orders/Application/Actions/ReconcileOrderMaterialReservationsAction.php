<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\InventoryItems\Application\Actions\ReleaseStockAction;
use Modules\Inventory\InventoryItems\Application\Actions\ReserveStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;

/**
 * Order-driven Raw Material reservation — ADR-027 §17 (v1.3).
 *
 * Derives each order's raw-material requirement from the FG's ACTIVE recipe and
 * reconciles `inventory_items.reserved_qty` to match it.
 *
 * WHY THIS EXISTS
 * ---------------
 * ADR-027 §3/§11 previously held that raw materials were invisible to the
 * reservation system, on the premise that "multiple BOMs may apply; Orders cannot
 * know". `Product::activeRecipe()` resolves exactly one recipe deterministically,
 * so that premise expired — and while it stood, a raw material carrying a real
 * commitment from confirmed orders reported `Reserved = 0` and a non-negative
 * `Available`. The commitment existed commercially and was invisible operationally.
 *
 * RECONCILIATION, NOT ACCUMULATION (§17.5)
 * ----------------------------------------
 * This action is IDEMPOTENT by construction. It does not "add a reservation"; it
 * computes the TARGET quantity this order should hold for each material, compares
 * it with what this order ALREADY holds, and applies only the delta:
 *
 *   target > held  → reserve   (target − held)
 *   target < held  → release   (held − target)
 *   target = held  → no-op
 *
 * So re-processing an order converges instead of doubling, a quantity increase
 * reserves only the difference, a decrease releases only the difference, and
 * cancellation (target 0) releases exactly once. A reservation that accumulated on
 * retry would silently manufacture demand no customer placed.
 *
 * The "already held" figure is derived from the canonical stock ledger rather than
 * a new tracking table: reservation minus release entries carrying this order's
 * reference. The ledger is already the system of record for both movements, so
 * there is no second source of truth to drift.
 *
 * WHAT THIS DOES NOT DO
 * ---------------------
 * It does not touch finished-goods reservation (ADR-027 §3 Cases 1–4 and §16.2 are
 * unchanged), does not inspect production schedules, and does not decide order
 * status. Allow Negative is enforced where it belongs — inside ReserveStockAction.
 */
final class ReconcileOrderMaterialReservationsAction extends BaseAction
{
    /** Distinguishes order-driven material commitments from FG reservations in the ledger. */
    public const REFERENCE_TYPE = 'sales_order_material';

    public function __construct(
        private readonly ReserveStockAction $reserveStock,
        private readonly ReleaseStockAction $releaseStock,
    ) {}

    /**
     * @return OperationResult data: array{
     *                         reconciled: list<array{product_id: string, target: float, held: float, delta: float, outcome: string}>,
     *                         blocked: list<array{product_id: string, required: float}>
     *                         }
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $order = $arguments[0] ?? null;

        if (! $order instanceof Order) {
            throw new InvalidArgumentException('ReconcileOrderMaterialReservationsAction::execute expects an Order.');
        }

        $warehouseId = $order->assigned_warehouse_id;

        // No warehouse → nothing to reserve against. ADR-027 §2/§10: this is a
        // postponement, never a status change, and never an error.
        if ($warehouseId === null) {
            return OperationResult::success(
                ['reconciled' => [], 'blocked' => []],
                'No warehouse assigned — material reservation postponed.',
            );
        }

        $companyId = (string) $order->company_id;
        $targets = $this->deriveMaterialTargets($order);
        $reconciled = [];
        $blocked = [];

        foreach ($targets as $productId => $target) {
            $held = $this->heldByThisOrder($order->id, $productId);
            $delta = round($target - $held, 4);

            if (abs($delta) < 0.0001) {
                $reconciled[] = ['product_id' => $productId, 'target' => $target, 'held' => $held, 'delta' => 0.0, 'outcome' => 'unchanged'];

                continue;
            }

            if ($delta > 0) {
                try {
                    $this->reserveStock->execute(new StockOperationDTO(
                        warehouse_id: $warehouseId,
                        product_id: $productId,
                        company_id: $companyId,
                        quantity: $delta,
                        reference_type: self::REFERENCE_TYPE,
                        reference_id: $order->id,
                        notes: "Material requirement for order #{$order->order_number}",
                    ));
                } catch (InsufficientStockException) {
                    // allow_negative_stock = OFF and physical stock cannot cover it.
                    // Reported, never partially applied — §17.4 / PART 5.
                    $blocked[] = ['product_id' => $productId, 'required' => $target];

                    continue;
                }

                $reconciled[] = ['product_id' => $productId, 'target' => $target, 'held' => $held, 'delta' => $delta, 'outcome' => 'reserved'];

                continue;
            }

            $this->releaseStock->execute(new StockOperationDTO(
                warehouse_id: $warehouseId,
                product_id: $productId,
                company_id: $companyId,
                quantity: abs($delta),
                reference_type: self::REFERENCE_TYPE,
                reference_id: $order->id,
                notes: "Material requirement reduced for order #{$order->order_number}",
            ));

            $reconciled[] = ['product_id' => $productId, 'target' => $target, 'held' => $held, 'delta' => $delta, 'outcome' => 'released'];
        }

        // Materials this order once required but no longer does (line removed, or
        // the recipe changed) must be released, or the commitment would be stranded.
        foreach ($this->materialsPreviouslyHeld($order->id) as $productId) {
            if (isset($targets[$productId])) {
                continue;
            }

            $held = $this->heldByThisOrder($order->id, $productId);

            if ($held <= 0.0) {
                continue;
            }

            $this->releaseStock->execute(new StockOperationDTO(
                warehouse_id: $warehouseId,
                product_id: $productId,
                company_id: $companyId,
                quantity: $held,
                reference_type: self::REFERENCE_TYPE,
                reference_id: $order->id,
                notes: "Material no longer required by order #{$order->order_number}",
            ));

            $reconciled[] = ['product_id' => $productId, 'target' => 0.0, 'held' => $held, 'delta' => -$held, 'outcome' => 'released'];
        }

        return OperationResult::success(
            ['reconciled' => $reconciled, 'blocked' => $blocked],
            sprintf('Reconciled %d material commitment(s); %d blocked.', count($reconciled), count($blocked)),
        );
    }

    /**
     * Raw-material quantity this order requires, keyed by product id.
     *
     * `yield_quantity` is honoured: a recipe producing 10 units from 5 KG requires
     * 0.5 KG per finished unit, not 5.
     *
     * @return array<string, float>
     */
    private function deriveMaterialTargets(Order $order): array
    {
        $targets = [];

        $order->loadMissing('lines.product.activeRecipe.components');

        foreach ($order->lines as $line) {
            $quantity = (float) $line->quantity;

            if ($quantity <= 0) {
                continue;
            }

            $recipe = $line->product?->activeRecipe;

            if ($recipe === null) {
                continue;
            }

            $yield = (float) ($recipe->yield_quantity ?? 1.0);
            $yield = $yield > 0.0 ? $yield : 1.0;

            foreach ($recipe->components as $component) {
                $per = (float) $component->quantity / $yield;
                $materialId = (string) $component->raw_material_id;

                $targets[$materialId] = round(($targets[$materialId] ?? 0.0) + ($per * $quantity), 4);
            }
        }

        return $targets;
    }

    /**
     * How much of $productId this order currently holds, from the canonical ledger.
     *
     * Reservation entries minus release entries carrying this order's reference —
     * the same records ReserveStockAction and ReleaseStockAction already write, so
     * this can never drift from the reservation itself.
     */
    private function heldByThisOrder(string $orderId, string $productId): float
    {
        $rows = StockLedgerEntry::query()
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', $orderId)
            ->where('product_id', $productId)
            ->whereIn('movement_type', [
                LedgerMovementType::Reservation->value,
                LedgerMovementType::ReservationRelease->value,
            ])
            ->select('movement_type', DB::raw('SUM(quantity) as total'))
            ->groupBy('movement_type')
            ->pluck('total', 'movement_type');

        $reserved = (float) ($rows[LedgerMovementType::Reservation->value] ?? 0.0);
        $released = (float) ($rows[LedgerMovementType::ReservationRelease->value] ?? 0.0);

        return round($reserved - $released, 4);
    }

    /**
     * Every material this order has ever committed to, so a requirement that
     * disappeared can still be released.
     *
     * @return list<string>
     */
    private function materialsPreviouslyHeld(string $orderId): array
    {
        return StockLedgerEntry::query()
            ->where('reference_type', self::REFERENCE_TYPE)
            ->where('reference_id', $orderId)
            ->distinct()
            ->pluck('product_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
