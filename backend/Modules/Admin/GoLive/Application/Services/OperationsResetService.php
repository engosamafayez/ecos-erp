<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * TASK-...-026 §3.C/§4 — Operations domain reset.
 *
 * SCOPED DELIBERATELY NARROWER than "all of Operations": this covers the Preparation track
 * (sessions/waves) and the Distribution/Trip track (the module that owns "Trip", "Delivery Stop",
 * "Driver custody", "Cash Handover" by name — Task 026's own §3.C examples). The separate
 * Loading/VehiclePlan track (`vehicle_plans`, `loading_sessions`, `vehicle_assignments`,
 * `allocation_records`, `route_plans`, `shipment_groups`, `vehicle_inventory_items`, ~12 tables
 * total, mostly linked to `orders` by plain non-FK uuid columns) is explicitly OUT OF SCOPE for
 * this checkpoint — named here and in the report, not silently dropped. Getting a 12-table graph
 * right for a track this task could not confirm is the "live" one vs. superseded, under time
 * pressure, was judged riskier than leaving it named and undone.
 *
 * Deletion order (evidence-derived from migrations, restrict-FKs first):
 *  Distribution/Trip: `distribution_trip_cash_handovers` (restrict, both FKs) →
 *    `distribution_trip_settlements` (now unblocked) → `distribution_trips` (cascades
 *    `distribution_trip_custody`, `driver_trip_movements`, `distribution_trip_orders`,
 *    `distribution_delivery_stops` [+actions+proofs], `distribution_delivery_exceptions`,
 *    `distribution_trip_returns`, `distribution_payment_collections` automatically).
 *  Preparation: `preparation_session_orders` + `preparation_wave_orders` (join rows) →
 *    `prepared_pool_movements` (restrict child of pool) → `prepared_products_pool` →
 *    `preparation_inventory_reservations` (restrict child of waves) → `preparation_waves` (now
 *    unblocked) → `preparation_sessions`.
 *
 * `preparation_session_orders` is cleared here independently of CommerceResetService's own clear
 * of the same table — both are safe: whichever runs, or both, the table ends up empty for the
 * relevant ids; a delete of already-deleted rows is a no-op.
 */
final class OperationsResetService
{
    public function previewCounts(string $companyId): array
    {
        $tripIds = $this->tripIds($companyId);
        $sessionIds = $this->sessionIds($companyId);
        $waveIds = $this->waveIds($sessionIds);

        return [
            'distribution_trips' => count($tripIds),
            'distribution_trip_settlements' => $this->countByParentIds('distribution_trip_settlements', 'trip_id', $tripIds),
            'distribution_trip_cash_handovers' => $this->countByParentIds('distribution_trip_cash_handovers', 'trip_id', $tripIds),
            'preparation_sessions' => count($sessionIds),
            'preparation_waves' => count($waveIds),
        ];
    }

    public function execute(string $companyId): array
    {
        $counts = [];

        $tripIds = $this->tripIds($companyId);
        if ($tripIds !== []) {
            $counts['distribution_trip_cash_handovers'] = DB::table('distribution_trip_cash_handovers')->whereIn('trip_id', $tripIds)->delete();
            $counts['distribution_trip_settlements'] = DB::table('distribution_trip_settlements')->whereIn('trip_id', $tripIds)->delete();
            // Cascades: trip_custody, driver_trip_movements, trip_orders, delivery_stops
            // (+actions+proofs), delivery_exceptions, trip_returns, payment_collections.
            $counts['distribution_trips'] = DB::table('distribution_trips')->whereIn('id', $tripIds)->delete();
        }

        $sessionIds = $this->sessionIds($companyId);
        $waveIds = $this->waveIds($sessionIds);

        if ($sessionIds !== []) {
            DB::table('preparation_session_orders')->whereIn('preparation_session_id', $sessionIds)->delete();
        }

        if ($waveIds !== []) {
            DB::table('preparation_wave_orders')->whereIn('preparation_wave_id', $waveIds)->delete();

            $poolIds = DB::table('prepared_products_pool')->whereIn('preparation_wave_id', $waveIds)->pluck('id')->all();
            if ($poolIds !== []) {
                DB::table('prepared_pool_movements')->whereIn('pool_entry_id', $poolIds)->delete();
            }
            DB::table('prepared_products_pool')->whereIn('preparation_wave_id', $waveIds)->delete();
            DB::table('preparation_inventory_reservations')->whereIn('preparation_wave_id', $waveIds)->delete();
        }

        $counts['preparation_waves'] = $waveIds !== [] ? DB::table('preparation_waves')->whereIn('id', $waveIds)->delete() : 0;
        $counts['preparation_sessions'] = $sessionIds !== [] ? DB::table('preparation_sessions')->whereIn('id', $sessionIds)->delete() : 0;

        return $counts;
    }

    /** @return list<string> */
    private function tripIds(string $companyId): array
    {
        return DB::table('distribution_trips')->where('company_id', $companyId)->pluck('id')->all();
    }

    /** @return list<string> */
    private function sessionIds(string $companyId): array
    {
        return DB::table('preparation_sessions')->where('company_id', $companyId)->pluck('id')->all();
    }

    /**
     * @param  list<string>  $sessionIds
     * @return list<string>
     */
    private function waveIds(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        return DB::table('preparation_waves')->whereIn('preparation_session_id', $sessionIds)->pluck('id')->all();
    }

    /** @param  list<string>  $ids */
    private function countByParentIds(string $table, string $column, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table($table)->whereIn($column, $ids)->count();
    }
}
