<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Logistics\Carriers\Application\Actions\CreateExternalCarrierShipmentAction;
use Modules\Logistics\Carriers\Domain\Exceptions\CarrierException;
use Modules\Logistics\Carriers\Domain\Models\CarrierShipment;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;

/**
 * The external-carrier shipment execution surface (TASK-ECOS-V1.1-OPS-03-
 * TASK1-BOSTA, Section 7/25). Thin by design (Section 7: "Do NOT put Bosta
 * HTTP logic in controllers") — every real decision lives in
 * CreateExternalCarrierShipmentAction; this controller only resolves the
 * stop, enforces company scope, and serialises the result.
 */
final class CarrierShipmentController extends Controller
{
    public function __construct(private readonly CreateExternalCarrierShipmentAction $create) {}

    public function store(string $stopUuid): JsonResponse
    {
        $companyId = Auth::user()?->company_id;

        $stop = DeliveryStop::where('uuid', $stopUuid)
            ->whereHas('trip', fn ($q) => $q->where('company_id', $companyId))
            ->firstOrFail();

        try {
            $shipment = $this->create->execute($stop, Auth::id() !== null ? (int) Auth::id() : null);
        } catch (CarrierException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->respond($shipment)->setStatusCode($shipment->wasRecentlyCreated ? 201 : 200);
    }

    public function show(string $stopUuid): JsonResponse
    {
        $companyId = Auth::user()?->company_id;

        $shipment = CarrierShipment::where('company_id', $companyId)
            ->whereHas('deliveryStop', fn ($q) => $q->where('uuid', $stopUuid))
            ->with(['carrierAccount:id,uuid,name,adapter_key'])
            ->firstOrFail();

        return $this->respond($shipment);
    }

    private function respond(CarrierShipment $shipment): JsonResponse
    {
        return response()->json(['data' => [
            'uuid' => $shipment->uuid,
            'carrier' => $shipment->carrierAccount?->name,
            'external_reference' => $shipment->external_reference,
            'tracking_number' => $shipment->tracking_number,
            'label_url' => $shipment->label_url,
            'raw_status' => $shipment->raw_status,
            'cod_amount' => $shipment->cod_amount,
            'last_event_at' => $shipment->last_event_at?->toIso8601String(),
        ]]);
    }
}
