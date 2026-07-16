<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifest;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DistributionTripCustody;
use Modules\Operations\Distribution\Domain\Models\DistributionTripOrder;
use Modules\Operations\Distribution\Domain\Models\DistributionWaveException;
use RuntimeException;

class TripManagementService
{
    public function __construct(
        private readonly DistributionBoardService $boardService,
        private readonly ManifestGenerationService $manifestService,
    ) {}

    public function createTrip(array $data, int $userId): DistributionTrip
    {
        return DB::transaction(function () use ($data, $userId) {
            $tripNumber = $this->boardService->nextTripNumber($data['preparation_wave_id']);

            return DistributionTrip::create([
                'company_id'          => $data['company_id'],
                'preparation_wave_id' => $data['preparation_wave_id'],
                'distribution_zone_id' => $data['distribution_zone_id'] ?? null,
                'trip_number'         => $tripNumber,
                'name'                => $data['name'] ?? $tripNumber,
                'type'                => $data['type'] ?? 'company_vehicle',
                'capacity'            => $data['capacity'] ?? 60,
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $userId,
            ]);
        });
    }

    public function updateTrip(DistributionTrip $trip, array $data): DistributionTrip
    {
        $this->assertPlanning($trip);

        $trip->update(array_filter([
            'name'     => $data['name'] ?? null,
            'type'     => $data['type'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'notes'    => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));

        return $trip->fresh();
    }

    public function deleteTrip(DistributionTrip $trip): void
    {
        $this->assertPlanning($trip);

        DB::transaction(function () use ($trip) {
            DistributionTripOrder::where('distribution_trip_id', $trip->id)->delete();
            DistributionTripCustody::where('distribution_trip_id', $trip->id)->delete();
            $trip->delete();
        });
    }

    /**
     * Auto-fill: assign the next N unassigned zone orders to the trip (FIFO).
     */
    public function autoFill(DistributionTrip $trip, int $userId): int
    {
        $this->assertPlanning($trip);

        $remaining = $trip->capacity - $trip->orders_count;
        if ($remaining <= 0) {
            throw new RuntimeException('Trip is at full capacity.');
        }

        if (!$trip->distribution_zone_id) {
            throw new RuntimeException('Trip must be assigned to a zone before auto-fill.');
        }

        $unassigned = $this->boardService->getUnassignedZoneOrders(
            $trip->preparation_wave_id,
            $trip->distribution_zone_id,
        )->take($remaining);

        if ($unassigned->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($trip, $unassigned, $userId) {
            $rows = $unassigned->map(fn ($o) => [
                'distribution_trip_id' => $trip->id,
                'order_id'             => $o->order_id,
                'zone_code_snapshot'   => $o->zone_code_snapshot,
                'governorate_snapshot' => $o->governorate_name,
                'assignment_type'      => 'auto',
                'assigned_by'          => $userId,
                'assigned_at'          => now(),
            ])->toArray();

            DistributionTripOrder::insert($rows);
            $this->syncTripCounters($trip);

            return count($rows);
        });
    }

    public function addOrder(DistributionTrip $trip, string $orderId, int $userId): void
    {
        $this->assertPlanning($trip);

        if ($trip->orders_count >= $trip->capacity) {
            throw new RuntimeException('Trip is at full capacity.');
        }

        if (DistributionTripOrder::where('order_id', $orderId)->exists()) {
            throw new RuntimeException('Order is already assigned to a trip.');
        }

        $orderInfo = DB::table('orders as o')
            ->join('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->join('logistics_governorates as lg', 'lg.id', '=', 'lc.governorate_id')
            ->where('o.id', $orderId)
            ->select('lc.distribution_zone_id', 'lg.name_en as governorate_name')
            ->first();

        DB::transaction(function () use ($trip, $orderId, $orderInfo, $userId) {
            DistributionTripOrder::create([
                'distribution_trip_id' => $trip->id,
                'order_id'             => $orderId,
                'governorate_snapshot' => $orderInfo?->governorate_name,
                'assignment_type'      => 'manual',
                'assigned_by'          => $userId,
                'assigned_at'          => now(),
            ]);

            $this->syncTripCounters($trip);
        });
    }

    public function removeOrder(DistributionTrip $trip, string $orderId): void
    {
        $this->assertPlanning($trip);

        DB::transaction(function () use ($trip, $orderId) {
            DistributionTripOrder::where('distribution_trip_id', $trip->id)
                ->where('order_id', $orderId)
                ->delete();

            $this->syncTripCounters($trip);
        });
    }

    public function moveOrder(DistributionTrip $fromTrip, DistributionTrip $toTrip, string $orderId, int $userId): void
    {
        $this->assertPlanning($fromTrip);
        $this->assertPlanning($toTrip);

        if ($toTrip->orders_count >= $toTrip->capacity) {
            throw new RuntimeException('Destination trip is at full capacity.');
        }

        DB::transaction(function () use ($fromTrip, $toTrip, $orderId, $userId) {
            DistributionTripOrder::where('distribution_trip_id', $fromTrip->id)
                ->where('order_id', $orderId)
                ->update([
                    'distribution_trip_id' => $toTrip->id,
                    'assignment_type'      => 'manual',
                    'assigned_by'          => $userId,
                    'assigned_at'          => now(),
                ]);

            $this->syncTripCounters($fromTrip);
            $this->syncTripCounters($toTrip);
        });
    }

    public function assignDriver(DistributionTrip $trip, ?int $driverId, ?string $driverName, ?string $driverPhone): DistributionTrip
    {
        $this->assertPlanning($trip);

        $trip->update([
            'fleet_driver_id' => $driverId,
            'driver_name'     => $driverName,
            'driver_phone'    => $driverPhone,
        ]);

        return $trip->fresh(['driver']);
    }

    public function assignVehicle(DistributionTrip $trip, ?int $vehicleId): DistributionTrip
    {
        $this->assertPlanning($trip);
        $trip->update(['fleet_vehicle_id' => $vehicleId]);
        return $trip->fresh(['vehicle']);
    }

    public function assignCarrier(DistributionTrip $trip, ?int $carrierId): DistributionTrip
    {
        $this->assertPlanning($trip);
        $trip->update(['external_carrier_id' => $carrierId]);
        return $trip->fresh(['carrier']);
    }

    public function addCustodyItem(DistributionTrip $trip, array $data, int $userId): DistributionTripCustody
    {
        return DistributionTripCustody::create([
            'distribution_trip_id' => $trip->id,
            'item_type'            => $data['item_type'],
            'description'          => $data['description'] ?? null,
            'quantity'             => $data['quantity'] ?? 1,
            'notes'                => $data['notes'] ?? null,
            'created_by'           => $userId,
            'created_at'           => now(),
        ]);
    }

    public function removeCustodyItem(DistributionTripCustody $item): void
    {
        $item->delete();
    }

    /**
     * Recompute orders_count and collection_amount from live data.
     */
    private function syncTripCounters(DistributionTrip $trip): void
    {
        $stats = DB::table('distribution_trip_orders as dto')
            ->join('orders as o', 'o.id', '=', 'dto.order_id')
            ->where('dto.distribution_trip_id', $trip->id)
            ->selectRaw('count(*) as cnt, coalesce(sum(o.grand_total), 0) as total')
            ->first();

        $trip->update([
            'orders_count'      => (int) $stats->cnt,
            'collection_amount' => (float) $stats->total,
        ]);
    }

    /**
     * Approve trip for loading: validate resources, generate manifest, set status='loading'.
     */
    public function approveTrip(DistributionTrip $trip, int $userId): DistributionLoadingManifest
    {
        $this->assertPlanning($trip);

        if (!$trip->is_ready_for_loading) {
            throw new RuntimeException("Trip {$trip->trip_number} is not ready: assign driver and vehicle/carrier first.");
        }

        return DB::transaction(function () use ($trip, $userId) {
            $manifest = $this->manifestService->generate($trip, $userId);

            $trip->update([
                'status'       => 'loading',
                'finalized_at' => now(),
                'finalized_by' => $userId,
            ]);

            return $manifest;
        });
    }

    /**
     * Return an order to the wave exception list (unassign from its trip).
     */
    public function returnToWave(
        DistributionTrip $trip,
        string $orderId,
        int $userId,
        ?string $reason = null,
        ?string $notes = null
    ): void {
        $this->assertPlanning($trip);

        DB::transaction(function () use ($trip, $orderId, $userId, $reason, $notes) {
            DistributionTripOrder::where('distribution_trip_id', $trip->id)
                ->where('order_id', $orderId)
                ->delete();

            $this->syncTripCounters($trip);

            DistributionWaveException::create([
                'preparation_wave_id'  => $trip->preparation_wave_id,
                'order_id'             => $orderId,
                'distribution_trip_id' => $trip->id,
                'reason'               => $reason ?? 'supervisor_return',
                'notes'                => $notes,
                'returned_by'          => $userId,
                'returned_at'          => now(),
            ]);
        });
    }

    private function assertPlanning(DistributionTrip $trip): void
    {
        if ($trip->status !== 'planning') {
            throw new RuntimeException("Trip {$trip->trip_number} is no longer in planning status.");
        }
    }
}
