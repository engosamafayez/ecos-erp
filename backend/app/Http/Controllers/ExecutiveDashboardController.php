<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Single-endpoint executive dashboard aggregator.
 *
 * Returns Sales, Marketing, Shipping, and Operations KPIs in one request,
 * designed to load in <500ms so the dashboard renders without skeleton states.
 */
final class ExecutiveDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user      = $request->user();
        $companyId = $user->company_id;

        return response()->json([
            'sales'     => $this->salesKpis($companyId),
            'marketing' => $this->marketingKpis($companyId),
            'shipping'  => $this->shippingKpis($companyId),
            'monthly'   => $this->monthlyPerformance($companyId),
            'operations'=> $this->operationsSnapshot($companyId),
        ]);
    }

    // ── Sales ────────────────────────────────────────────────────────────────

    private function salesKpis(?string $companyId): array
    {
        $bindings = [];
        $where    = 'deleted_at IS NULL';

        if ($companyId !== null) {
            $where    .= ' AND company_id = ?';
            $bindings[] = $companyId;
        }

        $row = DB::selectOne("
            SELECT
                -- Order counts
                COUNT(*)                                                            FILTER (WHERE created_at::date = CURRENT_DATE)           AS orders_today,
                COUNT(*)                                                            FILTER (WHERE created_at::date = CURRENT_DATE - 1)        AS orders_yesterday,
                COUNT(*)                                                            FILTER (WHERE DATE_TRUNC('month', created_at) = DATE_TRUNC('month', CURRENT_DATE)) AS orders_this_month,

                -- Revenue
                COALESCE(SUM(total)                                                FILTER (WHERE created_at::date = CURRENT_DATE), 0)        AS revenue_today,
                COALESCE(SUM(total)                                                FILTER (WHERE created_at::date = CURRENT_DATE - 1), 0)     AS revenue_yesterday,
                COALESCE(SUM(total)                                                FILTER (WHERE DATE_TRUNC('month', created_at) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS revenue_this_month,

                -- Shipped orders
                COUNT(*)                                                            FILTER (WHERE inventory_shipped_at::date = CURRENT_DATE)  AS orders_shipped_today,
                COALESCE(SUM(total)                                                FILTER (WHERE inventory_shipped_at::date = CURRENT_DATE), 0) AS value_shipped_today,

                -- Gross profit (when available from FIFO costing)
                COALESCE(SUM(actual_margin_amount)                                 FILTER (WHERE created_at::date = CURRENT_DATE), 0)        AS gross_profit_today,
                COALESCE(SUM(actual_margin_amount)                                 FILTER (WHERE DATE_TRUNC('month', created_at) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS gross_profit_month,

                -- Pipeline counts
                COUNT(*) FILTER (WHERE status = 'pending')                                                                                  AS pending_count,
                COUNT(*) FILTER (WHERE status = 'confirmed')                                                                                AS confirmed_count,
                COUNT(*) FILTER (WHERE status = 'preparing')                                                                                AS preparing_count,
                COUNT(*) FILTER (WHERE status = 'out_for_delivery')                                                                         AS out_for_delivery_count,
                COUNT(*) FILTER (WHERE status = 'delivered')                                                                                AS delivered_count,
                COUNT(*) FILTER (WHERE status = 'cancelled' AND created_at::date = CURRENT_DATE)                                           AS cancelled_today
            FROM orders
            WHERE {$where}
        ", $bindings);

        $ordersToday     = (int)  ($row?->orders_today     ?? 0);
        $ordersYesterday = (int)  ($row?->orders_yesterday ?? 0);
        $revenueToday    = (float)($row?->revenue_today    ?? 0);
        $revenueYday     = (float)($row?->revenue_yesterday ?? 0);
        $ordersMonth     = (int)  ($row?->orders_this_month ?? 0);
        $aov             = $ordersToday > 0 ? round($revenueToday / $ordersToday, 2) : 0;

        return [
            'revenue_today'        => round($revenueToday, 2),
            'revenue_yesterday'    => round($revenueYday, 2),
            'revenue_this_month'   => round((float)($row?->revenue_this_month ?? 0), 2),
            'revenue_trend_pct'    => $this->trendPct($revenueToday, $revenueYday),
            'orders_today'         => $ordersToday,
            'orders_yesterday'     => $ordersYesterday,
            'orders_this_month'    => $ordersMonth,
            'orders_trend_pct'     => $this->trendPct($ordersToday, $ordersYesterday),
            'orders_shipped_today' => (int)($row?->orders_shipped_today ?? 0),
            'value_shipped_today'  => round((float)($row?->value_shipped_today ?? 0), 2),
            'aov'                  => $aov,
            'gross_profit_today'   => round((float)($row?->gross_profit_today ?? 0), 2),
            'gross_profit_month'   => round((float)($row?->gross_profit_month ?? 0), 2),
            // Pipeline
            'pending_count'        => (int)($row?->pending_count        ?? 0),
            'confirmed_count'      => (int)($row?->confirmed_count      ?? 0),
            'preparing_count'      => (int)($row?->preparing_count      ?? 0),
            'out_for_delivery'     => (int)($row?->out_for_delivery_count ?? 0),
            'delivered_count'      => (int)($row?->delivered_count      ?? 0),
            'cancelled_today'      => (int)($row?->cancelled_today      ?? 0),
        ];
    }

    // ── Marketing ────────────────────────────────────────────────────────────

    private function marketingKpis(?string $companyId): array
    {
        try {
            $bindings = [];
            $cWhere   = '1=1';

            if ($companyId !== null) {
                $cWhere    .= ' AND c.company_id = ?';
                $bindings[] = $companyId;
            }

            $row = DB::selectOne("
                SELECT
                    COALESCE(SUM(ins.spend)          FILTER (WHERE ins.date_start = CURRENT_DATE), 0)           AS spend_today,
                    COALESCE(SUM(ins.spend)          FILTER (WHERE ins.date_start = CURRENT_DATE - 1), 0)        AS spend_yesterday,
                    COALESCE(SUM(ins.spend)          FILTER (WHERE DATE_TRUNC('month', ins.date_start) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS spend_this_month,
                    COALESCE(SUM(ins.purchase_value) FILTER (WHERE DATE_TRUNC('month', ins.date_start) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS revenue_this_month,
                    COALESCE(SUM(ins.purchases)      FILTER (WHERE DATE_TRUNC('month', ins.date_start) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS purchases_month,
                    COALESCE(SUM(ins.clicks)         FILTER (WHERE DATE_TRUNC('month', ins.date_start) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS clicks_month,
                    COALESCE(SUM(ins.impressions)    FILTER (WHERE DATE_TRUNC('month', ins.date_start) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS impressions_month,
                    COALESCE(SUM(ins.leads)          FILTER (WHERE DATE_TRUNC('month', ins.date_start) = DATE_TRUNC('month', CURRENT_DATE)), 0) AS leads_month,
                    COALESCE(SUM(ins.spend)          FILTER (WHERE ins.date_start = CURRENT_DATE - 1), 0)        AS spend_trend_base
                FROM marketing_campaign_insights ins
                JOIN marketing_campaigns c ON c.id = ins.marketing_campaign_id
                WHERE ins.level = 'campaign' AND {$cWhere}
            ", $bindings);

            $spendToday   = (float)($row?->spend_today    ?? 0);
            $spendYday    = (float)($row?->spend_yesterday ?? 0);
            $revenueMonth = (float)($row?->revenue_this_month ?? 0);
            $spendMonth   = (float)($row?->spend_this_month ?? 0);
            $purchases    = (int)  ($row?->purchases_month ?? 0);
            $clicks       = (int)  ($row?->clicks_month   ?? 0);
            $impressions  = (int)  ($row?->impressions_month ?? 0);

            $roas           = $spendMonth > 0 ? round($revenueMonth / $spendMonth, 2) : null;
            $cac            = $purchases  > 0 ? round($spendMonth   / $purchases,  2) : null;
            $conversionRate = $clicks     > 0 ? round(($purchases   / $clicks) * 100, 2) : null;

            // New vs returning customers from orders linked to marketing month
            $customerRow = null;
            if ($companyId !== null) {
                $customerRow = DB::selectOne("
                    SELECT
                        COUNT(DISTINCT o.customer_id) FILTER (WHERE NOT EXISTS (
                            SELECT 1 FROM orders o2
                            WHERE o2.customer_id = o.customer_id
                              AND o2.deleted_at IS NULL
                              AND o2.created_at < DATE_TRUNC('month', CURRENT_DATE)
                        )) AS new_customers,
                        COUNT(DISTINCT o.customer_id) FILTER (WHERE EXISTS (
                            SELECT 1 FROM orders o2
                            WHERE o2.customer_id = o.customer_id
                              AND o2.deleted_at IS NULL
                              AND o2.created_at < DATE_TRUNC('month', CURRENT_DATE)
                        )) AS returning_customers
                    FROM orders o
                    WHERE o.deleted_at IS NULL
                      AND o.company_id = ?
                      AND DATE_TRUNC('month', o.created_at) = DATE_TRUNC('month', CURRENT_DATE)
                ", [$companyId]);
            }

            return [
                'spend_today'       => round($spendToday, 2),
                'spend_yesterday'   => round($spendYday, 2),
                'spend_this_month'  => round($spendMonth, 2),
                'spend_trend_pct'   => $this->trendPct($spendToday, $spendYday),
                'campaign_revenue'  => round($revenueMonth, 2),
                'roas'              => $roas,
                'cac'               => $cac,
                'conversion_rate'   => $conversionRate,
                'purchases_month'   => $purchases,
                'impressions_month' => $impressions,
                'new_customers'     => (int)($customerRow?->new_customers     ?? 0),
                'returning_customers' => (int)($customerRow?->returning_customers ?? 0),
            ];
        } catch (\Throwable) {
            // Marketing tables may not exist on all environments
            return [
                'spend_today'       => 0, 'spend_yesterday'  => 0,
                'spend_this_month'  => 0, 'spend_trend_pct'  => null,
                'campaign_revenue'  => 0, 'roas'             => null,
                'cac'               => null, 'conversion_rate' => null,
                'purchases_month'   => 0, 'impressions_month' => 0,
                'new_customers'     => 0, 'returning_customers' => 0,
            ];
        }
    }

    // ── Shipping ─────────────────────────────────────────────────────────────

    private function shippingKpis(?string $companyId): array
    {
        try {
            $bindings = [];
            $dtWhere  = '1=1';

            if ($companyId !== null) {
                $dtWhere   .= ' AND dt.company_id = ?';
                $bindings[] = $companyId;
            }

            $row = DB::selectOne("
                SELECT
                    COUNT(*)                                                                                                               AS stops_with_activity,
                    COUNT(*) FILTER (WHERE dds.completed_at::date = CURRENT_DATE)                                                        AS shipments_today,
                    COUNT(*) FILTER (WHERE dds.status = 'delivered' AND dds.completed_at::date = CURRENT_DATE)                           AS delivered_today,
                    COUNT(*) FILTER (WHERE dds.status = 'failed'    AND dds.completed_at::date = CURRENT_DATE)                           AS failed_today,
                    COUNT(*) FILTER (WHERE dds.status = 'returned'  AND dds.completed_at::date = CURRENT_DATE)                           AS returns_today,
                    COALESCE(SUM(dds.collected_amount) FILTER (WHERE dds.completed_at::date = CURRENT_DATE), 0)                          AS cod_collected_today,
                    COALESCE(SUM(dds.collected_amount) FILTER (WHERE dds.status = 'pending'), 0)                                         AS cod_pending,
                    -- Shipping revenue = sum of collected_amount for delivered stops today
                    COALESCE(SUM(dds.collected_amount) FILTER (WHERE dds.status = 'delivered' AND dds.completed_at::date = CURRENT_DATE), 0) AS shipping_revenue_today,
                    COALESCE(SUM(dds.collected_amount) FILTER (WHERE dds.status = 'delivered' AND dds.completed_at::date = CURRENT_DATE - 1), 0) AS shipping_revenue_yesterday,
                    -- Average delivery time in minutes
                    AVG(EXTRACT(EPOCH FROM (dds.completed_at - dt.departure_at)) / 60.0)
                        FILTER (WHERE dds.status = 'delivered' AND dds.completed_at IS NOT NULL AND dt.departure_at IS NOT NULL)          AS avg_delivery_minutes
                FROM driver_delivery_stops dds
                JOIN distribution_trips dt ON dt.id = dds.distribution_trip_id
                WHERE {$dtWhere}
            ", $bindings);

            return [
                'shipments_today'          => (int)  ($row?->shipments_today          ?? 0),
                'delivered_today'          => (int)  ($row?->delivered_today          ?? 0),
                'failed_today'             => (int)  ($row?->failed_today             ?? 0),
                'returns_today'            => (int)  ($row?->returns_today            ?? 0),
                'shipping_revenue_today'   => round((float)($row?->shipping_revenue_today   ?? 0), 2),
                'shipping_revenue_yesterday' => round((float)($row?->shipping_revenue_yesterday ?? 0), 2),
                'cod_collected_today'      => round((float)($row?->cod_collected_today ?? 0), 2),
                'cod_pending'              => round((float)($row?->cod_pending         ?? 0), 2),
                'avg_delivery_minutes'     => $row?->avg_delivery_minutes !== null
                    ? round((float)$row->avg_delivery_minutes, 0)
                    : null,
            ];
        } catch (\Throwable) {
            return [
                'shipments_today' => 0, 'delivered_today' => 0, 'failed_today' => 0,
                'returns_today'   => 0, 'shipping_revenue_today' => 0,
                'shipping_revenue_yesterday' => 0, 'cod_collected_today' => 0,
                'cod_pending' => 0, 'avg_delivery_minutes' => null,
            ];
        }
    }

    // ── Monthly Performance ───────────────────────────────────────────────────

    private function monthlyPerformance(?string $companyId): array
    {
        $bindings = [];
        $where    = "deleted_at IS NULL AND DATE_TRUNC('month', created_at) = DATE_TRUNC('month', CURRENT_DATE)";

        if ($companyId !== null) {
            $where    .= ' AND company_id = ?';
            $bindings[] = $companyId;
        }

        $row = DB::selectOne("
            SELECT
                COALESCE(SUM(total), 0) AS monthly_revenue,
                COUNT(*)                AS monthly_orders,
                COALESCE(SUM(total) FILTER (WHERE status NOT IN ('cancelled', 'returned')), 0) AS monthly_revenue_net
            FROM orders
            WHERE {$where}
        ", $bindings);

        // Revenue target: no target table exists yet — return null so the UI shows a "--" state
        return [
            'monthly_revenue'     => round((float)($row?->monthly_revenue     ?? 0), 2),
            'monthly_revenue_net' => round((float)($row?->monthly_revenue_net ?? 0), 2),
            'monthly_orders'      => (int)($row?->monthly_orders ?? 0),
            'revenue_target'      => null, // no target table yet
            'progress_pct'        => null,
        ];
    }

    // ── Operations Snapshot ───────────────────────────────────────────────────

    private function operationsSnapshot(?string $companyId): array
    {
        try {
            $bindings = [];
            $wWhere   = 'deleted_at IS NULL AND status NOT IN (\'completed\', \'cancelled\')';
            $tWhere   = "status IN ('out_for_delivery', 'dispatched')";

            if ($companyId !== null) {
                $wWhere   .= ' AND company_id = ?';
                $bindings[] = $companyId;
                $tWhere   .= ' AND company_id = ?';
                $bindings[] = $companyId;
            }

            $wavesRow = DB::selectOne(
                "SELECT COUNT(*) AS active_waves FROM preparation_waves WHERE {$wWhere}",
                array_slice($bindings, 0, $companyId !== null ? 1 : 0)
            );

            $tripsRow = DB::selectOne(
                "SELECT COUNT(*) AS active_trips FROM distribution_trips WHERE {$tWhere}",
                $companyId !== null ? array_slice($bindings, 1) : []
            );

            return [
                'active_waves'   => (int)($wavesRow?->active_waves ?? 0),
                'active_trips'   => (int)($tripsRow?->active_trips ?? 0),
            ];
        } catch (\Throwable) {
            return ['active_waves' => 0, 'active_trips' => 0];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function trendPct(float $current, float $previous): ?float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
