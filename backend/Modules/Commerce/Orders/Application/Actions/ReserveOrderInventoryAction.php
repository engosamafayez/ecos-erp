<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Exceptions\OrderWarehouseNotAssignedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\OrderReservationAudit;
use Modules\Inventory\InventoryItems\Application\Actions\ReserveStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Exceptions\InsufficientStockException;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;
use Throwable;

/**
 * TASK-INV-RESERVATION-LIFECYCLE-001 — Part 2, 3, 4
 *
 * Attempts to reserve inventory for every order line.
 * Supports partial reservation: reserves as much as is available per line.
 *
 * Returns the resulting ReservationStatus so callers can branch without catching.
 *
 * Outcomes (ADR-027 §8; the denominator is lines with quantity > 0):
 *   reserved         — every reservable line fully satisfied (physically or via
 *                      manufacturing eligibility), or there is no reservable line
 *   partial_reserved — at least one reservable line satisfied, at least one short
 *   awaiting_stock   — no reservable line could be satisfied at all
 *
 * Does NOT throw InsufficientStockException for insufficient stock — that
 * concern has moved to the returned status.
 *
 * Policy — TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001 (owner-approved,
 * ADR-027 §16 v1.5). ECOS is order-driven preparation / made-to-order, so a
 * finished product's fulfillability is: PHYSICAL FG STOCK **or** an EXECUTABLE
 * PREPARATION RECIPE. This supersedes the previous Option B rule that additionally
 * required the `can_manufacture` capability flag — that flag no longer gates order
 * fulfilment (see ADR-027 §16 v1.5 and §19).
 *
 *   Physical Finished Product stock is reserved first and is NEVER gated by the
 *   recipe (Case 1) — an order that can ship from stock always ships from stock.
 *
 *   When FG stock is insufficient, the recipe decides: the commitment is made only
 *   if the preparation recipe is actually executable (every required material
 *   available OR allow_negative). An unexecutable recipe — or no recipe at all —
 *   falls through to the shortage path, which lets the V3 workflow produce Awaiting
 *   Stock itself. Recipe-backed finished goods with zero FG stock are fulfillable.
 *
 * ManufacturingAvailabilityService remains the single authority for recipe
 * executability (ADR-027 §16.3) — the material-level allow_negative_stock rule is
 * read from it, never recomputed here.
 */
final class ReserveOrderInventoryAction
{
    /**
     * Reservation states in which execution is finished and must not be repeated.
     *
     * Checked twice: once unlocked as a cheap exit, and once inside the row lock so a
     * concurrent attempt cannot act on a status that has since changed.
     *
     * @var list<ReservationStatus>
     */
    private const SKIP_STATES = [
        ReservationStatus::Reserved,
        ReservationStatus::Transferred,
        ReservationStatus::Consumed,
        ReservationStatus::Released,
    ];

    public function __construct(
        private readonly ReserveStockAction $reserveStock,
        private readonly ManufacturingAvailabilityService $manufacturingAvailability,
        private readonly ReconcileOrderMaterialReservationsAction $reconcileMaterials,
    ) {}

    /**
     * Is this finished good's PREPARATION RECIPE executable right now?
     *
     * TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001: order fulfilment is now
     * gated on recipe executability ALONE (the `can_manufacture` capability flag was
     * removed from the fulfillability decision — ADR-027 §16 v1.5). So this must be a
     * POSITIVE test: only an actually-present, actually-executable recipe makes the
     * finished product fulfillable via preparation.
     *
     *   'instock'        → every required material is available OR allow_negative → executable
     *   'outofstock'     → a required material is blocked → NOT executable
     *   'recipe_missing' → no active recipe exists → there is no preparation path, so the
     *                      finished good can only be fulfilled from physical FG stock
     *                      (Case 1) or its own allow_negative policy (Case 3); NOT here.
     *
     * `ManufacturingAvailabilityService` remains the single authority (ADR-027 §16.3);
     * this never recomputes the material rule.
     */
    private function manufacturingIsExecutable(Product $product): bool
    {
        return $this->manufacturingAvailability->evaluate($product)['status'] === 'instock';
    }

    public function execute(Order $order): ReservationStatus
    {
        // Idempotency: already in a terminal-for-reservation state → skip
        $currentStatus = $order->reservation_status;
        if ($currentStatus !== null && in_array($currentStatus, self::SKIP_STATES, true)) {
            return $currentStatus;
        }

        if ($order->assigned_warehouse_id === null) {
            throw new OrderWarehouseNotAssignedException($order->id);
        }

        $order->loadMissing('lines.product', 'assignedWarehouse');
        $companyId = $order->assignedWarehouse->company_id;
        $warehouseId = $order->assigned_warehouse_id;
        $totalLines = $order->lines->count();

        // The entire reservation unit — per-line stock locks, order.reservation_status,
        // inventory_reserved_at, OrderReservationAudit, and OrderEvent — is committed in
        // one DB::transaction so no partial state can persist if any step fails.
        // When called from a FulfillmentEngine workflow, this becomes a savepoint inside
        // the outer FE transaction, preserving full atomicity with the order status update.
        return DB::transaction(function () use (
            $order, $companyId, $warehouseId, $totalLines,
        ): ReservationStatus {
            // Serialise concurrent reservation attempts for the SAME order. Two
            // availability events can select one order in the same instant — a receipt
            // and a release, say — and the unlocked pre-check above would wave both
            // through to reserve the same quantity twice.
            //
            // Re-reading the status INSIDE the lock is what makes that pre-check safe:
            // whichever transaction arrives second blocks here, then observes the
            // first one's committed outcome and returns it untouched.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked?->reservation_status !== null
                && in_array($locked->reservation_status, self::SKIP_STATES, true)
            ) {
                return $locked->reservation_status;
            }

            $reservedLines = 0;
            $partialLines = 0;
            // A line that carries real demand but could not be reserved at all.
            $blockedLines = 0;
            // A line with quantity <= 0 — no demand, therefore neither satisfiable
            // nor short. Counted separately so it can tilt the outcome neither way.
            $zeroQtyLines = 0;
            $failReason = null;
            $metaLines = [];

            foreach ($order->lines as $line) {
                $requested = (float) $line->quantity;
                if ($requested <= 0) {
                    $zeroQtyLines++;

                    continue;
                }

                // Determine how much FG stock is physically available (no lock — pre-check)
                $item = InventoryItem::where('warehouse_id', $warehouseId)
                    ->where('product_id', $line->product_id)
                    ->first();
                // Signed. availableQty() is already `on_hand − reserved`; the outer
                // max() clamped it a SECOND time and hid every negative balance.
                $available = $item ? $item->availableQty() : 0.0;

                // CASE 1: Sufficient FG stock — reserve physically, done
                if ($available >= $requested) {
                    try {
                        $this->reserveStock->execute(new StockOperationDTO(
                            warehouse_id: $warehouseId,
                            product_id: $line->product_id,
                            company_id: $companyId,
                            quantity: $requested,
                            reference_type: 'sales_order',
                            reference_id: $order->id,
                            notes: "Reserved for order #{$order->order_number}",
                        ));
                    } catch (InsufficientStockException) {
                        // BUG-36/M-4 fix: stock dropped between the unlocked pre-check
                        // and the lockForUpdate inside ReserveStockAction — treat as partial.
                        $blockedLines++;
                        $failReason ??= 'Insufficient Inventory';
                        $metaLines[] = ['product_id' => $line->product_id, 'requested' => $requested, 'reserved' => 0.0, 'outcome' => 'none'];

                        continue;
                    }
                    $line->update(['reserved_qty' => $requested]);
                    $reservedLines++;
                    $metaLines[] = ['product_id' => $line->product_id, 'requested' => $requested, 'reserved' => $requested, 'outcome' => 'full'];

                    continue;
                }

                // FG stock is insufficient — check manufacturing eligibility first
                $product = $line->product;

                // Order-driven preparation gate (ADR-027 §16 v1.5,
                // TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001): when physical FG
                // stock is short, the finished product is fulfillable iff its PREPARATION
                // RECIPE is executable — every required raw material available OR
                // allow_negative. The `can_manufacture` capability flag no longer gates
                // this: ECOS is made-to-order, so a zero-FG-stock product with an
                // executable recipe is fulfillable and must reserve. When the recipe is
                // not executable this branch is skipped and the shortage path below
                // decides the outcome — no status is written by hand here.
                if ($product !== null && $this->manufacturingIsExecutable($product)) {
                    // TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001 (D3) — RESERVE MUST BE
                    // SYMMETRIC WITH RELEASE.
                    //
                    // This previously locked only the physically available slice, guarded by
                    // `if ($available > 0.0)`, while still writing the FULL requested quantity
                    // to `order_lines.reserved_qty` OUTSIDE that guard. With on_hand = 0 —
                    // the normal made-to-order case — nothing was written to inventory_items
                    // at all, so the order claimed a commitment that inventory had no record
                    // of, and ReleaseStockAction later threw "No inventory record found for
                    // the given warehouse and product" on any edit of that order.
                    //
                    // This is the identical defect already fixed in the allow_negative branch
                    // below (see its comment); the fix is the same shape. The permission to
                    // commit beyond physical stock comes from the recipe-executability
                    // decision taken immediately above, and is stated explicitly rather than
                    // borrowed from the product's own allow_negative_stock flag.
                    //
                    // The resulting negative `available` on the finished good is not a bug:
                    // it is the physical truth that these units must still be prepared.
                    try {
                        $this->reserveStock->execute(new StockOperationDTO(
                            warehouse_id: $warehouseId,
                            product_id: $line->product_id,
                            company_id: $companyId,
                            quantity: $requested,
                            reference_type: 'sales_order',
                            reference_id: $order->id,
                            notes: "Reserved for order #{$order->order_number} (made-to-order; executable recipe)",
                            permit_negative_commitment: true,
                        ));
                    } catch (InsufficientStockException) {
                        // Unreachable while the commitment is permitted; kept so a policy
                        // change can never turn a rejected reservation into a crash.
                    }
                    $line->update(['reserved_qty' => $requested]);
                    $reservedLines++;
                    $metaLines[] = ['product_id' => $line->product_id, 'requested' => $requested, 'reserved' => $requested, 'outcome' => 'manufacturing_committed'];

                    continue;
                }

                // TASK-REGRESSION-NEGATIVE-RESERVATION-001: allow_negative_stock on a
                // finished good commits the full ordered quantity as reserved regardless
                // of on-hand qty. Lock any physically available units first; the
                // remainder is a logical commitment that drives inventory negative at
                // shipment time (DirectIssue path).
                if ($product?->allow_negative_stock) {
                    // Reserve the FULL requested quantity, not just the physically
                    // available slice.
                    //
                    // This previously reserved only `$available` and recorded the
                    // remainder as a "logical commitment" that existed nowhere but on
                    // the order line. With on_hand = 0 the guard `$available > 0.0` was
                    // false, so NOTHING was written to inventory_items: reserved_qty
                    // stayed 0 and available never went negative — precisely the
                    // reported symptom, and a commitment invisible to every other
                    // warehouse consumer.
                    //
                    // ReserveStockAction now honours allow_negative_stock, so the whole
                    // quantity is recorded and `available` becomes on_hand − reserved,
                    // negative when commitment exceeds stock. That negative IS the
                    // shortage signal Preparation and Manufacturing need to see.
                    try {
                        $this->reserveStock->execute(new StockOperationDTO(
                            warehouse_id: $warehouseId,
                            product_id: $line->product_id,
                            company_id: $companyId,
                            quantity: $requested,
                            reference_type: 'sales_order',
                            reference_id: $order->id,
                            notes: "Reserved for order #{$order->order_number} (negative stock policy)",
                        ));
                    } catch (InsufficientStockException) {
                        // Should be unreachable while the flag is on; kept so a policy
                        // change can never turn a rejected reservation into a crash.
                    }
                    $line->update(['reserved_qty' => $requested]);
                    $reservedLines++;
                    $metaLines[] = ['product_id' => $line->product_id, 'requested' => $requested, 'reserved' => $requested, 'outcome' => 'negative_stock_committed'];

                    continue;
                }

                // Non-manufactured product with insufficient stock
                if ($available <= 0.0) {
                    $blockedLines++;
                    $failReason ??= 'Insufficient Inventory';
                    $metaLines[] = ['product_id' => $line->product_id, 'requested' => $requested, 'reserved' => 0.0, 'outcome' => 'none'];

                    continue;
                }

                // Partial physical reserve (available > 0 but < requested).
                // BUG-36 fix: wrap in try-catch — stock can drop to zero between the unlocked
                // pre-check above and the lockForUpdate inside ReserveStockAction. When that
                // race fires, treat as AwaitingStock (skipped) instead of propagating a 500.
                try {
                    $this->reserveStock->execute(new StockOperationDTO(
                        warehouse_id: $warehouseId,
                        product_id: $line->product_id,
                        company_id: $companyId,
                        quantity: $available,
                        reference_type: 'sales_order',
                        reference_id: $order->id,
                        notes: "Reserved for order #{$order->order_number}",
                    ));
                    $line->update(['reserved_qty' => $available]);
                    $partialLines++;
                    $metaLines[] = ['product_id' => $line->product_id, 'requested' => $requested, 'reserved' => $available, 'outcome' => 'partial'];
                } catch (InsufficientStockException) {
                    $blockedLines++;
                    $metaLines[] = ['product_id' => $line->product_id, 'requested' => $requested, 'reserved' => 0.0, 'outcome' => 'none'];
                }
                $failReason ??= 'Insufficient Inventory';
            }

            // ── ADR-027 §17 — order-driven Raw Material commitment ───────────────
            // Runs after the finished-goods decision so §16.2 still holds: FG stock is
            // reserved first and is never gated by a recipe. Reconciles (never
            // accumulates), so re-processing this order converges rather than doubling.
            //
            // Deliberately non-fatal: a material blocked by allow_negative_stock = OFF
            // is reported in the result, and the FG reservation status below is decided
            // by the finished-goods outcome exactly as before. Letting a material
            // failure throw here would rewrite order status from inside inventory code,
            // which ADR-027 §4 forbids.
            try {
                $this->reconcileMaterials->execute($order);
            } catch (Throwable $e) {
                report($e);
            }

            // ── Determine new status — ADR-027 §8 ────────────────────────────────
            //
            // The denominator is RESERVABLE lines (quantity > 0), never the raw line
            // count. A zero-quantity line carries no demand, so it can be neither
            // satisfied nor short, and it must tilt the outcome in neither direction.
            //
            // One counter previously stood for two unrelated facts — "nothing to
            // reserve" and "could not reserve" — and that conflation produced two
            // opposite defects out of a single expression:
            //
            //   `$reservedLines === $totalLines - $skippedLines` was TRUE for a
            //   two-line order whose first line reserved in full and whose second was
            //   entirely unavailable, stamping it fully Reserved and erasing the
            //   shortage. §8 admits Reserved only when EVERY line reaches
            //   `reserved_qty = quantity`, and calls ≥1 short line Partial Reserved.
            //
            //   An order with no reservable line at all fulfilled nothing and fell
            //   through to AwaitingStock. §2 forbids that: awaiting_stock asserts an
            //   inventory block, and nothing is blocked when nothing was requested.
            //   Such an order carries no shortage, so it must not be held out of the
            //   canonical lifecycle — it is vacuously Reserved.
            $reservableLines = $reservedLines + $partialLines + $blockedLines;

            $newStatus = match (true) {
                $reservableLines === 0 => ReservationStatus::Reserved,
                $reservedLines === $reservableLines => ReservationStatus::Reserved,
                $reservedLines + $partialLines === 0 => ReservationStatus::AwaitingStock,
                default => ReservationStatus::PartialReserved,
            };

            // A vacuous reservation states no shortage; carrying a failure reason into
            // it would surface an inventory block the order does not have.
            if ($newStatus === ReservationStatus::Reserved) {
                $failReason = null;
            }

            // ── Persist + audit ──────────────────────────────────────────────────
            $previousStatus = $order->reservation_status?->value;

            $order->update([
                'inventory_reserved_at' => now(),
                'reservation_status' => $newStatus->value,
                'reservation_failure_reason' => in_array($newStatus, [ReservationStatus::AwaitingStock, ReservationStatus::PartialReserved], true)
                    ? $failReason
                    : null,
            ]);

            OrderReservationAudit::record(
                orderId: $order->id,
                fromStatus: $previousStatus,
                toStatus: $newStatus->value,
                reason: $failReason,
                warehouseId: $order->assigned_warehouse_id,
                meta: [
                    'lines' => $metaLines,
                    'total_lines' => $totalLines,
                    'reservable_lines' => $reservableLines,
                    'reserved_lines' => $reservedLines,
                    'partial_lines' => $partialLines,
                    'blocked_lines' => $blockedLines,
                    'zero_qty_lines' => $zeroQtyLines,
                ],
                actorId: Auth::id(),
                actorType: Auth::check() ? 'user' : 'system',
            );

            OrderEvent::log(
                orderId: $order->id,
                type: 'reservation_'.$newStatus->value,
                description: match ($newStatus) {
                    ReservationStatus::Reserved => $reservableLines === 0
                        ? "Order #{$order->order_number} has no reservable line; no inventory commitment required."
                        : "Inventory fully reserved for order #{$order->order_number}.",
                    ReservationStatus::PartialReserved => "Inventory partially reserved for order #{$order->order_number}: {$reservedLines} full, {$partialLines} partial, {$blockedLines} pending.",
                    ReservationStatus::AwaitingStock => "No inventory available for order #{$order->order_number}. Awaiting stock.",
                    default => "Reservation status updated to {$newStatus->value}.",
                },
                payload: ['warehouse_id' => $warehouseId, 'lines' => $metaLines],
                module: 'orders',
                actionType: 'inventory',
            );

            return $newStatus;
        });
    }
}
