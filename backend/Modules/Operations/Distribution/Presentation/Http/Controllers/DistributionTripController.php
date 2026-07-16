<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Application\Services\DistributionBoardService;
use Modules\Operations\Distribution\Application\Services\TripManagementService;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifest;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DistributionTripCustody;
use Modules\Operations\Distribution\Presentation\Http\Resources\DistributionTripResource;
use RuntimeException;

class DistributionTripController extends Controller
{
    public function __construct(
        private readonly TripManagementService $tripService,
        private readonly DistributionBoardService $boardService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preparation_wave_id'  => ['required', 'uuid'],
            'distribution_zone_id' => ['nullable', 'integer'],
            'name'                 => ['nullable', 'string', 'max:100'],
            'type'                 => ['required', 'in:company_vehicle,personal_vehicle,external_carrier'],
            'capacity'             => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes'                => ['nullable', 'string', 'max:1000'],
        ]);

        $data['company_id'] = $request->user()->company_id;
        $trip = $this->tripService->createTrip($data, $request->user()->id);

        return response()->json(
            (new DistributionTripResource($trip->load(['vehicle', 'driver', 'carrier', 'custodyItems'])))->resolve(),
            201
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        $data = $request->validate([
            'name'     => ['nullable', 'string', 'max:100'],
            'type'     => ['nullable', 'in:company_vehicle,personal_vehicle,external_carrier'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'notes'    => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $trip = $this->tripService->updateTrip($trip, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json((new DistributionTripResource($trip->load(['vehicle', 'driver', 'carrier', 'custodyItems'])))->resolve());
    }

    public function destroy(string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);

        try {
            $this->tripService->deleteTrip($trip);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Trip deleted.']);
    }

    public function autoFill(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);

        try {
            $assigned = $this->tripService->autoFill($trip, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $trip->refresh()->load(['vehicle', 'driver', 'carrier', 'custodyItems']);
        $orders = $this->boardService->getTripOrders($trip->id);

        return response()->json([
            'trip'            => (new DistributionTripResource($trip))->resolve(),
            'assigned_orders' => $orders->values(),
            'assigned_count'  => $assigned,
        ]);
    }

    public function addOrder(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        $data = $request->validate(['order_id' => ['required', 'uuid']]);

        try {
            $this->tripService->addOrder($trip, $data['order_id'], $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $trip->refresh();
        return response()->json(['orders_count' => $trip->orders_count, 'collection_amount' => $trip->collection_amount]);
    }

    public function removeOrder(Request $request, string $id, string $orderId): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);

        try {
            $this->tripService->removeOrder($trip, $orderId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $trip->refresh();
        return response()->json(['orders_count' => $trip->orders_count, 'collection_amount' => $trip->collection_amount]);
    }

    public function moveOrder(Request $request, string $id): JsonResponse
    {
        $fromTrip = DistributionTrip::findOrFail($id);
        $data = $request->validate([
            'order_id'       => ['required', 'uuid'],
            'to_trip_id'     => ['required', 'uuid'],
        ]);
        $toTrip = DistributionTrip::findOrFail($data['to_trip_id']);

        try {
            $this->tripService->moveOrder($fromTrip, $toTrip, $data['order_id'], $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Order moved.']);
    }

    public function assignDriver(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        $data = $request->validate([
            'fleet_driver_id' => ['nullable', 'integer'],
            'driver_name'     => ['nullable', 'string', 'max:100'],
            'driver_phone'    => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $trip = $this->tripService->assignDriver(
                $trip,
                $data['fleet_driver_id'] ?? null,
                $data['driver_name'] ?? null,
                $data['driver_phone'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json((new DistributionTripResource($trip->load(['vehicle', 'driver', 'carrier', 'custodyItems'])))->resolve());
    }

    public function assignVehicle(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        $data = $request->validate(['fleet_vehicle_id' => ['nullable', 'integer']]);

        try {
            $trip = $this->tripService->assignVehicle($trip, $data['fleet_vehicle_id'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json((new DistributionTripResource($trip->load(['vehicle', 'driver', 'carrier', 'custodyItems'])))->resolve());
    }

    public function assignCarrier(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        $data = $request->validate(['external_carrier_id' => ['nullable', 'integer']]);

        try {
            $trip = $this->tripService->assignCarrier($trip, $data['external_carrier_id'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json((new DistributionTripResource($trip->load(['vehicle', 'driver', 'carrier', 'custodyItems'])))->resolve());
    }

    public function addCustody(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        $data = $request->validate([
            'item_type'   => ['required', 'in:cash_float,pos_device,ice_boxes,ice_packs,thermal_bags,delivery_bags,other'],
            'description' => ['nullable', 'string', 'max:200'],
            'quantity'    => ['nullable', 'integer', 'min:1'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ]);

        $item = $this->tripService->addCustodyItem($trip, $data, $request->user()->id);

        return response()->json($item->toArray(), 201);
    }

    public function removeCustody(string $id, int $custodyId): JsonResponse
    {
        $item = DistributionTripCustody::where('distribution_trip_id', $id)
            ->findOrFail($custodyId);

        $this->tripService->removeCustodyItem($item);

        return response()->json(['message' => 'Custody item removed.']);
    }

    /**
     * POST /distribution/trips/{id}/approve
     * Approve trip for loading — generates manifest, status → loading.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);

        try {
            $manifest = $this->tripService->approveTrip($trip, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $trip->refresh()->load(['vehicle', 'driver', 'carrier', 'custodyItems']);

        return response()->json([
            'trip'     => (new DistributionTripResource($trip))->resolve(),
            'manifest' => [
                'id'             => $manifest->id,
                'total_products' => $manifest->total_products,
                'status'         => $manifest->status,
            ],
            'message'  => 'Trip approved. Loading manifest generated.',
        ]);
    }

    /**
     * POST /distribution/trips/{id}/orders/{orderId}/return-to-wave
     * Return an order to the wave exception list (remove from trip).
     */
    public function returnToWave(Request $request, string $id, string $orderId): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:50'],
            'notes'  => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->tripService->returnToWave(
                $trip,
                $orderId,
                $request->user()->id,
                $data['reason'] ?? null,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $trip->refresh();

        return response()->json([
            'message'      => 'Order returned to wave exception list.',
            'orders_count' => $trip->orders_count,
        ]);
    }

    /**
     * GET /distribution/trips/{id}/coverage
     * Returns order locations for Coverage Map with outlier-detection data.
     */
    public function coverageMap(string $id): JsonResponse
    {
        $orders = DB::table('distribution_trip_orders as dto')
            ->join('orders as o', 'o.id', '=', 'dto.order_id')
            ->join('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->join('logistics_governorates as lg', 'lg.id', '=', 'lc.governorate_id')
            ->where('dto.distribution_trip_id', $id)
            ->select([
                'o.id as order_id',
                'o.order_number',
                'o.grand_total',
                'o.latitude',
                'o.longitude',
                'lc.name_en as city_name',
                'lg.name_en as governorate_name',
            ])
            ->get();

        return response()->json(['orders' => $orders->values()]);
    }

    /**
     * GET /distribution/trips/{id}/manifest
     * Shortcut: get the manifest for a trip (if it exists).
     */
    public function manifest(string $id): JsonResponse
    {
        $manifest = DistributionLoadingManifest::with('items')
            ->where('distribution_trip_id', $id)
            ->first();

        if (!$manifest) {
            return response()->json(['manifest' => null]);
        }

        return response()->json(['manifest' => [
            'id'                  => $manifest->id,
            'status'              => $manifest->status,
            'total_products'      => $manifest->total_products,
            'confirmed_products'  => $manifest->confirmed_products,
            'shortage_products'   => $manifest->shortage_products,
            'can_complete'        => $manifest->items->where('status', 'pending')->count() === 0
                                     && $manifest->items->where('status', 'shortage')->whereNull('shortage_resolution')->count() === 0,
            'started_at'          => $manifest->started_at,
            'completed_at'        => $manifest->completed_at,
        ]]);
    }
}
