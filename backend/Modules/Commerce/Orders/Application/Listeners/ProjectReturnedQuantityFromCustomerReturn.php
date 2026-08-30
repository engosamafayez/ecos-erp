<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Operations\Fulfillment\Domain\Events\OrderReturnedEvent;

/**
 * TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001 (§16) — the single canonical
 * writer of `order_lines.returned_qty`.
 *
 * `customer_return_lines.quantity_returned` (written only by ReturnOrderWorkflow, the
 * one order-lifecycle-bearing return authority — ADR-015 §11 / Operations\Fulfillment)
 * is the source of truth for "how much of this order line the customer returned". This
 * listener re-derives the order line's `returned_qty` as the SUM of every return line
 * for that order line, so:
 *   • multiple returns against one line sum correctly;
 *   • replaying OrderReturnedEvent is a no-op (absolute set from the source);
 *   • there is exactly ONE authority — no Driver / Warehouse / Reconciliation / Delivery
 *     path writes a competing returned_qty (§16).
 *
 * Condition (sellable / damaged / destroyed) governs RESTOCK — handled canonically by
 * ReceiveReturnWorkflow — not whether the unit was returned, so every returned unit
 * counts here regardless of condition.
 *
 * It writes ONLY `order_lines.returned_qty`. It never touches `Order.status`
 * (ORM-guarded to FulfillmentEngine), the stock ledger, reservations, or vehicle
 * custody. Mirrors ProjectDeliveredQuantityFromAllocation exactly (§16 "copy the
 * delivered_qty projection").
 */
final class ProjectReturnedQuantityFromCustomerReturn
{
    public function handle(OrderReturnedEvent $event): void
    {
        // Re-derive every affected order line from the canonical source: all customer
        // return lines belonging to any customer return for this order. order_id on
        // customer_returns is the tenant-safe anchor (globally unique).
        $sums = DB::table('customer_return_lines')
            ->join('customer_returns', 'customer_returns.id', '=', 'customer_return_lines.customer_return_id')
            ->where('customer_returns.order_id', $event->orderId)
            ->whereNotNull('customer_return_lines.order_line_id')
            ->groupBy('customer_return_lines.order_line_id')
            ->select('customer_return_lines.order_line_id')
            ->selectRaw('SUM(customer_return_lines.quantity_returned) AS returned_qty')
            ->get();

        foreach ($sums as $row) {
            // Absolute set. A return line whose order line no longer exists simply
            // updates zero rows rather than erroring.
            OrderLine::query()
                ->whereKey($row->order_line_id)
                ->update(['returned_qty' => (float) $row->returned_qty]);
        }
    }
}
