<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use RuntimeException;

class DispatchGateService
{
    public function __construct(private readonly TripAuditService $audit) {}

    /**
     * All trips awaiting dispatch gate processing (loading_completed, driver_accepted, dispatch_blocked).
     */
    public function getGateTrips(int $companyId): array
    {
        $trips = DB::table('distribution_trips as dt')
            ->leftJoin('preparation_waves as pw',    'pw.id',  '=', 'dt.preparation_wave_id')
            ->leftJoin('distribution_zones as dz',   'dz.id',  '=', 'dt.distribution_zone_id')
            ->leftJoin('fleet_drivers as fd',         'fd.id',  '=', 'dt.fleet_driver_id')
            ->leftJoin('fleet_vehicles as fv',        'fv.id',  '=', 'dt.fleet_vehicle_id')
            ->leftJoin('external_carriers as ec',     'ec.id',  '=', 'dt.external_carrier_id')
            ->leftJoin('distribution_loading_manifests as dlm', 'dlm.distribution_trip_id', '=', 'dt.id')
            ->where('dt.company_id', $companyId)
            ->whereIn('dt.status', ['loading_completed', 'driver_accepted', 'dispatch_blocked'])
            ->select([
                'dt.id',
                'dt.trip_number',
                'dt.name',
                'dt.status',
                'dt.type',
                'dt.orders_count',
                'dt.collection_amount',
                'dt.finalized_at',
                'dt.driver_acceptance_at',
                'dt.has_discrepancy',
                DB::raw("COALESCE(fd.name_en, dt.driver_name, 'No Driver') as driver_display"),
                DB::raw("COALESCE(fv.plate_number, '') as vehicle_plate"),
                DB::raw("COALESCE(ec.name, '') as carrier_name"),
                DB::raw("COALESCE(pw.wave_number, '') as wave_number"),
                DB::raw("COALESCE(dz.name_en, '') as zone_name"),
                DB::raw("COALESCE(dz.color, '#64748b') as zone_color"),
                DB::raw("COALESCE(dlm.total_products, 0) as total_products"),
                DB::raw("COALESCE(dlm.confirmed_products, 0) as confirmed_products"),
                DB::raw("COALESCE(dlm.shortage_products, 0) as shortage_products"),
                'dlm.completed_at as loading_completed_at',
            ])
            ->orderByRaw("CASE dt.status WHEN 'dispatch_blocked' THEN 0 WHEN 'driver_accepted' THEN 1 ELSE 2 END")
            ->orderBy('dt.finalized_at', 'desc')
            ->get();

        $stats = [
            'total'              => $trips->count(),
            'loading_completed'  => $trips->where('status', 'loading_completed')->count(),
            'driver_accepted'    => $trips->where('status', 'driver_accepted')->count(),
            'dispatch_blocked'   => $trips->where('status', 'dispatch_blocked')->count(),
        ];

        return [
            'trips' => $trips->map(fn ($t) => [
                'id'                   => $t->id,
                'trip_number'          => $t->trip_number,
                'name'                 => $t->name,
                'status'               => $t->status,
                'type'                 => $t->type,
                'orders_count'         => $t->orders_count,
                'collection_amount'    => (float) $t->collection_amount,
                'driver_display'       => $t->driver_display,
                'vehicle_plate'        => $t->vehicle_plate ?: null,
                'carrier_name'         => $t->carrier_name ?: null,
                'wave_number'          => $t->wave_number,
                'zone_name'            => $t->zone_name,
                'zone_color'           => $t->zone_color,
                'total_products'       => (int) $t->total_products,
                'confirmed_products'   => (int) $t->confirmed_products,
                'shortage_products'    => (int) $t->shortage_products,
                'loading_completed_at' => $t->loading_completed_at,
                'driver_acceptance_at' => $t->driver_acceptance_at,
                'has_discrepancy'      => (bool) $t->has_discrepancy,
            ])->values()->toArray(),
            'stats' => $stats,
        ];
    }

    /**
     * Full trip review — manifest, custody, checklist, audit trail.
     */
    public function getTripReview(string $tripId): array
    {
        $trip = DB::table('distribution_trips as dt')
            ->leftJoin('preparation_waves as pw',   'pw.id',  '=', 'dt.preparation_wave_id')
            ->leftJoin('distribution_zones as dz',  'dz.id',  '=', 'dt.distribution_zone_id')
            ->leftJoin('fleet_drivers as fd',        'fd.id',  '=', 'dt.fleet_driver_id')
            ->leftJoin('fleet_vehicles as fv',       'fv.id',  '=', 'dt.fleet_vehicle_id')
            ->leftJoin('external_carriers as ec',    'ec.id',  '=', 'dt.external_carrier_id')
            ->leftJoin('users as accepting_user',   'accepting_user.id', '=', 'dt.driver_acceptance_by')
            ->where('dt.id', $tripId)
            ->select([
                'dt.id', 'dt.trip_number', 'dt.name', 'dt.status', 'dt.type',
                'dt.orders_count', 'dt.collection_amount', 'dt.finalized_at',
                'dt.driver_accepted_products', 'dt.driver_accepted_custody', 'dt.driver_accepted_equipment',
                'dt.driver_acceptance_at', 'dt.has_discrepancy', 'dt.discrepancy_notes',
                'dt.departure_at', 'dt.odometer_start', 'dt.fuel_level', 'dt.gps_tracking_started',
                DB::raw("COALESCE(fd.name_en, dt.driver_name, 'No Driver') as driver_display"),
                DB::raw("COALESCE(fd.phone, dt.driver_phone) as driver_phone"),
                DB::raw("COALESCE(fv.plate_number, '') as vehicle_plate"),
                DB::raw("COALESCE(fv.make, '') as vehicle_make"),
                DB::raw("COALESCE(fv.model, '') as vehicle_model"),
                DB::raw("COALESCE(ec.name, '') as carrier_name"),
                DB::raw("COALESCE(pw.wave_number, '') as wave_number"),
                DB::raw("COALESCE(dz.name_en, '') as zone_name"),
                DB::raw("COALESCE(dz.color, '#64748b') as zone_color"),
                DB::raw("COALESCE(accepting_user.name, null) as accepting_user_name"),
            ])
            ->first();

        if (!$trip) {
            throw new RuntimeException('Trip not found.');
        }

        // Manifest summary + items
        $manifest = DB::table('distribution_loading_manifests')
            ->where('distribution_trip_id', $tripId)
            ->first();

        $manifestData = null;
        if ($manifest) {
            $items = DB::table('distribution_loading_manifest_items')
                ->where('loading_manifest_id', $manifest->id)
                ->select(['id', 'product_name', 'product_sku', 'required_qty', 'loaded_qty', 'shortage_qty', 'status'])
                ->get();

            $manifestData = [
                'id'                 => $manifest->id,
                'status'             => $manifest->status,
                'total_products'     => (int) $manifest->total_products,
                'confirmed_products' => (int) $manifest->confirmed_products,
                'shortage_products'  => (int) $manifest->shortage_products,
                'completed_at'       => $manifest->completed_at,
                'items'              => $items->map(fn ($i) => [
                    'id'           => $i->id,
                    'product_name' => $i->product_name,
                    'product_sku'  => $i->product_sku,
                    'required_qty' => (float) $i->required_qty,
                    'loaded_qty'   => $i->loaded_qty !== null ? (float) $i->loaded_qty : null,
                    'shortage_qty' => $i->shortage_qty !== null ? (float) $i->shortage_qty : null,
                    'status'       => $i->status,
                ])->values()->toArray(),
            ];
        }

        // Custody summary
        $custodyRows = DB::table('distribution_trip_custody')
            ->where('distribution_trip_id', $tripId)
            ->select(['id', 'item_type', 'label', 'quantity', 'is_driver_confirmed', 'received_quantity'])
            ->get();

        $custodyData = [
            'total'     => $custodyRows->count(),
            'confirmed' => $custodyRows->where('is_driver_confirmed', true)->count(),
            'items'     => $custodyRows->map(fn ($c) => [
                'id'                  => $c->id,
                'item_type'           => $c->item_type,
                'label'               => $c->label,
                'quantity'            => (int) $c->quantity,
                'received_quantity'   => $c->received_quantity !== null ? (int) $c->received_quantity : null,
                'is_driver_confirmed' => (bool) $c->is_driver_confirmed,
            ])->values()->toArray(),
        ];

        // 6-condition dispatch checklist
        $noShortages      = $manifestData === null || $manifestData['shortage_products'] === 0;
        $noDiscrepancies  = !(bool) $trip->has_discrepancy;
        $checklist = [
            'loading_completed'             => $manifestData !== null && $manifestData['status'] === 'completed',
            'driver_accepted_products'      => (bool) $trip->driver_accepted_products,
            'driver_accepted_custody'       => (bool) $trip->driver_accepted_custody,
            'driver_accepted_equipment'     => (bool) $trip->driver_accepted_equipment,
            'no_outstanding_shortages'      => $noShortages,
            'no_outstanding_discrepancies'  => $noDiscrepancies,
        ];
        $checklist['can_dispatch'] = !in_array(false, $checklist, true);

        $auditTrail = $this->audit->getTrail($tripId);

        return [
            'trip' => [
                'id'                        => $trip->id,
                'trip_number'               => $trip->trip_number,
                'name'                      => $trip->name,
                'status'                    => $trip->status,
                'type'                      => $trip->type,
                'orders_count'              => $trip->orders_count,
                'collection_amount'         => (float) $trip->collection_amount,
                'driver_display'            => $trip->driver_display,
                'driver_phone'              => $trip->driver_phone,
                'vehicle_plate'             => $trip->vehicle_plate ?: null,
                'vehicle_make'              => $trip->vehicle_make ?: null,
                'vehicle_model'             => $trip->vehicle_model ?: null,
                'carrier_name'              => $trip->carrier_name ?: null,
                'wave_number'               => $trip->wave_number,
                'zone_name'                 => $trip->zone_name,
                'zone_color'                => $trip->zone_color,
                'finalized_at'              => $trip->finalized_at,
                'driver_accepted_products'  => (bool) $trip->driver_accepted_products,
                'driver_accepted_custody'   => (bool) $trip->driver_accepted_custody,
                'driver_accepted_equipment' => (bool) $trip->driver_accepted_equipment,
                'driver_acceptance_at'      => $trip->driver_acceptance_at,
                'accepting_user_name'       => $trip->accepting_user_name,
                'has_discrepancy'           => (bool) $trip->has_discrepancy,
                'discrepancy_notes'         => $trip->discrepancy_notes,
                'departure_at'              => $trip->departure_at,
                'odometer_start'            => $trip->odometer_start,
                'fuel_level'                => $trip->fuel_level,
                'gps_tracking_started'      => (bool) $trip->gps_tracking_started,
            ],
            'manifest_summary' => $manifestData,
            'custody_summary'  => $custodyData,
            'checklist'        => $checklist,
            'audit_trail'      => $auditTrail,
        ];
    }

    /**
     * Dispatch the vehicle — final step before out for delivery.
     */
    public function dispatchVehicle(
        DistributionTrip $trip,
        ?int    $odometerStart,
        ?float  $fuelLevel,
        ?string $notes,
        int     $userId,
    ): DistributionTrip {
        if ($trip->status !== 'driver_accepted') {
            throw new RuntimeException('Trip must be in Driver Accepted status to dispatch the vehicle.');
        }

        if ($trip->has_discrepancy) {
            throw new RuntimeException('Trip has an outstanding discrepancy. Resolve it before dispatching.');
        }

        $fromStatus = $trip->status;

        DB::transaction(function () use ($trip, $odometerStart, $fuelLevel, $userId, $notes, $fromStatus): void {
            $trip->update([
                'status'                  => 'out_for_delivery',
                'departure_at'            => now(),
                'departure_by'            => $userId,
                'odometer_start'          => $odometerStart,
                'fuel_level'              => $fuelLevel,
                'gps_tracking_started'    => true,
                'gps_tracking_started_at' => now(),
            ]);

            $this->audit->record(
                $trip->id,
                'vehicle_dispatched',
                $fromStatus,
                'out_for_delivery',
                $userId,
                $notes,
                array_filter(['odometer_start' => $odometerStart, 'fuel_level' => $fuelLevel]),
            );
        });

        return $trip->fresh();
    }
}
