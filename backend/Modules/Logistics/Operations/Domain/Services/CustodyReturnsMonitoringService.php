<?php

declare(strict_types=1);

namespace Modules\Logistics\Operations\Domain\Services;

use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripReturn;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliationLine;

/**
 * TASK-ECOS-V1.1-OPS-04-TASK1 — custody reconciliation + returns visibility +
 * the bounded Expected Driver Returns read model, all composed from the
 * canonical Loading/Custody/Return authorities. No new custody or return
 * engine; no order-status arithmetic anywhere in this class.
 */
class CustodyReturnsMonitoringService
{
    /**
     * Physical goods custody, company-wide. Every figure reads a real
     * VehicleInventoryItem/VehicleShiftReconciliationLine column — "remaining"
     * is quantity_on_hand itself, never (loaded - delivered) or any other
     * derived arithmetic over order/delivery status (Section 6's explicit
     * invariant).
     *
     * @return array<string, mixed>
     */
    public function custody(?string $companyId = null): array
    {
        $totals = VehicleInventoryItem::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('
                COALESCE(SUM(quantity_loaded), 0) as loaded,
                COALESCE(SUM(quantity_delivered), 0) as delivered,
                COALESCE(SUM(quantity_on_hand), 0) as remaining_with_driver_vehicle,
                COALESCE(SUM(quantity_returned), 0) as returned
            ')
            ->first();

        $received = VehicleShiftReconciliationLine::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('warehouse_receipt_at')
            ->selectRaw('
                COALESCE(SUM(quantity_accepted), 0) as accepted,
                COALESCE(SUM(quantity_damaged), 0) as damaged
            ')
            ->first();

        $awaitingReceiptLines = VehicleShiftReconciliationLine::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('warehouse_receipt_at')
            ->count();

        // A trip-return line (TripReturn) that has been warehouse-confirmed
        // carries its OWN discrepancy figure — a different, courier-return-
        // level fact from the product-level VehicleShiftReconciliationLine
        // figures above. Exposed separately, never merged into one number.
        $tripReturnDiscrepancy = TripReturn::query()
            ->when($companyId !== null, fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('company_id', $companyId)))
            ->whereNotNull('warehouse_confirmed_qty')
            ->selectRaw('COALESCE(SUM(ABS(discrepancy_qty)), 0) as total')
            ->value('total');

        return [
            'loaded' => (float) $totals->loaded,
            'delivered' => (float) $totals->delivered,
            'remaining_with_driver_vehicle' => (float) $totals->remaining_with_driver_vehicle,
            'returned_by_driver' => (float) $totals->returned,
            'received_by_warehouse_accepted' => (float) $received->accepted,
            'received_by_warehouse_damaged' => (float) $received->damaged,
            'awaiting_warehouse_receipt_lines' => $awaitingReceiptLines,
            'trip_return_discrepancy_qty' => (float) $tripReturnDiscrepancy,
        ];
    }

    /**
     * Returns visibility — distinguishing delivery-outcome-Returned (a stop
     * that will not complete this attempt) from physical-return-confirmed
     * (TripReturn.warehouse_confirmed_qty set) from actual warehouse receipt
     * (VehicleShiftReconciliationLine.warehouse_receipt_at set). Section 7's
     * invariant: none of these implies Inventory has moved — that remains
     * exclusively ReceiveVehicleReturnAction's own authority, never invoked
     * from here.
     *
     * @return array<string, mixed>
     */
    public function returns(?string $companyId = null): array
    {
        $tripReturns = TripReturn::query()
            ->when($companyId !== null, fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('company_id', $companyId)));

        return [
            'physical_return_confirmed' => (clone $tripReturns)->whereNotNull('warehouse_confirmed_qty')->count(),
            'physical_return_awaiting_confirmation' => (clone $tripReturns)->whereNull('warehouse_confirmed_qty')->count(),
            'driver_liable_discrepancies' => (clone $tripReturns)->where('driver_liable', true)->count(),
            'warehouse_receipt_completed_lines' => VehicleShiftReconciliationLine::query()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->whereNotNull('warehouse_receipt_at')
                ->count(),
            'warehouse_receipt_awaiting_lines' => VehicleShiftReconciliationLine::query()
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->whereNull('warehouse_receipt_at')
                ->count(),
        ];
    }

    /**
     * The bounded Expected Driver Returns read model (Section 5) — one row
     * per VehicleInventoryItem still holding real, positive on-hand custody
     * (never inferred from Order status). A row with no reconciliation line
     * yet is reported as INCOMPLETE LINKAGE ('awaiting_reconciliation'),
     * distinct from a line that exists and genuinely shows zero damage/
     * acceptance — the two are never conflated into the same "0".
     *
     * Trip/driver/vehicle context resolves through the LIVE canonical pairing
     * (Trip.driverVehicleAssignment.driver/vehicle) — NOT
     * VehicleShiftReconciliation.driverAssignment, which points at
     * Operations\Loading\DriverAssignment, confirmed dead for the live
     * Group/Trip flow in the OPS-01/OPS-02 reconciliation (zero rows written
     * for real sessions). Batch-loaded per page to avoid one Trip query per row.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function expectedReturns(?string $companyId, int $perPage = 25, int $page = 1): array
    {
        $query = VehicleInventoryItem::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('quantity_on_hand', '>', 0)
            ->with(['vehicleAssignment:id,trip_id,vehicle_id', 'reconciliationLines'])
            ->orderByDesc('last_movement_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', max(1, $page));

        /** @var \Illuminate\Support\Collection<int, VehicleInventoryItem> $items */
        $items = $paginator->getCollection();

        $tripIds = $items->map(fn (VehicleInventoryItem $i) => $i->vehicleAssignment?->trip_id)->filter()->unique()->values();

        $trips = Trip::query()
            ->whereIn('id', $tripIds)
            ->with(['driverVehicleAssignment.driver:id,full_name,driver_code', 'driverVehicleAssignment.vehicle:id,plate_number'])
            ->get()
            ->keyBy('id');

        $rows = $items->map(function (VehicleInventoryItem $item) use ($trips) {
            $trip = $item->vehicleAssignment?->trip_id !== null ? $trips->get($item->vehicleAssignment->trip_id) : null;
            $driver = $trip?->driverVehicleAssignment?->driver;
            $vehicle = $trip?->driverVehicleAssignment?->vehicle;

            // The most recent reconciliation attempt for this exact product/
            // vehicle-assignment, if any has been recorded yet.
            $line = $item->reconciliationLines->sortByDesc('created_at')->first();
            $hasLine = $line !== null;
            $received = $hasLine && $line->warehouse_receipt_at !== null;

            return [
                'vehicle_inventory_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->name_snapshot,
                'trip' => $trip !== null ? ['id' => $trip->id, 'uuid' => $trip->uuid, 'trip_number' => $trip->trip_number, 'status' => $trip->status->value] : null,
                'driver' => $driver !== null ? ['id' => $driver->id, 'full_name' => $driver->full_name, 'driver_code' => $driver->driver_code] : null,
                'vehicle' => $vehicle !== null ? ['id' => $vehicle->id, 'plate_number' => $vehicle->plate_number] : null,
                'expected_qty' => (float) $item->quantity_on_hand,
                // null (not 0) whenever no reconciliation line exists yet — the
                // honest "not yet processed" state Section 5 requires.
                'accepted_qty' => $hasLine ? (float) $line->quantity_accepted : null,
                'damaged_qty' => $hasLine ? (float) $line->quantity_damaged : null,
                'linkage_state' => ! $hasLine ? 'awaiting_reconciliation' : ($received ? 'received' : 'reconciled_not_yet_received'),
                'received_at' => $received ? $line->warehouse_receipt_at->toIso8601String() : null,
                'last_movement_at' => $item->last_movement_at?->toIso8601String(),
            ];
        })->all();

        return [
            'data' => array_values($rows),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }
}
