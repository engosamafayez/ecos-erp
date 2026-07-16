<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DriverCustodyReturn;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryReturn;
use Modules\Operations\Distribution\Domain\Models\DriverTripSettlement;
use RuntimeException;

class TripSettlementService
{
    public function __construct(
        private readonly TripAuditService $audit,
    ) {}

    /**
     * Calculate (or retrieve existing) settlement for a trip.
     */
    public function calculateSettlement(DistributionTrip $trip): DriverTripSettlement
    {
        $existing = DriverTripSettlement::where('distribution_trip_id', $trip->id)->first();

        $totals = DB::table('driver_payment_collections')
            ->where('distribution_trip_id', $trip->id)
            ->select([
                DB::raw("COALESCE(SUM(CASE WHEN payment_type = 'cash'          THEN amount ELSE 0 END), 0) as cash"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_type = 'bank_transfer' THEN amount ELSE 0 END), 0) as bank"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_type = 'already_paid'  THEN amount ELSE 0 END), 0) as already"),
            ])
            ->first();

        $cashCollected     = (float) $totals->cash;
        $bankTransfers     = (float) $totals->bank;
        $alreadyPaid       = (float) $totals->already;
        $totalCollected    = $cashCollected + $bankTransfers + $alreadyPaid;
        $cashExpected      = $cashCollected; // cash expected = what was collected as cash

        $data = [
            'distribution_trip_id'   => $trip->id,
            'cash_collected'         => $cashCollected,
            'bank_transfers_pending' => $bankTransfers,
            'already_paid'           => $alreadyPaid,
            'total_collected'        => $totalCollected,
            'cash_expected'          => $cashExpected,
            'updated_at'             => now(),
        ];

        if ($existing) {
            // Don't overwrite submitted/verified/closed settlements
            if (in_array($existing->status, ['submitted', 'verified', 'closed'], true)) {
                return $existing;
            }
            $existing->update($data);
            return $existing->fresh();
        }

        return DriverTripSettlement::create(array_merge($data, [
            'status'     => 'draft',
            'created_at' => now(),
        ]));
    }

    /**
     * Submit settlement (driver submits cash).
     */
    public function submitSettlement(
        DriverTripSettlement $settlement,
        float                $cashSubmitted,
        ?string              $notes,
        int                  $userId,
    ): DriverTripSettlement {
        if ($settlement->status !== 'draft') {
            throw new RuntimeException("Settlement is already {$settlement->status}.");
        }

        $discrepancy = $cashSubmitted - $settlement->cash_expected;

        $settlement->update([
            'driver_cash_submitted' => $cashSubmitted,
            'discrepancy'           => $discrepancy,
            'status'                => 'submitted',
            'notes'                 => $notes,
            'updated_at'            => now(),
        ]);

        $this->audit->record(
            tripId:      $settlement->distribution_trip_id,
            action:      'settlement_submitted',
            fromStatus:  null,
            toStatus:    null,
            performedBy: $userId,
            notes:       "Driver submitted EGP {$cashSubmitted}. Discrepancy: EGP {$discrepancy}",
        );

        return $settlement->fresh();
    }

    /**
     * Record custody return.
     */
    public function recordCustodyReturn(
        DistributionTrip $trip,
        string           $custodyType,
        int              $dispatchedQty,
        int              $returnedQty,
        ?string          $notes,
        int              $userId,
    ): DriverCustodyReturn {
        $driverLiable = $returnedQty < $dispatchedQty;

        return DriverCustodyReturn::create([
            'distribution_trip_id' => $trip->id,
            'custody_type'         => $custodyType,
            'dispatched_qty'       => $dispatchedQty,
            'returned_qty'         => $returnedQty,
            'driver_liable'        => $driverLiable,
            'notes'                => $notes,
            'created_at'           => now(),
        ]);
    }

    /**
     * Close trip (all conditions met).
     */
    public function closeTrip(DistributionTrip $trip, int $userId): DistributionTrip
    {
        if (!in_array($trip->status, ['completed', 'settlement_pending'], true)) {
            throw new RuntimeException("Trip must be completed or settlement_pending to close.");
        }

        $trip->update(['status' => 'closed']);

        $this->audit->record(
            tripId:      $trip->id,
            action:      'trip_closed',
            fromStatus:  $trip->getOriginal('status'),
            toStatus:    'closed',
            performedBy: $userId,
            notes:       'Trip closed after settlement verification.',
        );

        return $trip->fresh();
    }

    /**
     * Add a delivery return (product return).
     *
     * @param string[] $photos
     */
    public function addReturn(
        DistributionTrip $trip,
        int              $orderId,
        int              $productId,
        string           $productName,
        string           $returnType,
        float            $qty,
        ?string          $reason,
        array            $photos,
        int              $userId,
    ): DriverDeliveryReturn {
        return DriverDeliveryReturn::create([
            'distribution_trip_id' => $trip->id,
            'order_id'             => $orderId,
            'product_id'           => $productId,
            'product_name'         => $productName,
            'return_type'          => $returnType,
            'returned_qty'         => $qty,
            'reason'               => $reason,
            'photos'               => json_encode($photos, JSON_THROW_ON_ERROR),
            'driver_liability'     => false,
            'reported_by'          => $userId,
            'created_at'           => now(),
        ]);
    }

    /**
     * Warehouse confirms return quantities.
     */
    public function confirmReturn(
        DriverDeliveryReturn $return,
        float                $confirmedQty,
        int                  $userId,
    ): DriverDeliveryReturn {
        $discrepancy = $return->returned_qty - $confirmedQty;
        $driverLiable = $discrepancy > 0;

        $return->update([
            'warehouse_confirmed_qty' => $confirmedQty,
            'warehouse_confirmed_at'  => now(),
            'warehouse_confirmed_by'  => $userId,
            'discrepancy_qty'         => $discrepancy,
            'driver_liability'        => $driverLiable,
        ]);

        return $return->fresh();
    }
}
