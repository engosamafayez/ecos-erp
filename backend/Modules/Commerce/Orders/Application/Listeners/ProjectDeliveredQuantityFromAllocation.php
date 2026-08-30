<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Operations\Loading\Domain\Events\ProductDeliveryRecorded;

/**
 * Projects the canonical delivered quantity onto the Commerce order line.
 *
 * `allocation_records.quantity_delivered` (ADR-015, "the definitive record of what
 * a driver delivers", written only by RecordProductDeliveryAction) is the single
 * source of truth. This listener re-derives the order line's `delivered_qty` as the
 * SUM of every allocation for that line, so:
 *   • a split shipment (one order line spread across several vehicles) sums correctly;
 *   • replaying the same delivery is a no-op (absolute set from the source);
 *   • the projection is deterministic and idempotent.
 *
 * It writes ONLY `order_lines.delivered_qty`. It never touches `Order.status`
 * (ORM-guarded to FulfillmentEngine), the stock ledger, reservations, or vehicle
 * custody — those are owned elsewhere and are explicitly out of scope
 * (TASK-DRIVER-04 decision A: "inventory-neutral" = warehouse-stock-neutral).
 *
 * There is no second delivered-quantity source: this reads back the canonical one.
 */
final class ProjectDeliveredQuantityFromAllocation
{
    public function handle(ProductDeliveryRecorded $event): void
    {
        // Re-derive from the canonical source. company_id is a belt-and-braces tenant
        // filter on top of the globally-unique order_line_id.
        $delivered = (float) DB::table('allocation_records')
            ->where('order_line_id', $event->orderLineId)
            ->where('company_id', $event->companyId)
            ->sum('quantity_delivered');

        // Absolute set. A delivery recorded against an allocation whose order line
        // does not exist (e.g. a synthetic allocation with no matching order line)
        // simply updates zero rows rather than erroring.
        OrderLine::query()
            ->whereKey($event->orderLineId)
            ->update(['delivered_qty' => $delivered]);
    }
}
