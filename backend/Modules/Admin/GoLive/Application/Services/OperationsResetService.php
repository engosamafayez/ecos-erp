<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Application\Services;

use Illuminate\Support\Facades\DB;

/**
 * TASK-...-026 §3.C/§4 — Operations domain reset. Extended by TASK-...-026-R1 Gate 3.
 *
 * Covers three tracks: Preparation (sessions/waves), Distribution/Trip (the module that owns
 * "Trip", "Delivery Stop", "Driver custody", "Cash Handover" by name — Task 026's own §3.C
 * examples), and Loading/VehiclePlan (`vehicle_plans` through `vehicle_inventory_movements`
 * below).
 *
 * The Loading/VehiclePlan track was OUT OF SCOPE in Task 026, disclosed as an unresolved
 * "live vs. superseded" question. Task 026-R1 Gate 3 resolved it: `backend/routes/api.php`'s
 * `loading/*` route group is live and permission-gated (`LoadingSessionController`,
 * `VehicleAssignmentController`, `DriverAssignmentController`, `AllocationController`,
 * `ShipmentGroupController`, `VehicleInventoryController`, `VehicleShiftReconciliationController`,
 * `LoadingExceptionController`, plus `GroupLoadingWorkspaceController`'s Group-grain adapter — all
 * of which read/write these exact tables through their Eloquent models). `vehicle_plans` /
 * `vehicle_plan_slots` / `vehicle_plan_slot_orders` specifically are not dead either:
 * `AutoAllocationService::useVehiclePlanSlots()` is a live per-company policy flag that routes
 * `AssignVehicleToSessionAction` through them. So the whole track is classification (A) ACTIVE
 * CURRENT OPERATIONS AUTHORITY, not (B) retired — every table below is included.
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
 *  Loading/VehiclePlan (all 20 tables carry their own `company_id`, so each is filtered directly
 *  rather than plucked through a parent-id chain; every edge below is a real DB `restrictOnDelete`
 *  FK except `loading_task_adjustment_log`, which cascades from `loading_tasks` but is still
 *  deleted explicitly first, matching this service's explicit-raw-delete style elsewhere):
 *    `vehicle_plan_slot_orders`, `vehicle_plan_adjustment_log`, `loading_exceptions`,
 *    `allocation_decisions`, `vehicle_inventory_movements`, `vehicle_shift_reconciliation_lines`,
 *    `route_plan_stops`, `loading_task_adjustment_log`, `shipment_group_items` (all leaves, no
 *    other Loading table references them) → `vehicle_plan_slots`, `allocation_records`,
 *    `route_plans`, `vehicle_shift_reconciliations`, `shipment_groups` (now unblocked) →
 *    `vehicle_plans`, `vehicle_inventory_items`, `driver_assignments` (now unblocked) →
 *    `loading_tasks` (needs `vehicle_inventory_items` gone) → `vehicle_assignments` (needs
 *    `loading_tasks` gone, among others) → `loading_sessions` (the root; needs
 *    `vehicle_assignments` gone, among others).
 *
 * `preparation_session_orders` is cleared here independently of CommerceResetService's own clear
 * of the same table — both are safe: whichever runs, or both, the table ends up empty for the
 * relevant ids; a delete of already-deleted rows is a no-op.
 */
final class OperationsResetService
{
    /**
     * Loading/VehiclePlan tables in FK-safe delete order (leaves first). See class docblock for
     * the edge-by-edge derivation.
     *
     * @var list<string>
     */
    private const LOADING_TABLES_IN_DELETE_ORDER = [
        'vehicle_plan_slot_orders',
        'vehicle_plan_adjustment_log',
        'loading_exceptions',
        'allocation_decisions',
        'vehicle_inventory_movements',
        'vehicle_shift_reconciliation_lines',
        'route_plan_stops',
        'loading_task_adjustment_log',
        'shipment_group_items',
        'vehicle_plan_slots',
        'allocation_records',
        'route_plans',
        'vehicle_shift_reconciliations',
        'shipment_groups',
        'vehicle_plans',
        'vehicle_inventory_items',
        'driver_assignments',
        'loading_tasks',
        'vehicle_assignments',
        'loading_sessions',
    ];

    public function previewCounts(string $companyId): array
    {
        $tripIds = $this->tripIds($companyId);
        $sessionIds = $this->sessionIds($companyId);
        $waveIds = $this->waveIds($sessionIds);

        $counts = [
            'distribution_trips' => count($tripIds),
            'distribution_trip_settlements' => $this->countByParentIds('distribution_trip_settlements', 'trip_id', $tripIds),
            'distribution_trip_cash_handovers' => $this->countByParentIds('distribution_trip_cash_handovers', 'trip_id', $tripIds),
            'preparation_sessions' => count($sessionIds),
            'preparation_waves' => count($waveIds),
        ];

        foreach (self::LOADING_TABLES_IN_DELETE_ORDER as $table) {
            $counts[$table] = DB::table($table)->where('company_id', $companyId)->count();
        }

        return $counts;
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

        foreach (self::LOADING_TABLES_IN_DELETE_ORDER as $table) {
            $counts[$table] = DB::table($table)->where('company_id', $companyId)->delete();
        }

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
