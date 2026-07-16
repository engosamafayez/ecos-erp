<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Distribution\Application\Services\TripSettlementService;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DriverCustodyReturn;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryReturn;
use Modules\Operations\Distribution\Domain\Models\DriverTripSettlement;
use RuntimeException;

class TripSettlementController extends Controller
{
    public function __construct(
        private readonly TripSettlementService $settlementService,
    ) {}

    /**
     * GET /driver/trips/{id}/settlement
     */
    public function settlement(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        $settlement = $this->settlementService->calculateSettlement($trip);
        return response()->json($settlement);
    }

    /**
     * POST /driver/trips/{id}/settlement/submit
     */
    public function submitSettlement(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'cash_submitted' => ['required', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $trip = DistributionTrip::findOrFail($id);
        $settlement = DriverTripSettlement::where('distribution_trip_id', $id)->firstOrFail();

        try {
            $settlement = $this->settlementService->submitSettlement(
                settlement:    $settlement,
                cashSubmitted: (float) $data['cash_submitted'],
                notes:         $data['notes'] ?? null,
                userId:        $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'    => 'Settlement submitted.',
            'settlement' => $settlement,
        ]);
    }

    /**
     * POST /driver/trips/{id}/returns
     */
    public function addReturn(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'order_id'     => ['required', 'integer'],
            'product_id'   => ['required', 'integer'],
            'product_name' => ['required', 'string', 'max:255'],
            'return_type'  => ['required', 'string', 'in:full,partial'],
            'qty'          => ['required', 'numeric', 'min:0.001'],
            'reason'       => ['nullable', 'string', 'max:1000'],
            'photos'       => ['nullable', 'array'],
            'photos.*'     => ['string', 'max:500'],
        ]);

        $trip = DistributionTrip::findOrFail($id);

        $return = $this->settlementService->addReturn(
            trip:        $trip,
            orderId:     (int) $data['order_id'],
            productId:   (int) $data['product_id'],
            productName: $data['product_name'],
            returnType:  $data['return_type'],
            qty:         (float) $data['qty'],
            reason:      $data['reason'] ?? null,
            photos:      $data['photos'] ?? [],
            userId:      $request->user()->id,
        );

        return response()->json([
            'message' => 'Return recorded.',
            'return'  => $return,
        ]);
    }

    /**
     * POST /driver/returns/{returnId}/confirm
     */
    public function confirmReturn(Request $request, string $returnId): JsonResponse
    {
        $data = $request->validate([
            'confirmed_qty' => ['required', 'numeric', 'min:0'],
        ]);

        $return = DriverDeliveryReturn::findOrFail($returnId);

        $return = $this->settlementService->confirmReturn(
            return:       $return,
            confirmedQty: (float) $data['confirmed_qty'],
            userId:       $request->user()->id,
        );

        return response()->json([
            'message' => 'Return confirmed.',
            'return'  => $return,
        ]);
    }

    /**
     * GET /driver/trips/{id}/custody-returns
     */
    public function custodyReturns(Request $request, string $id): JsonResponse
    {
        $returns = DriverCustodyReturn::where('distribution_trip_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($returns);
    }

    /**
     * POST /driver/trips/{id}/custody-returns
     */
    public function recordCustodyReturn(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'custody_type'   => ['required', 'string', 'max:50'],
            'dispatched_qty' => ['required', 'integer', 'min:0'],
            'returned_qty'   => ['required', 'integer', 'min:0'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $trip = DistributionTrip::findOrFail($id);

        $custodyReturn = $this->settlementService->recordCustodyReturn(
            trip:          $trip,
            custodyType:   $data['custody_type'],
            dispatchedQty: (int) $data['dispatched_qty'],
            returnedQty:   (int) $data['returned_qty'],
            notes:         $data['notes'] ?? null,
            userId:        $request->user()->id,
        );

        return response()->json([
            'message'        => 'Custody return recorded.',
            'custody_return' => $custodyReturn,
        ]);
    }

    /**
     * POST /driver/trips/{id}/close
     */
    public function closeTrip(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);

        try {
            $trip = $this->settlementService->closeTrip($trip, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Trip closed.',
            'status'  => $trip->status,
        ]);
    }
}
