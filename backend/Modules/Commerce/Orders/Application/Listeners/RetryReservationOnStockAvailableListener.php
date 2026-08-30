<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\DomainEvents\Events\InventoryStockAdjusted;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Modules\Inventory\DomainEvents\Events\InventoryStockReleased;
use Modules\Inventory\DomainEvents\Events\InventoryTransferred;
use Modules\Inventory\DomainEvents\Events\ProductNegativeStockEnabled;
use Modules\Inventory\DomainEvents\Events\WarehouseTransferCompleted;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;
use Throwable;

/**
 * Automatic re-evaluation of stock-blocked orders when inventory becomes available.
 *
 * TASK-INV-RESERVATION-LIFECYCLE-001 Part 4 established this for stock RECEIPTS.
 * TASK-ORDERS-INVENTORY-EXECUTION-LIFECYCLE-REPAIR-001 §7/§8 extends it to every
 * canonical event that raises availability, because receipt was only one of five:
 *
 *   InventoryStockReceived      — new stock arrived
 *   InventoryStockReleased      — a reservation was freed (e.g. another order cancelled)
 *   InventoryStockAdjusted      — a count/correction raised on-hand
 *   InventoryTransferred        — stock moved IN from another warehouse
 *   WarehouseTransferCompleted  — ditto, transfer-module surface
 *
 * The release case is the one that mattered most in practice: where every physical
 * unit is already reserved, `available = on_hand − reserved` is zero, and the ONLY
 * way a blocked order ever frees up is another order releasing its hold. Nothing
 * listened for that, so those orders waited forever on an event that never came.
 *
 * NO DUPLICATE EVENT ARCHITECTURE (§8). These are the existing Inventory domain
 * events, published through the existing LaravelDomainEventBus, after the existing
 * afterCommit guarantee. No new event, bus, or queue is introduced.
 *
 * TARGETED, NEVER A FULL SCAN (§8). Every event above carries company, warehouse and
 * product; the candidate query is bounded by all three plus a line-level match, so a
 * stock movement never walks orders it cannot possibly affect.
 *
 * THE LINE-LEVEL MATCH SPANS THE RECIPE (TASK-ORDERS-AVAILABILITY-LIFECYCLE-COMPLETION-001).
 * A recipe-backed finished good is blocked by RAW MATERIAL availability, not by its own
 * stock, so the product that unblocks such an order never appears on the order's lines.
 * The match therefore covers the moved product AND the finished goods whose active
 * recipe consumes it — see finishedGoodsConsuming(), which resolves that reverse edge
 * through the canonical accessor and inside one company only.
 *
 * SELECTED BY RESERVATION STATUS, NOT LIFECYCLE STATUS. The previous filter was
 * `status = awaiting_stock`, which was only ever correct while a shortage was allowed
 * to overwrite the lifecycle status. Now that Awaiting Payment and Confirmed keep
 * their state through a shortage (OrderStatus::yieldsToStockBlock), those orders carry
 * the block on `reservation_status` alone — filtering on lifecycle status would leave
 * exactly the orders this task protects with no recovery path at all.
 *
 * IDEMPOTENT ON FOUR LEVELS (§9, §21):
 *   1. The candidate filter — once reservation succeeds the order is no longer
 *      awaiting_stock/pending, so a replayed event selects nothing.
 *   2. ProcessOrderWorkflow skips reservation when already Reserved/PartialReserved.
 *   3. ReserveOrderInventoryAction skips Reserved/Transferred/Consumed/Released, and
 *      locks the order row so concurrent events serialise rather than double-reserve.
 *   4. ReconcileOrderMaterialReservationsAction reconciles to a TARGET quantity rather
 *      than adding, so a repeated run converges instead of doubling.
 *
 * That first level is also what bounds re-entrancy: reserving raw materials can itself
 * emit a release event, but an order that has just reserved is no longer a candidate,
 * so the cascade terminates by construction.
 */
final class RetryReservationOnStockAvailableListener
{
    /**
     * Lifecycle states in which an inventory shortage is still worth re-evaluating.
     *
     * Awaiting Payment and Confirmed are present precisely BECAUSE they no longer
     * absorb the shortage into their status — their block lives on reservation_status,
     * and a successful retry leaves their lifecycle state untouched
     * (OrderStatus::advancesToInProgressOnReservation returns false for both).
     *
     * Scheduled is deliberately absent: a future-dated order has not entered the
     * operational queue, its reservation runs at activation (D-1), and re-evaluating
     * it early would only be rejected by ProcessOrderWorkflow's delivery-date guard.
     *
     * @var list<OrderStatus>
     */
    private const RETRYABLE_STATUSES = [
        OrderStatus::InProgress,
        OrderStatus::AwaitingStock,
        OrderStatus::AwaitingPayment,
        OrderStatus::Confirmed,
    ];

    /**
     * Reservation states whose EXECUTION is still outstanding.
     *
     * PartialReserved is not here: ProcessOrderWorkflow treats it as already-reserved
     * and would skip reservation entirely, so including it would select orders only to
     * do nothing for them. Completing a partial needs delta-reservation, which the
     * current engine does not implement — recorded as a contract gap rather than
     * invented here (§18).
     *
     * @var list<string>
     */
    private const OUTSTANDING_RESERVATION_STATES = [
        ReservationStatus::AwaitingStock->value,
        ReservationStatus::Pending->value,
    ];

    public function __construct(
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly ProcessOrderWorkflow $processWorkflow,
    ) {}

    public function handleStockReceived(InventoryStockReceived $event): void
    {
        $this->reevaluate($event->companyId, $event->warehouseId, $event->productId, 'stock_received');
    }

    /**
     * A freed reservation raises `available` without changing `on_hand`. §22: integrate
     * with the existing release event; do not redesign release itself.
     */
    public function handleStockReleased(InventoryStockReleased $event): void
    {
        $this->reevaluate($event->companyId, $event->warehouseId, $event->productId, 'reservation_released');
    }

    /**
     * Only adjustments that actually RAISE on-hand can unblock an order. Decided from
     * the before/after figures the event already carries rather than by interpreting
     * `adjustmentType` strings, so a new adjustment type cannot silently misroute here.
     */
    public function handleStockAdjusted(InventoryStockAdjusted $event): void
    {
        if ($event->onHandAfter <= $event->onHandBefore) {
            return;
        }

        $this->reevaluate($event->companyId, $event->warehouseId, $event->productId, 'stock_adjusted');
    }

    /** Only the DESTINATION warehouse gains availability. */
    public function handleInventoryTransferred(InventoryTransferred $event): void
    {
        $this->reevaluate($event->companyId, $event->destinationWarehouseId, $event->productId, 'inventory_transferred');
    }

    /** Only the DESTINATION warehouse gains availability. */
    public function handleWarehouseTransferCompleted(WarehouseTransferCompleted $event): void
    {
        $this->reevaluate($event->companyId, $event->destinationWarehouseId, $event->productId, 'warehouse_transfer_completed');
    }

    /**
     * Allow-Negative-Stock turned ON (false → true) for a product.
     *
     * TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001 §6B. This is a company-wide
     * POLICY change, not a warehouse-scoped stock movement, so it re-evaluates the
     * company's affected orders across ALL warehouses — each order still reserves
     * against its OWN assigned warehouse inside ProcessOrderWorkflow, and the company
     * predicate keeps tenant isolation intact. The moved product may be a raw material
     * (unblocks the finished goods whose recipe consumes it) or a finished good (its
     * own allow_negative commitment) — finishedGoodsConsuming() covers the RM case.
     */
    public function handleNegativeStockEnabled(ProductNegativeStockEnabled $event): void
    {
        $candidates = $this->candidateOrders($event->companyId, $event->productId, warehouseId: null);
        $this->runCandidates($candidates, 'allow_negative_enabled', $event->companyId, null, $event->productId);
    }

    /**
     * Re-run reservation for the orders this movement could plausibly unblock.
     *
     * Tenant isolation (§20) is structural: `company_id` is a predicate, and the
     * warehouse it is paired with is the order's OWN assigned warehouse (§19) — never
     * a warehouse chosen here. Company A's order can therefore never be satisfied by
     * Company B's inventory, whatever product the two happen to share.
     */
    private function reevaluate(string $companyId, string $warehouseId, string $productId, string $trigger): void
    {
        $candidates = $this->candidateOrders($companyId, $productId, warehouseId: $warehouseId);
        $this->runCandidates($candidates, $trigger, $companyId, $warehouseId, $productId);
    }

    /**
     * The orders a change to `$productId` could unblock, FIFO-ordered.
     *
     * `$warehouseId = null` widens to every warehouse in the company (used by the
     * company-wide policy trigger); a non-null value keeps the original warehouse
     * scope for stock movements. Company is always a predicate, so tenant isolation
     * holds either way. The line match spans the recipe (finishedGoodsConsuming).
     *
     * @return Collection<int, Order>
     */
    private function candidateOrders(string $companyId, string $productId, ?string $warehouseId): Collection
    {
        $lineProductIds = array_values(array_unique(array_merge(
            [$productId],
            $this->finishedGoodsConsuming($productId, $companyId),
        )));

        return Order::query()
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->when($warehouseId !== null, static fn ($q) => $q->where('assigned_warehouse_id', $warehouseId))
            ->whereIn('reservation_status', self::OUTSTANDING_RESERVATION_STATES)
            ->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, self::RETRYABLE_STATUSES))
            ->whereHas('lines', static fn ($q) => $q->whereIn('product_id', $lineProductIds))
            // FIFO: the order that has waited longest is served first, so a partial
            // restock cannot let a newer order jump the queue.
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Re-run reservation for each candidate through the existing workflow. One order's
     * failure never aborts the loop or propagates into the trigger. Idempotent by the
     * candidate filter (a reserved order is no longer a candidate on replay).
     *
     * @param  Collection<int, Order>  $candidates
     */
    private function runCandidates(Collection $candidates, string $trigger, string $companyId, ?string $warehouseId, string $productId): void
    {
        if ($candidates->isEmpty()) {
            return;
        }

        foreach ($candidates as $order) {
            try {
                $result = $this->fulfillmentEngine->run(
                    $this->processWorkflow,
                    $order,
                    [],
                    null, // system actor
                );

                Log::info("[RetryReservation] Order #{$order->order_number} re-evaluated after {$trigger}.", [
                    'order_id' => $order->id,
                    'trigger' => $trigger,
                    'company_id' => $companyId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'status' => $result->order->status->value,
                    'reservation_status' => $result->order->reservation_status?->value,
                ]);
            } catch (WorkflowPreconditionException $e) {
                // The order moved on between selection and execution. Expected under
                // concurrency, and not an error.
                Log::info("[RetryReservation] Order #{$order->order_number} skipped — {$e->getMessage()}", [
                    'order_id' => $order->id,
                    'trigger' => $trigger,
                ]);
            } catch (Throwable $e) {
                // Never let one order abort the loop, and never let a re-evaluation
                // failure propagate into the inventory movement that triggered it.
                report($e);

                Log::error("[RetryReservation] Order #{$order->order_number} failed to re-evaluate after {$trigger}.", [
                    'order_id' => $order->id,
                    'trigger' => $trigger,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Finished goods whose ACTIVE recipe consumes `$materialId`, inside `$companyId`.
     *
     * WHY THIS IS NEEDED. For a recipe-backed finished good the thing that withholds
     * the commitment is the ADR-027 §16 gate, and that gate closes on RAW MATERIAL
     * availability, not on the finished good's own stock: ReserveOrderInventoryAction
     * consults ManufacturingAvailabilityService, and an `outofstock` verdict falls
     * through to the shortage path, so the order becomes Awaiting Stock (clause B,
     * correctly). But the stock that later unblocks it is the MATERIAL, which never
     * appears on the order's own lines. Matching candidates on line products alone
     * therefore selected nothing for exactly the orders whose blocker had just been
     * cleared, and they waited for ever on an event that, for them, never came.
     *
     * NO SECOND RECIPE AUTHORITY. The join below is only a PREFILTER; membership is
     * then confirmed through `Product::activeRecipe()`, the canonical accessor. That
     * matters because `bills_of_materials` has no unique constraint on
     * (product_id, is_active) — `ActiveRecipeResolver` documents this — so several
     * versions can carry `is_active = true` while only the highest
     * `bom_version_number` is the real recipe. A product whose OLD version references
     * the material but whose CURRENT one does not must not be selected, and the
     * prefilter alone cannot tell the difference.
     *
     * TENANT ISOLATION (clause J). Ownership is resolved as `Product -> Brand ->
     * Company`, the same rule §16.4 uses to decide which inventory the gate may see,
     * and a product with no derivable company FAILS CLOSED. Using the same rule as the
     * gate is deliberate: a recipe resolved here that the gate would then evaluate
     * against a different company's stock could only produce a wrong answer.
     *
     * @return list<string>
     */
    private function finishedGoodsConsuming(string $materialId, string $companyId): array
    {
        // Cheap, index-friendly prefilter. Soft-deleted recipes are excluded here for
        // the same reason the canonical accessor excludes them.
        $candidateProductIds = DB::table('bill_of_material_lines as l')
            ->join('bills_of_materials as b', 'b.id', '=', 'l.bom_id')
            ->where('l.raw_material_id', $materialId)
            ->where('b.is_active', true)
            ->whereNull('b.deleted_at')
            ->distinct()
            ->pluck('b.product_id')
            ->filter()
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($candidateProductIds === []) {
            return [];
        }

        $confirmed = [];

        $products = Product::query()
            ->whereIn('id', $candidateProductIds)
            ->with(['activeRecipe.components', 'brand'])
            ->get();

        foreach ($products as $product) {
            if ((string) ($product->brand?->company_id ?? '') !== $companyId) {
                continue;
            }

            $recipe = $product->activeRecipe;

            if ($recipe === null) {
                continue;
            }

            $consumesIt = $recipe->components->contains(
                static fn ($component): bool => (string) $component->raw_material_id === $materialId,
            );

            if ($consumesIt) {
                $confirmed[] = (string) $product->id;
            }
        }

        return $confirmed;
    }
}
