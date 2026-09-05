<?php

declare(strict_types=1);

namespace Modules\Operations\ShippingOrders\Domain\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Operations\ShippingOrders\Domain\Enums\ShippingOrderClassification;

/**
 * The ONE bounded Shipping Orders read model — TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-
 * IMPLEMENTATION-002 §14. Anchored on `Order`, joined to its current DeliveryStop and
 * that stop's Trip (driver/shipping-company), with the latest DeliveryAction and
 * custody evidence read via correlated scalar subqueries — a single, bounded SQL query
 * per page, not one application-level query per row (see `CUSTODY_SQL`'s own docblock
 * for why a subquery is the correct tool here, matching this task's own §14
 * "bounded aggregate/subquery patterns" allowance).
 *
 * Population boundary (Architecture-001 §4/§5): only orders that have a DeliveryStop
 * at all are candidates — Distribution Group/Vehicle/Driver assignment alone never
 * qualifies an order for this page, because no DeliveryStop exists until the order has
 * genuinely entered a Trip's delivery-stop list.
 *
 * Deliberately does NOT read `orders.status`/`orders.inventory_shipped_at` for
 * anything eligibility- or classification-related — Architecture-001 proved both are
 * disconnected from the live Distribution flow (this task's own §2).
 */
final class ShippingOrderReadModel
{
    /**
     * Latest DeliveryAction fields for this order's stop — a correlated scalar
     * subquery per field, evaluated by the database once per candidate row as part of
     * the ONE query the page already runs; not a second application-level query.
     */
    private const LATEST_REASON_SQL = <<<'SQL'
        (SELECT da.reason FROM distribution_delivery_actions da
         WHERE da.stop_id = ds.id ORDER BY da.created_at DESC LIMIT 1)
        SQL;

    private const LATEST_ACTION_TYPE_SQL = <<<'SQL'
        (SELECT da.action_type FROM distribution_delivery_actions da
         WHERE da.stop_id = ds.id ORDER BY da.created_at DESC LIMIT 1)
        SQL;

    /**
     * Custody evidence: TRUE only when EVERY distinct product on this order's own
     * lines has a driver-confirmed LoadingTask within this order's Trip's (Loading)
     * VehicleAssignment. Deliberately per-PRODUCT, not "any LoadingTask on this
     * vehicle confirmed" — the latter would report custody as confirmed for an order
     * whose own goods are not yet confirmed merely because some OTHER product on the
     * same truck was, which is exactly the "fake handoff from another field" this
     * task's §3 forbids. A correlated subquery (not a per-row application query) —
     * the database evaluates it once per row of the same bounded page query.
     */
    private const CUSTODY_SQL = <<<'SQL'
        (NOT EXISTS (
            SELECT 1 FROM order_lines ol
            WHERE ol.order_id = orders.id
            AND NOT EXISTS (
                SELECT 1
                FROM vehicle_assignments va
                INNER JOIN loading_tasks lt ON lt.vehicle_assignment_id = va.id
                WHERE va.trip_id = ds.trip_id
                AND lt.product_id = ol.product_id
                AND lt.driver_confirmed_at IS NOT NULL
            )
        ))
        SQL;

    /**
     * The classification SQL expression, duplicating ShippingOrderClassificationService's
     * own precedence in SQL so tab filters/counts can run as ONE bounded, set-based
     * query instead of "fetch everything, then filter in PHP" (which would break
     * pagination correctness whenever a classification tab is active). The PHP service
     * remains the single value actually RETURNED to the frontend for each row — this
     * SQL copy exists only for WHERE/GROUP BY. If
     * ShippingOrderClassificationService::classify()'s precedence ever changes, this
     * expression MUST change with it — kept in the same file as every other query
     * fragment for exactly that reason, not scattered.
     */
    public static function classificationSql(): string
    {
        $reason = self::LATEST_REASON_SQL;
        $actionType = self::LATEST_ACTION_TYPE_SQL;
        $custody = self::CUSTODY_SQL;

        return <<<SQL
            CASE
                WHEN ds.status IN ('delivered', 'partial') THEN 'delivered'
                WHEN ds.status IN ('failed', 'returned', 'skipped') THEN
                    CASE
                        WHEN ({$reason}) IS NULL THEN 'cancelled'
                        WHEN ({$reason}) IN ('customer_refused', 'product_damaged', 'wrong_item', 'item_missing') THEN 'cancelled'
                        WHEN ({$reason}) IN ('customer_unavailable', 'no_answer') THEN 'no_answer'
                        ELSE 'postponed'
                    END
                WHEN ({$actionType}) = 'delay' THEN 'postponed'
                WHEN ds.status = 'in_progress' THEN 'out_for_delivery'
                WHEN ds.status = 'pending' AND {$custody} THEN 'assigned_driver'
                ELSE NULL
            END
            SQL;
    }

    public function __construct(private readonly ShippingOrderClassificationService $classifier) {}

    /**
     * The shared join skeleton — Order to its own MOST RECENT DeliveryStop (a
     * defensive choice — the current design has one active DeliveryStop per order,
     * but a re-attempted order could in principle gain a second one on a later Trip;
     * picking the latest by creation keeps the page truthful either way, never
     * double-counting an order under two stops), and that stop's Trip. No SELECT
     * list yet — `baseQuery()` (full row fetch) and the controller's tab-count query
     * both layer their OWN, different SELECT on top of this same join, so the two
     * never fight over conflicting `selectRaw()` calls on one query.
     */
    public function joinedQuery(string $companyId): Builder
    {
        return Order::query()
            ->where('orders.company_id', $companyId)
            ->join('distribution_delivery_stops as ds', function ($join) {
                $join->on('ds.order_id', '=', 'orders.id')
                    ->whereRaw('ds.id = (
                        SELECT id FROM distribution_delivery_stops
                        WHERE order_id = orders.id
                        ORDER BY created_at DESC
                        LIMIT 1
                    )');
            })
            ->leftJoin('distribution_trips as trip', 'trip.id', '=', 'ds.trip_id');
    }

    /** `joinedQuery()` plus the full row SELECT this page's table needs per order. */
    public function baseQuery(string $companyId): Builder
    {
        return $this->joinedQuery($companyId)
            ->selectRaw(
                'orders.*, '
                .'ds.id as ds_id, ds.status as ds_status, ds.gps_lat as ds_gps_lat, ds.gps_lng as ds_gps_lng, '
                .'ds.trip_id as ds_trip_id, '
                .'trip.driver_vehicle_assignment_id as trip_driver_vehicle_assignment_id, '
                .'trip.shipping_company_id as trip_shipping_company_id, '
                .'trip.type as trip_type, trip.trip_started_at as trip_started_at, '
                .'('.self::LATEST_ACTION_TYPE_SQL.') as latest_action_type, '
                .'('.self::LATEST_REASON_SQL.') as latest_reason, '
                .self::CUSTODY_SQL.' as custody_confirmed, '
                .'('.self::classificationSql().') as shipping_classification',
            )
            ->with(['channel.brand', 'customer', 'lines']);
    }

    /** Narrow a query built from `baseQuery()` to only rows this page may show at all. */
    public function applyEligibility(Builder $query): Builder
    {
        return $query->whereRaw('('.self::classificationSql().') IS NOT NULL');
    }

    /**
     * Resolve one row's classification via the SAME PHP authority every row's
     * displayed value comes from (not the SQL copy — that exists only to make
     * filtering/counting possible in SQL, per this class's own docblock).
     */
    public function classificationFor(Order $row): ?ShippingOrderClassification
    {
        /** @var string $status */
        $status = $row->getAttribute('ds_status');

        return $this->classifier->classify(
            DeliveryStopStatus::from($status),
            $row->getAttribute('latest_action_type'),
            $row->getAttribute('latest_reason'),
            (bool) $row->getAttribute('custody_confirmed'),
        );
    }

    public function paginate(Builder $query, int $perPage, int $page): LengthAwarePaginator
    {
        return $query->paginate($perPage, ['*'], 'page', $page);
    }
}
