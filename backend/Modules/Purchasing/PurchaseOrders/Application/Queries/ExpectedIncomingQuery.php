<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseOrders\Application\Queries;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;

/**
 * "Expected Incoming Qty" — how much of a product Procurement has ordered on open purchase
 * orders for a warehouse but has NOT yet received into inventory.
 *
 * TASK-PREPARATION-OPERATIONS-UX-002 §9-§11. This is PLANNING data, surfaced so Preparation
 * can see that a physical shortage is already being covered by an order in flight. It is
 * derived, READ-ONLY, from `purchase_order_lines.quantity - received_qty` over receivable POs.
 * It is NOT a new field and NOT inventory:
 *
 *   - it never touches inventory_items, stock_ledger_entries, receipt layers (FIFO), GRNs,
 *     or reservations — those change ONLY on an actual goods receipt (ReceiveStockAction);
 *   - it never reduces the real `missing_qty` (the SSOT physical shortage);
 *   - "receivable" = PurchaseOrderStatus::canReceive() (approved | partially_received), so a
 *     draft/submitted PO contributes nothing until it is a firm, approved commitment.
 *
 * Lives in Purchasing so the procurement query stays out of Preparation. Company + warehouse
 * scoped for tenant isolation.
 */
final class ExpectedIncomingQuery
{
    /**
     * Expected-incoming quantity per product for the given warehouse.
     *
     * @param  list<string>  $productIds
     * @return array<string, float> product_id => expected_incoming_qty
     */
    public function forWarehouse(string $companyId, string $warehouseId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $receivable = array_map(
            static fn (PurchaseOrderStatus $s): string => $s->value,
            array_filter(PurchaseOrderStatus::cases(), static fn (PurchaseOrderStatus $s): bool => $s->canReceive()),
        );

        return DB::table('purchase_order_lines as pol')
            ->join('purchase_orders as po', 'po.id', '=', 'pol.purchase_order_id')
            ->where('po.company_id', $companyId)
            ->where('po.warehouse_id', $warehouseId)
            ->whereIn('po.status', $receivable)
            ->whereNull('po.deleted_at')
            ->whereIn('pol.product_id', $productIds)
            ->whereColumn('pol.quantity', '>', 'pol.received_qty')
            ->groupBy('pol.product_id')
            ->selectRaw('pol.product_id, SUM(pol.quantity - pol.received_qty) AS expected_incoming')
            ->get()
            ->mapWithKeys(static fn ($r): array => [(string) $r->product_id => (float) $r->expected_incoming])
            ->all();
    }
}
