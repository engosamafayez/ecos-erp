<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;

/**
 * Order-derived metrics for a customer — the SINGLE source for these KPIs.
 *
 * WHY THIS LIVES IN COMMERCE, NOT IN Customer360Service
 * -----------------------------------------------------
 * Customer360Service states its contract explicitly: "It deliberately imports NO
 * operational module — Commerce, Finance, Logistics and Marketing reference the
 * customer and each builds its own 360° panel; the dependency never inverts."
 * Querying `orders` from inside the CRM service would invert exactly that. The
 * metrics therefore live on the Commerce side and are COMPOSED into the customer
 * responses by the presentation layer, which is allowed to depend on both.
 *
 * NOT the customer-intelligence pipeline. `crm_customer_purchase_facts` (which
 * backs lifetime_value / AOV / churn) is written only by a manual HTTP endpoint —
 * no order event ever records a fact, and the table is empty — so every figure
 * derived from it is currently zero by construction. These KPIs read canonical
 * `orders` directly and are deliberately independent of that pipeline.
 *
 * APPROVED SEMANTICS (TASK-CUSTOMER-360-ENHANCEMENTS-001)
 * -------------------------------------------------------
 *   Total Orders   = every order belonging to the customer, within the tenant.
 *   Total Value    = SUM(orders.total) over ALL those orders. Cancelled and
 *                    Returned are NOT excluded: this measures the value of the
 *                    customer's orders, not Customer Lifetime Value.
 *   Delivered      = OrderStatus::Delivered ONLY. Returned is a distinct terminal
 *                    state and is never counted as delivered.
 *   Receiving Rate = Delivered / ALL orders × 100. Cancelled and Returned remain
 *                    in the denominator. NULL when the customer has no orders —
 *                    never 0%, which would misread as "never receives".
 *
 * LAST ORDER DATE (TASK-CUSTOMER-DOMAIN-FINAL-CLOSURE-001)
 * --------------------------------------------------------
 *   Last Order = MAX(orders.order_date), NOT MAX(created_at).
 *
 *   This is not a new decision — it is the Order domain's own definition, already
 *   in OrderResource's customer stats block:
 *       MIN(order_date) AS first_order_date, MAX(order_date) AS last_order_date
 *   `order_date` is the business date of the order (NOT NULL, user-supplied, and what
 *   the Orders module filters and sorts by); `created_at` is row-insertion time. For an
 *   imported or back-dated order the two disagree, and the business date is the one that
 *   answers "when did this customer last order".
 *
 *   This closes the LAST_ORDER_DATE_CONTRACT gap recorded in
 *   TASK-SALES-CUSTOMERS-POST-360-HARDENING-001 §S.7 — the frontend was already reading
 *   `order_date`; this service was the inconsistent side.
 */
final class CustomerOrderMetricsService
{
    /**
     * Metrics for many customers in ONE query.
     *
     * Used by the customer list so N rows cost one aggregate scan rather than N
     * round-trips. Customers with no orders are simply absent from the result and
     * the caller supplies the zero/NULL shape via {@see self::emptyMetrics()}.
     *
     * @param  list<string>  $customerIds
     * @return array<string, array<string, mixed>> keyed by customer_id
     */
    public function forCustomers(array $customerIds, string $companyId): array
    {
        if ($customerIds === []) {
            return [];
        }

        $rows = DB::table('orders')
            ->select('customer_id')
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(total), 0) AS total_order_value')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS delivered_count', [OrderStatus::Delivered->value])
            // LAST ORDER DATE = MAX(order_date). This is the Order domain's OWN definition,
            // already established in OrderResource's customer stats block
            // (MIN(order_date) as first_order_date, MAX(order_date) as last_order_date).
            // `order_date` is the business date of the order — NOT NULL, user-supplied, and
            // what the Orders module filters and sorts by. `created_at` is row-insertion time
            // and is deliberately NOT used here: a back-dated or imported order would
            // otherwise report the wrong "last order".
            ->selectRaw('MAX(order_date) AS last_order_at')
            ->whereIn('customer_id', $customerIds)
            ->where('company_id', $companyId)   // tenant boundary — never cross-company
            ->whereNull('deleted_at')
            ->groupBy('customer_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->customer_id] = self::shape(
                (int) $row->orders_count,
                (float) $row->total_order_value,
                (int) $row->delivered_count,
                $row->last_order_at !== null ? (string) $row->last_order_at : null,
            );
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function forCustomer(string $customerId, string $companyId): array
    {
        return $this->forCustomers([$customerId], $companyId)[$customerId] ?? self::emptyMetrics();
    }

    /**
     * Distinct products this customer has ordered, aggregated across all orders.
     *
     * One grouped query — never "fetch all orders and aggregate in the client".
     * A product ordered in five separate orders appears once, with the summed
     * quantity and the number of orders containing it.
     *
     * @return list<array<string, mixed>>
     */
    public function purchasedProducts(string $customerId, string $companyId): array
    {
        return DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'ol.product_id')
            ->where('o.customer_id', $customerId)
            ->where('o.company_id', $companyId)
            ->whereNull('o.deleted_at')
            ->groupBy('ol.product_id', 'p.name', 'p.sku')
            ->selectRaw('
                ol.product_id,
                p.name  AS product_name,
                p.sku   AS product_sku,
                SUM(ol.quantity)            AS total_quantity,
                COUNT(DISTINCT o.id)        AS orders_count,
                MAX(o.order_date)           AS last_ordered_at
            ')
            ->orderByDesc('total_quantity')
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'product_name' => $r->product_name,
                'product_sku' => $r->product_sku,
                'total_quantity' => round((float) $r->total_quantity, 4),
                'orders_count' => (int) $r->orders_count,
                'last_ordered_at' => $r->last_ordered_at,
            ])
            ->values()
            ->all();
    }

    /**
     * Top products for MANY customers in ONE query.
     *
     * Window functions do the per-customer ranking in the database: ROW_NUMBER caps the
     * rows at $limit per customer, and COUNT(*) OVER counts the grouped rows — which is
     * exactly the number of DISTINCT products that customer has ordered, not the number
     * of units. No query per customer, and no sorting or slicing in the client.
     *
     * @param  list<string>  $customerIds
     * @return array<string, array{distinct_count: int, top: list<array<string, mixed>>}>
     */
    public function topProductsForCustomers(array $customerIds, string $companyId, int $limit = 5): array
    {
        if ($customerIds === []) {
            return [];
        }

        $ranked = DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->leftJoin('products as p', 'p.id', '=', 'ol.product_id')
            ->whereIn('o.customer_id', $customerIds)
            ->where('o.company_id', $companyId)
            ->whereNull('o.deleted_at')
            ->groupBy('o.customer_id', 'ol.product_id', 'p.name')
            ->selectRaw('
                o.customer_id,
                ol.product_id,
                p.name AS product_name,
                SUM(ol.quantity) AS total_quantity,
                ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY SUM(ol.quantity) DESC) AS rn,
                COUNT(*)     OVER (PARTITION BY o.customer_id)                              AS distinct_products
            ');

        $rows = DB::query()->fromSub($ranked, 't')->where('rn', '<=', $limit)->orderBy('customer_id')->orderBy('rn')->get();

        $out = [];

        foreach ($rows as $row) {
            $key = (string) $row->customer_id;
            $out[$key] ??= ['distinct_count' => (int) $row->distinct_products, 'top' => []];
            $out[$key]['top'][] = [
                'product_id' => $row->product_id,
                'product_name' => $row->product_name,
                'total_quantity' => round((float) $row->total_quantity, 4),
            ];
        }

        return $out;
    }

    /**
     * The Google Maps URL for many customers in ONE query.
     *
     * CANONICAL SOURCE: `orders.google_maps_url` — the link captured on the order itself.
     * A customer with several orders resolves to the MOST RECENT order that actually
     * carries a link, so an older order without one never blanks out a known location.
     * Nothing is synthesised from city, zone or lat/lng.
     *
     * @param  list<string>  $customerIds
     * @return array<string, string> customer_id => url
     */
    public function locationUrlForCustomers(array $customerIds, string $companyId): array
    {
        if ($customerIds === []) {
            return [];
        }

        $ranked = DB::table('orders')
            ->whereIn('customer_id', $customerIds)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('google_maps_url')
            ->where('google_maps_url', '!=', '')
            // "Most recent order" means the same thing here as everywhere else: business
            // date first, then insertion time, then id as the stable tiebreak.
            ->selectRaw('
                customer_id,
                google_maps_url,
                ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY order_date DESC, created_at DESC, id DESC) AS rn
            ');

        $rows = DB::query()->fromSub($ranked, 't')->where('rn', '=', 1)->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->customer_id] = (string) $row->google_maps_url;
        }

        return $out;
    }

    /**
     * The governorate appearing most often in a customer's orders — for MANY customers in ONE query.
     *
     * DEFINITION (moved here from React, unchanged): the most frequent
     * `orders.governorate` across the customer's orders.
     *
     * TIE-BREAKING follows the existing platform convention — every ordering ends in a
     * deterministic ascending tiebreaker on a natural key. See
     * EnterpriseQueueSorterService ("order_number ASC — stable tiebreaker") and
     * ActivityTimelineService ("each row carries a unique id as the tiebreak"). Here the
     * natural key is the governorate name, so equal counts resolve alphabetically. That
     * makes the result stable across runs; the previous client-side version resolved ties
     * by JS object insertion order, which was incidental rather than specified.
     *
     * Orders with a NULL or empty governorate are excluded from the frequency count
     * entirely — they are not a governorate, and must not win by accident.
     *
     * A customer with no qualifying orders is simply absent, and the caller renders null.
     * No value is ever invented.
     *
     * @param  list<string>  $customerIds
     * @return array<string, string> customer_id => governorate
     */
    public function preferredGovernorateForCustomers(array $customerIds, string $companyId): array
    {
        if ($customerIds === []) {
            return [];
        }

        $ranked = DB::table('orders')
            ->whereIn('customer_id', $customerIds)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('governorate')
            ->where('governorate', '!=', '')
            ->groupBy('customer_id', 'governorate')
            ->selectRaw('
                customer_id,
                governorate,
                ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY COUNT(*) DESC, governorate ASC) AS rn
            ');

        $rows = DB::query()->fromSub($ranked, 't')->where('rn', '=', 1)->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->customer_id] = (string) $row->governorate;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function emptyMetrics(): array
    {
        return self::shape(0, 0.0, 0, null);
    }

    /** @return array<string, mixed> */
    private static function shape(int $orders, float $value, int $delivered, ?string $lastOrderAt): array
    {
        return [
            'orders_count' => $orders,
            'total_order_value' => round($value, 2),
            'delivered_count' => $delivered,
            // NULL, not 0, when there are no orders: a customer who has never
            // ordered has no receiving rate, and 0% would read as a failure.
            'receiving_rate' => $orders > 0 ? round(($delivered / $orders) * 100, 2) : null,
            'average_order_value' => $orders > 0 ? round($value / $orders, 2) : null,
            'last_order_at' => $lastOrderAt,
        ];
    }
}
