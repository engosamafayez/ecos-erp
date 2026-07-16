<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryAction;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryException;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryProof;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryStop;
use RuntimeException;

class DeliveryActionService
{
    public function __construct(
        private readonly TripAuditService        $audit,
        private readonly PaymentCollectionService $paymentService,
    ) {}

    /**
     * Process delivery outcome for a stop.
     *
     * @param array<string,mixed> $payload
     */
    public function processDelivery(
        DriverDeliveryStop $stop,
        string             $actionType,
        array              $payload,
        int                $userId,
    ): DriverDeliveryStop {
        if (!in_array($stop->status, ['pending', 'in_progress'], true)) {
            throw new RuntimeException("Stop #{$stop->sequence} is already {$stop->status}.");
        }

        // Map action type to stop status
        $statusMap = [
            'completed'      => 'delivered',
            'partial'        => 'partial',
            'refused'        => 'failed',
            'not_available'  => 'failed',
            'delay'          => 'pending',   // reschedule — stays pending
            'wrong_address'  => 'failed',
            'unreachable'    => 'failed',
        ];

        $newStopStatus = $statusMap[$actionType] ?? 'failed';

        // Record the action
        DriverDeliveryAction::create([
            'stop_id'           => $stop->id,
            'action_type'       => $actionType,
            'reason'            => $payload['reason'] ?? null,
            'notes'             => $payload['notes'] ?? null,
            'new_delivery_date' => $payload['new_delivery_date'] ?? null,
            'corrected_lat'     => $payload['corrected_lat'] ?? null,
            'corrected_lng'     => $payload['corrected_lng'] ?? null,
            'performed_by'      => $userId,
            'created_at'        => now(),
        ]);

        // Update stop
        $stop->update([
            'status'         => $newStopStatus,
            'delivery_type'  => $actionType,
            'attempted_at'   => $stop->attempted_at ?? now(),
            'completed_at'   => in_array($newStopStatus, ['delivered', 'partial', 'failed', 'returned'], true) ? now() : null,
            'notes'          => $payload['notes'] ?? $stop->notes,
        ]);

        // Handle inline payment collection if provided
        if (
            in_array($actionType, ['completed', 'partial'], true)
            && isset($payload['payment_type'])
            && isset($payload['payment_amount'])
        ) {
            $this->paymentService->collect(
                stop:            $stop,
                paymentType:     $payload['payment_type'],
                amount:          (float) $payload['payment_amount'],
                referenceNumber: $payload['reference_number'] ?? null,
                imagePath:       $payload['image_path'] ?? null,
                notes:           $payload['payment_notes'] ?? null,
                userId:          $userId,
            );
        }

        $this->audit->record(
            tripId:      $stop->distribution_trip_id,
            action:      "stop_{$actionType}",
            fromStatus:  null,
            toStatus:    $newStopStatus,
            performedBy: $userId,
            notes:       "Stop #{$stop->sequence} — {$actionType}" . ($payload['reason'] ? ": {$payload['reason']}" : ''),
        );

        return $stop->fresh();
    }

    /**
     * Save proof of delivery for a stop.
     *
     * @param string[] $photos
     */
    public function saveProof(
        DriverDeliveryStop $stop,
        ?string            $signaturePath,
        array              $photos,
        ?string            $notes,
        int                $userId,
    ): DriverDeliveryProof {
        // Delete existing proof if any
        $stop->proof()?->delete();

        return DriverDeliveryProof::create([
            'stop_id'        => $stop->id,
            'signature_path' => $signaturePath,
            'photos'         => json_encode($photos, JSON_THROW_ON_ERROR),
            'notes'          => $notes,
            'captured_at'    => now(),
            'captured_by'    => $userId,
        ]);
    }

    /**
     * Create a delivery exception for a stop/order.
     *
     * @param string[] $photos
     */
    public function createException(
        DistributionTrip   $trip,
        DriverDeliveryStop $stop,
        string             $type,
        string             $description,
        array              $photos,
        int                $userId,
    ): DriverDeliveryException {
        return DriverDeliveryException::create([
            'distribution_trip_id' => $trip->id,
            'stop_id'              => $stop->id,
            'order_id'             => $stop->order_id,
            'exception_type'       => $type,
            'description'          => $description,
            'photos'               => json_encode($photos, JSON_THROW_ON_ERROR),
            'synced_to_cs'         => false,
            'reported_by'          => $userId,
            'created_at'           => now(),
        ]);
    }
}
