<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Distribution\Application\Services\DeliveryActionService;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryStop;
use Modules\Operations\Distribution\Domain\Models\DriverPaymentCollection;
use RuntimeException;

class DriverStopController extends Controller
{
    public function __construct(
        private readonly DeliveryActionService $actionService,
    ) {}

    /**
     * POST /driver/stops/{stopId}/action
     * Process delivery outcome for a stop.
     */
    public function action(Request $request, string $stopId): JsonResponse
    {
        $data = $request->validate([
            'action_type'       => ['required', 'string', 'in:completed,partial,refused,not_available,delay,wrong_address,unreachable'],
            'reason'            => ['nullable', 'string', 'max:255'],
            'notes'             => ['nullable', 'string', 'max:1000'],
            'new_delivery_date' => ['nullable', 'date'],
            'corrected_lat'     => ['nullable', 'numeric'],
            'corrected_lng'     => ['nullable', 'numeric'],
            'payment_type'      => ['nullable', 'string', 'in:cash,bank_transfer,already_paid'],
            'payment_amount'    => ['nullable', 'numeric', 'min:0'],
            'reference_number'  => ['nullable', 'string', 'max:100'],
            'image_path'        => ['nullable', 'string', 'max:500'],
            'payment_notes'     => ['nullable', 'string', 'max:500'],
        ]);

        $stop = DriverDeliveryStop::findOrFail($stopId);

        try {
            $stop = $this->actionService->processDelivery(
                stop:       $stop,
                actionType: $data['action_type'],
                payload:    $data,
                userId:     $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Delivery action recorded.',
            'stop'    => $stop,
        ]);
    }

    /**
     * POST /driver/stops/{stopId}/proof
     * Save proof of delivery.
     */
    public function proof(Request $request, string $stopId): JsonResponse
    {
        $data = $request->validate([
            'signature_path' => ['nullable', 'string', 'max:500'],
            'photos'         => ['nullable', 'array'],
            'photos.*'       => ['string', 'max:500'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ]);

        $stop = DriverDeliveryStop::findOrFail($stopId);

        $proof = $this->actionService->saveProof(
            stop:          $stop,
            signaturePath: $data['signature_path'] ?? null,
            photos:        $data['photos'] ?? [],
            notes:         $data['notes'] ?? null,
            userId:        $request->user()->id,
        );

        return response()->json([
            'message' => 'Proof of delivery saved.',
            'proof'   => $proof,
        ]);
    }

    /**
     * POST /driver/stops/{stopId}/exception
     * Create a delivery exception.
     */
    public function exception(Request $request, string $stopId): JsonResponse
    {
        $data = $request->validate([
            'exception_type' => ['required', 'string', 'in:damaged,missing,wrong_product,complaint,packaging,other'],
            'description'    => ['required', 'string', 'max:1000'],
            'photos'         => ['nullable', 'array'],
            'photos.*'       => ['string', 'max:500'],
        ]);

        $stop = DriverDeliveryStop::findOrFail($stopId);
        $trip = DistributionTrip::findOrFail($stop->distribution_trip_id);

        $exception = $this->actionService->createException(
            trip:        $trip,
            stop:        $stop,
            type:        $data['exception_type'],
            description: $data['description'],
            photos:      $data['photos'] ?? [],
            userId:      $request->user()->id,
        );

        return response()->json([
            'message'   => 'Exception recorded.',
            'exception' => $exception,
        ]);
    }

    /**
     * GET /driver/stops/{stopId}/collections
     */
    public function collections(Request $request, string $stopId): JsonResponse
    {
        $collections = DriverPaymentCollection::where('stop_id', $stopId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($collections);
    }
}
