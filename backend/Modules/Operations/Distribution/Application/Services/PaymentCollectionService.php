<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryStop;
use Modules\Operations\Distribution\Domain\Models\DriverPaymentCollection;

class PaymentCollectionService
{
    /**
     * Collect payment for a stop.
     */
    public function collect(
        DriverDeliveryStop $stop,
        string             $paymentType,
        float              $amount,
        ?string            $referenceNumber,
        ?string            $imagePath,
        ?string            $notes,
        int                $userId,
    ): DriverPaymentCollection {
        $collection = DriverPaymentCollection::create([
            'stop_id'              => $stop->id,
            'distribution_trip_id' => $stop->distribution_trip_id,
            'payment_type'         => $paymentType,
            'amount'               => $amount,
            'reference_number'     => $referenceNumber,
            'image_path'           => $imagePath,
            'notes'                => $notes,
            'status'               => $paymentType === 'bank_transfer' ? 'pending_verification' : 'recorded',
            'created_at'           => now(),
        ]);

        // Update stop collected amount
        $stop->increment('collected_amount', $amount);
        $stop->update(['payment_method' => $paymentType]);

        $this->recalculateTripTotals($stop->distribution_trip_id);

        return $collection;
    }

    /**
     * Recalculate trip payment totals from all collections.
     */
    public function recalculateTripTotals(string $tripId): void
    {
        $totals = DB::table('driver_payment_collections')
            ->where('distribution_trip_id', $tripId)
            ->select([
                DB::raw("COALESCE(SUM(CASE WHEN payment_type = 'cash' THEN amount ELSE 0 END), 0)           AS cash"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_type = 'bank_transfer' THEN amount ELSE 0 END), 0)  AS bank"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_type = 'already_paid' THEN amount ELSE 0 END), 0)   AS already"),
            ])
            ->first();

        DB::table('distribution_trips')
            ->where('id', $tripId)
            ->update([
                'total_cash_collected' => $totals->cash,
                'total_bank_transfers' => $totals->bank,
                'total_already_paid'   => $totals->already,
                'updated_at'           => now(),
            ]);
    }
}
