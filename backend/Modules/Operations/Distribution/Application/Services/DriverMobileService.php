<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryStop;
use Modules\Operations\Distribution\Domain\Models\DriverGpsWaypoint;
use RuntimeException;

class DriverMobileService
{
    public function __construct(
        private readonly TripAuditService $audit,
    ) {}

    /**
     * Get all out_for_delivery + in_progress trips for a company (driver view).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getActiveTrips(int $companyId): array
    {
        return DB::table('distribution_trips as dt')
            ->leftJoin('preparation_waves as pw',  'pw.id', '=', 'dt.preparation_wave_id')
            ->leftJoin('distribution_zones as dz',  'dz.id', '=', 'dt.distribution_zone_id')
            ->leftJoin('fleet_drivers as fd',        'fd.id', '=', 'dt.fleet_driver_id')
            ->leftJoin('fleet_vehicles as fv',       'fv.id', '=', 'dt.fleet_vehicle_id')
            ->where('dt.company_id', $companyId)
            ->whereIn('dt.status', ['out_for_delivery', 'in_progress'])
            ->select([
                'dt.id',
                'dt.trip_number',
                'dt.name',
                'dt.type',
                'dt.status',
                'dt.orders_count',
                'dt.collection_amount',
                'dt.departure_at',
                'dt.trip_started_at',
                'dt.trip_finished_at',
                'dt.total_cash_collected',
                'dt.total_bank_transfers',
                'dt.total_already_paid',
                DB::raw("COALESCE(dz.zone_code, '') as zone_code"),
                DB::raw("COALESCE(pw.wave_number, '') as wave_number"),
                DB::raw("COALESCE(fd.name_en, dt.driver_name, '') as driver_name"),
                DB::raw("COALESCE(fv.plate_number, '') as vehicle_plate"),
            ])
            ->orderBy('dt.departure_at', 'asc')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /**
     * Get full trip data for driver dashboard.
     *
     * @return array<string,mixed>
     */
    public function getTripDashboard(DistributionTrip $trip): array
    {
        $kpis = DB::table('driver_delivery_stops')
            ->where('distribution_trip_id', $trip->id)
            ->select([
                DB::raw('COUNT(*) as total_orders'),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END)   as pending"),
                DB::raw("SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered"),
                DB::raw("SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END)   as partial"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END)    as failed"),
                DB::raw("SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END)  as returned"),
                DB::raw('COALESCE(SUM(collected_amount), 0)                     as total_collections'),
            ])
            ->first();

        $remaining = (int) ($kpis->pending ?? 0);

        return [
            'id'                   => $trip->id,
            'trip_number'          => $trip->trip_number,
            'name'                 => $trip->name,
            'type'                 => $trip->type,
            'status'               => $trip->status,
            'orders_count'         => $trip->orders_count,
            'collection_amount'    => $trip->collection_amount,
            'zone_code'            => null,
            'wave_number'          => null,
            'driver_name'          => $trip->driver_name,
            'vehicle_plate'        => null,
            'departure_at'         => $trip->departure_at,
            'trip_started_at'      => $trip->trip_started_at,
            'trip_finished_at'     => $trip->trip_finished_at,
            'total_cash_collected' => $trip->total_cash_collected,
            'total_bank_transfers' => $trip->total_bank_transfers,
            'total_already_paid'   => $trip->total_already_paid,
            'kpis'                 => [
                'total_orders'      => (int) ($kpis->total_orders ?? 0),
                'pending'           => (int) ($kpis->pending ?? 0),
                'delivered'         => (int) ($kpis->delivered ?? 0),
                'partial'           => (int) ($kpis->partial ?? 0),
                'failed'            => (int) ($kpis->failed ?? 0),
                'returned'          => (int) ($kpis->returned ?? 0),
                'total_collections' => (float) ($kpis->total_collections ?? 0),
                'remaining_stops'   => $remaining,
            ],
        ];
    }

    /**
     * Initialize delivery stops for a trip (one per trip order).
     */
    public function initializeStops(DistributionTrip $trip, int $userId): void
    {
        // Check if stops already initialized
        $existing = DB::table('driver_delivery_stops')
            ->where('distribution_trip_id', $trip->id)
            ->count();

        if ($existing > 0) {
            return;
        }

        $orders = DB::table('distribution_trip_orders as dto')
            ->join('orders as o', 'o.id', '=', 'dto.order_id')
            ->where('dto.distribution_trip_id', $trip->id)
            ->orderBy('dto.sequence', 'asc')
            ->select(['dto.order_id', 'dto.sequence'])
            ->get();

        $seq = 1;
        foreach ($orders as $row) {
            DB::table('driver_delivery_stops')->insert([
                'id'                   => DB::raw('gen_random_uuid()'),
                'distribution_trip_id' => $trip->id,
                'order_id'             => $row->order_id,
                'sequence'             => $row->sequence ?? $seq,
                'status'               => 'pending',
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
            $seq++;
        }
    }

    /**
     * Start trip: status → in_progress, record GPS + time.
     */
    public function startTrip(
        DistributionTrip $trip,
        float            $lat,
        float            $lng,
        int              $userId,
        ?int             $odoStart = null,
    ): DistributionTrip {
        if ($trip->status !== 'out_for_delivery') {
            throw new RuntimeException("Trip must be out_for_delivery to start. Current: {$trip->status}");
        }

        $trip->update([
            'status'          => 'in_progress',
            'trip_started_at' => now(),
            'trip_start_lat'  => $lat,
            'trip_start_lng'  => $lng,
        ]);

        // Initialize stops if not done yet
        $this->initializeStops($trip, $userId);

        // Record GPS waypoint
        DriverGpsWaypoint::create([
            'distribution_trip_id' => $trip->id,
            'lat'                  => $lat,
            'lng'                  => $lng,
            'recorded_at'          => now(),
        ]);

        $this->audit->record(
            tripId:      $trip->id,
            action:      'trip_started',
            fromStatus:  'out_for_delivery',
            toStatus:    'in_progress',
            performedBy: $userId,
            notes:       "Trip started at ({$lat}, {$lng})",
        );

        return $trip->fresh();
    }

    /**
     * Finish trip: validate all stops processed, status → completed.
     */
    public function finishTrip(
        DistributionTrip $trip,
        float            $lat,
        float            $lng,
        int              $userId,
        ?int             $odoEnd = null,
    ): DistributionTrip {
        if ($trip->status !== 'in_progress') {
            throw new RuntimeException("Trip must be in_progress to finish. Current: {$trip->status}");
        }

        $pendingCount = DB::table('driver_delivery_stops')
            ->where('distribution_trip_id', $trip->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        if ($pendingCount > 0) {
            throw new RuntimeException("Cannot finish trip: {$pendingCount} stops are still pending or in progress.");
        }

        $trip->update([
            'status'           => 'completed',
            'trip_finished_at' => now(),
            'trip_finish_lat'  => $lat,
            'trip_finish_lng'  => $lng,
            'odometer_end'     => $odoEnd,
        ]);

        DriverGpsWaypoint::create([
            'distribution_trip_id' => $trip->id,
            'lat'                  => $lat,
            'lng'                  => $lng,
            'recorded_at'          => now(),
        ]);

        $this->audit->record(
            tripId:      $trip->id,
            action:      'trip_finished',
            fromStatus:  'in_progress',
            toStatus:    'completed',
            performedBy: $userId,
            notes:       "Trip finished at ({$lat}, {$lng})",
        );

        return $trip->fresh();
    }

    /**
     * Get stop list for a trip with order details.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getStopList(DistributionTrip $trip): array
    {
        return DB::table('driver_delivery_stops as s')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->leftJoin('governorates as gov', 'gov.id', '=', 'o.governorate_id')
            ->leftJoin('cities as cit',       'cit.id', '=', 'o.city_id')
            ->where('s.distribution_trip_id', $trip->id)
            ->orderBy('s.sequence', 'asc')
            ->select([
                's.id',
                's.sequence',
                's.status',
                's.delivery_type',
                's.collected_amount',
                's.payment_method',
                's.attempted_at',
                's.completed_at',
                's.notes',
                'o.id as order_id',
                DB::raw("COALESCE(o.order_number, CAST(o.id AS TEXT)) as order_number"),
                DB::raw("COALESCE(o.billing_name, o.customer_name, '') as customer_name"),
                DB::raw("COALESCE(o.billing_phone, '') as billing_phone"),
                DB::raw("COALESCE(o.shipping_address, o.delivery_address, '') as shipping_address"),
                DB::raw("COALESCE(gov.name_ar, '') as governorate"),
                DB::raw("COALESCE(cit.name_ar, '') as city"),
                DB::raw("COALESCE(o.payment_method, '') as payment_method_order"),
                DB::raw("COALESCE(o.grand_total, 0) as grand_total"),
                DB::raw("COALESCE(o.deposit_paid, 0) as deposit_paid"),
                DB::raw("COALESCE(o.delivery_notes, '') as delivery_notes"),
            ])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();
    }

    /**
     * Get full order detail for a stop.
     *
     * @return array<string,mixed>
     */
    public function getStopDetail(DriverDeliveryStop $stop): array
    {
        $order = DB::table('orders as o')
            ->leftJoin('governorates as gov', 'gov.id', '=', 'o.governorate_id')
            ->leftJoin('cities as cit',       'cit.id', '=', 'o.city_id')
            ->where('o.id', $stop->order_id)
            ->select([
                'o.id',
                DB::raw("COALESCE(o.order_number, CAST(o.id AS TEXT)) as order_number"),
                DB::raw("COALESCE(o.billing_name, o.customer_name, '') as customer_name"),
                DB::raw("COALESCE(o.billing_phone, '') as billing_phone"),
                DB::raw("COALESCE(o.shipping_address, o.delivery_address, '') as shipping_address"),
                DB::raw("COALESCE(gov.name_ar, '') as governorate"),
                DB::raw("COALESCE(cit.name_ar, '') as city"),
                'o.area',
                DB::raw("COALESCE(o.payment_method, '') as payment_method"),
                DB::raw("COALESCE(o.grand_total, 0) as grand_total"),
                DB::raw("COALESCE(o.deposit_paid, 0) as deposit_paid"),
                DB::raw("COALESCE(o.grand_total, 0) - COALESCE(o.deposit_paid, 0) as remaining_balance"),
                DB::raw("COALESCE(o.delivery_notes, '') as delivery_notes"),
            ])
            ->first();

        $lines = DB::table('order_lines as ol')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->where('ol.order_id', $stop->order_id)
            ->select([
                'ol.product_id',
                DB::raw("COALESCE(p.name, '') as product_name"),
                DB::raw("COALESCE(p.sku, '') as product_sku"),
                'ol.quantity',
                DB::raw("COALESCE(ol.unit_price, 0) as unit_price"),
                DB::raw("COALESCE(ol.total_price, ol.quantity * ol.unit_price, 0) as line_total"),
            ])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        $collections = DB::table('driver_payment_collections')
            ->where('stop_id', $stop->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->toArray();

        $proof = DB::table('driver_delivery_proofs')
            ->where('stop_id', $stop->id)
            ->first();

        return [
            'id'             => $stop->id,
            'sequence'       => $stop->sequence,
            'status'         => $stop->status,
            'delivery_type'  => $stop->delivery_type,
            'collected_amount' => $stop->collected_amount,
            'payment_method' => $stop->payment_method,
            'attempted_at'   => $stop->attempted_at,
            'completed_at'   => $stop->completed_at,
            'notes'          => $stop->notes,
            'order'          => array_merge(
                (array) $order,
                ['lines' => $lines]
            ),
            'collections'    => $collections,
            'proof'          => $proof ? (array) $proof : null,
        ];
    }

    /**
     * Record a GPS waypoint for an in-progress trip.
     */
    public function recordGps(
        DistributionTrip $trip,
        float            $lat,
        float            $lng,
        ?float           $speed    = null,
        ?float           $accuracy = null,
    ): DriverGpsWaypoint {
        return DriverGpsWaypoint::create([
            'distribution_trip_id' => $trip->id,
            'lat'                  => $lat,
            'lng'                  => $lng,
            'speed'                => $speed,
            'accuracy'             => $accuracy,
            'recorded_at'          => now(),
        ]);
    }

    /**
     * Build chronological trip timeline.
     *
     * @return array<string,mixed>
     */
    public function getTimeline(DistributionTrip $trip): array
    {
        $events = [];

        if ($trip->trip_started_at) {
            $events[] = [
                'type'          => 'trip_started',
                'label'         => 'Trip Started',
                'stop_sequence' => null,
                'order_number'  => null,
                'timestamp'     => $trip->trip_started_at,
                'notes'         => null,
            ];
        }

        $stops = DB::table('driver_delivery_stops as s')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('s.distribution_trip_id', $trip->id)
            ->whereNotNull('s.completed_at')
            ->orderBy('s.completed_at', 'asc')
            ->select([
                's.sequence',
                's.status',
                's.delivery_type',
                's.completed_at',
                's.notes',
                DB::raw("COALESCE(o.order_number, CAST(o.id AS TEXT)) as order_number"),
            ])
            ->get();

        foreach ($stops as $stop) {
            $typeMap = [
                'delivered' => 'stop_completed',
                'partial'   => 'stop_partial',
                'failed'    => 'stop_failed',
                'returned'  => 'stop_returned',
            ];

            $events[] = [
                'type'          => $typeMap[$stop->status] ?? 'stop_completed',
                'label'         => ucfirst($stop->status) . " — Order #{$stop->order_number}",
                'stop_sequence' => $stop->sequence,
                'order_number'  => $stop->order_number,
                'timestamp'     => $stop->completed_at,
                'notes'         => $stop->notes,
            ];
        }

        if ($trip->trip_finished_at) {
            $events[] = [
                'type'          => 'trip_finished',
                'label'         => 'Trip Finished',
                'stop_sequence' => null,
                'order_number'  => null,
                'timestamp'     => $trip->trip_finished_at,
                'notes'         => null,
            ];
        }

        return ['events' => $events];
    }
}
