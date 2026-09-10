<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * TASK-...-026 §3.B/§4 — Commerce domain reset (Orders and everything that must go with them).
 *
 * Deletion order is evidence-derived (Task 026's own dependency-graph research read every
 * migration listed below directly — this is not a guess):
 *
 *  1. RESTRICT-FK children of `orders` that the DB will not let an Order be deleted while they
 *     exist: `order_business_context_snapshots` (restrict), `fulfillment_lines`→`fulfillments`
 *     (restrict), `customer_returns` (restrict — an Operations-owned table, but its FK blocks
 *     Commerce's own deletion, so Commerce must clear it regardless of whether the Operations
 *     domain is separately selected), `preparation_session_orders` (restrict — the JOIN row
 *     only; the parent `preparation_sessions`/`preparation_waves` aggregate is Operations
 *     domain's own responsibility, not deleted here).
 *  2. The `orders` rows themselves. Every remaining CASCADE-FK child (`order_lines`,
 *     `order_fees`, `order_coupons`, `order_events`, `order_financial_snapshots` [+
 *     `order_line_snapshots`], `order_notes`, `order_reservation_audits`, `payment_proofs`,
 *     `distribution_trip_orders`, `distribution_delivery_stops` [+ `distribution_delivery_actions`
 *     + `distribution_delivery_proofs`], `distribution_delivery_exceptions`,
 *     `distribution_trip_returns`) is removed automatically by the DB's own FK cascade — deleting
 *     them explicitly first would be redundant, not safer.
 *
 * Uses `DB::table()` raw deletes throughout, not Eloquent models — deliberately: this is a bulk
 * test-data wipe, not a business operation, and must not risk firing Order/Customer/Product
 * Observers (several of which dispatch outbound Woo sync jobs — TASK-...-024/025) as a side
 * effect of deletion.
 */
final class CommerceResetService
{
    /**
     * @return array<string, int> counts of what WOULD be affected — zero mutation.
     */
    public function previewCounts(string $companyId): array
    {
        $orderIds = $this->orderIds($companyId);

        return [
            'orders' => count($orderIds),
            'order_business_context_snapshots' => $this->countByOrderIds('order_business_context_snapshots', $orderIds),
            'fulfillments' => $this->countByOrderIds('fulfillments', $orderIds),
            'customer_returns' => $this->countByOrderIds('customer_returns', $orderIds),
            'preparation_session_orders' => $this->countByOrderIds('preparation_session_orders', $orderIds),
            'payment_proofs' => $this->countByOrderIds('payment_proofs', $orderIds),
        ];
    }

    /**
     * @return array<string, int> counts of what was actually deleted.
     */
    public function execute(string $companyId): array
    {
        $orderIds = $this->orderIds($companyId);

        if ($orderIds === []) {
            return ['orders' => 0];
        }

        $counts = [];

        $counts['order_business_context_snapshots'] = $this->deleteByOrderIds('order_business_context_snapshots', $orderIds);

        $fulfillmentIds = DB::table('fulfillments')->whereIn('order_id', $orderIds)->pluck('id')->all();
        if ($fulfillmentIds !== []) {
            DB::table('fulfillment_lines')->whereIn('fulfillment_id', $fulfillmentIds)->delete();
        }
        $counts['fulfillments'] = $this->deleteByOrderIds('fulfillments', $orderIds);

        $counts['customer_returns'] = $this->deleteByOrderIds('customer_returns', $orderIds);
        $counts['preparation_session_orders'] = $this->deleteByOrderIds('preparation_session_orders', $orderIds);

        // Now unblocked — the DB cascades every remaining child listed in the class docblock.
        $counts['orders'] = DB::table('orders')->whereIn('id', $orderIds)->delete();

        return $counts;
    }

    /** @return list<string> */
    private function orderIds(string $companyId): array
    {
        return DB::table('orders')->where('company_id', $companyId)->pluck('id')->all();
    }

    /** @param  list<string>  $orderIds */
    private function countByOrderIds(string $table, array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        return DB::table($table)->whereIn('order_id', $orderIds)->count();
    }

    /** @param  list<string>  $orderIds */
    private function deleteByOrderIds(string $table, array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        return DB::table($table)->whereIn('order_id', $orderIds)->delete();
    }
}
