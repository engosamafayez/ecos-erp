<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Distribution\Application\Services\PaymentCollectionService;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryStop;
use Modules\Operations\Distribution\Domain\Models\DriverPaymentCollection;

class DriverPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentCollectionService $paymentService,
    ) {}

    /**
     * POST /driver/stops/{stopId}/payment
     * Collect payment for a stop.
     */
    public function collect(Request $request, string $stopId): JsonResponse
    {
        $data = $request->validate([
            'payment_type'     => ['required', 'string', 'in:cash,bank_transfer,already_paid'],
            'amount'           => ['required', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'image_path'       => ['nullable', 'string', 'max:500'],
            'notes'            => ['nullable', 'string', 'max:500'],
        ]);

        $stop = DriverDeliveryStop::findOrFail($stopId);

        $collection = $this->paymentService->collect(
            stop:            $stop,
            paymentType:     $data['payment_type'],
            amount:          (float) $data['amount'],
            referenceNumber: $data['reference_number'] ?? null,
            imagePath:       $data['image_path'] ?? null,
            notes:           $data['notes'] ?? null,
            userId:          $request->user()->id,
        );

        return response()->json([
            'message'    => 'Payment recorded.',
            'collection' => $collection,
        ]);
    }

    /**
     * GET /driver/trips/{id}/collections
     * All payment collections for a trip.
     */
    public function tripCollections(Request $request, string $id): JsonResponse
    {
        $collections = DriverPaymentCollection::where('distribution_trip_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Attach order_number for context
        $result = $collections->map(function ($col) {
            $data = $col->toArray();
            $stop = DriverDeliveryStop::find($col->stop_id);
            if ($stop) {
                $data['order_id'] = $stop->order_id;
            }
            return $data;
        });

        return response()->json($result);
    }
}
