<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\PaymentType;
use Modules\Logistics\Distribution\Domain\Enums\SettlementStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Events\TripSettled;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\PaymentCollection;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;

/**
 * COD collection and end-of-trip cash reconciliation.
 *
 * Money totals are always DERIVED from the payment-collection ledger, never
 * accumulated by hand, so the settlement cannot drift from the underlying rows.
 */
class SettlementService
{
    /**
     * Record money taken at a stop.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordPayment(DeliveryStop $stop, array $attributes, ?int $actorId = null): PaymentCollection
    {
        if (! $stop->acceptsPayment()) {
            throw DistributionException::paymentNotAllowedForStop();
        }

        return DB::transaction(function () use ($stop, $attributes, $actorId) {
            $payment = PaymentCollection::create($attributes + [
                'trip_id' => $stop->trip_id,
                'stop_id' => $stop->id,
                'collected_by' => $actorId,
            ]);

            $this->refreshTripTotals($stop->trip);

            return $payment;
        });
    }

    /**
     * Verify a recorded collection.
     *
     * SEPARATION OF DUTIES — `collected_by != verified_by`, enforced BY IDENTITY
     * (TASK-DRIVER-02). Before this, record and verify took the same permission, the
     * `driver` role held it, and this method performed no check — so a driver could
     * record cash and immediately verify their own cash, on any company's trip, through
     * a uuid alone. The permission split and the tenant scope close the other two halves;
     * this closes the one they cannot: that two different people were actually involved.
     *
     * Mirrors the certified `VerifyPaymentProofAction` control exactly, and for the same
     * stated reason — it sits in the domain rather than the route, so an `is_system` role
     * is subject to it like every other actor.
     */
    public function verifyPayment(PaymentCollection $payment, ?int $actorId = null): PaymentCollection
    {
        if ($payment->isSelfReviewBy($actorId)) {
            abort(403, 'Separation of duties: the user who recorded a payment collection may not verify it. Verification must be performed by a different authorised reviewer.');
        }

        $payment->update([
            'status' => PaymentCollection::STATUS_VERIFIED,
            'verified_at' => now(),
            'verified_by' => $actorId,
        ]);

        return $payment->refresh();
    }

    /**
     * Reject a recorded collection.
     *
     * Same identity rule as {@see verifyPayment()}. Reject is the other half of one
     * reviewer act — both routes carry the same reviewer permission — so exempting it
     * would leave the collector in control of half the outcome space and make the
     * contract asymmetric for no reason. The rule itself lives on the model,
     * `PaymentCollection::isSelfReviewBy()`, so there is one implementation, not two.
     */
    public function rejectPayment(PaymentCollection $payment, ?string $notes = null, ?int $actorId = null): PaymentCollection
    {
        if ($payment->isSelfReviewBy($actorId)) {
            abort(403, 'Separation of duties: the user who recorded a payment collection may not reject it. Review must be performed by a different authorised reviewer.');
        }

        return DB::transaction(function () use ($payment, $notes, $actorId) {
            $payment->update([
                'status' => PaymentCollection::STATUS_REJECTED,
                'verified_at' => now(),
                'verified_by' => $actorId,
                'notes' => $notes ?? $payment->notes,
            ]);

            $this->refreshTripTotals($payment->trip);

            return $payment->refresh();
        });
    }

    /**
     * Open (or refresh) the settlement for a trip.
     * Requires every stop to have reached an outcome.
     */
    public function openSettlement(Trip $trip): TripSettlement
    {
        if (! $trip->allStopsSettled()) {
            throw DistributionException::settlementRequiresCompletion();
        }

        return DB::transaction(function () use ($trip) {
            $totals = $this->calculateTotals($trip);

            $settlement = TripSettlement::firstOrNew(['trip_id' => $trip->id]);

            if ($settlement->exists && $settlement->isFinal()) {
                throw DistributionException::settlementFinal();
            }

            $settlement->fill($totals);
            $settlement->save();

            return $settlement->refresh();
        });
    }

    /** The driver hands back cash; the discrepancy is derived, not supplied. */
    public function submitDriverCash(
        TripSettlement $settlement,
        float $amount,
        ?string $notes = null,
    ): TripSettlement {
        $this->assertMutable($settlement);
        $this->assertTransition($settlement, SettlementStatus::Submitted);

        return DB::transaction(function () use ($settlement, $amount, $notes) {
            $settlement->update([
                'driver_cash_submitted' => $amount,
                'status' => SettlementStatus::Submitted->value,
                'submitted_at' => now(),
                'notes' => $notes ?? $settlement->notes,
            ]);

            $settlement->refresh();
            $settlement->update(['discrepancy' => $settlement->calculateDiscrepancy()]);

            return $settlement->refresh();
        });
    }

    public function reconcile(TripSettlement $settlement, ?string $notes = null): TripSettlement
    {
        $this->assertMutable($settlement);
        $this->assertTransition($settlement, SettlementStatus::Reconciled);

        $settlement->update([
            'status' => SettlementStatus::Reconciled->value,
            'reconciled_at' => now(),
            'notes' => $notes ?? $settlement->notes,
        ]);

        return $settlement->refresh();
    }

    public function dispute(TripSettlement $settlement, ?string $notes = null): TripSettlement
    {
        $this->assertMutable($settlement);
        $this->assertTransition($settlement, SettlementStatus::Disputed);

        $settlement->update([
            'status' => SettlementStatus::Disputed->value,
            'notes' => $notes ?? $settlement->notes,
        ]);

        return $settlement->refresh();
    }

    /** Finalize and close the trip. */
    public function finalize(TripSettlement $settlement, ?int $actorId = null, ?string $actor = null): TripSettlement
    {
        $this->assertMutable($settlement);
        $this->assertTransition($settlement, SettlementStatus::Finalized);

        $finalized = DB::transaction(function () use ($settlement, $actorId) {
            $settlement->update([
                'status' => SettlementStatus::Finalized->value,
                'finalized_at' => now(),
                'finalized_by' => $actorId,
            ]);

            $trip = $settlement->trip;
            if ($trip !== null && $trip->status->canTransitionTo(TripStatus::Closed)) {
                $trip->update(['status' => TripStatus::Closed->value]);
            }

            return $settlement->refresh();
        });

        $trip = $finalized->trip;
        if ($trip !== null) {
            TripSettled::dispatch($trip, $finalized, $actor);
        }

        return $finalized;
    }

    /** Read-only financial summary for the UI. */
    public function financialSummary(Trip $trip): array
    {
        $totals = $this->calculateTotals($trip);
        $settlement = $trip->settlement;

        return $totals + [
            'settlement_status' => $settlement?->status->value,
            'driver_cash_submitted' => $settlement?->driver_cash_submitted !== null
                ? (float) $settlement->driver_cash_submitted
                : null,
            'discrepancy' => $settlement?->discrepancy !== null ? (float) $settlement->discrepancy : null,
            'is_balanced' => $settlement?->isBalanced(),
            'stops_total' => $trip->stops()->count(),
            'stops_outstanding' => $trip->outstandingStopsCount(),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @return array<string, float> */
    private function calculateTotals(Trip $trip): array
    {
        $rows = $trip->paymentCollections()
            ->where('status', '!=', PaymentCollection::STATUS_REJECTED)
            ->get();

        $sumOf = static fn (PaymentType $type): float => round(
            (float) $rows->where('payment_type', $type)->sum('amount'),
            2,
        );

        $cash = $sumOf(PaymentType::Cash);
        $bank = $sumOf(PaymentType::BankTransfer);
        $card = $sumOf(PaymentType::Card);
        $alreadyPaid = $sumOf(PaymentType::AlreadyPaid);

        return [
            'cash_collected' => $cash,
            'bank_transfers_pending' => $bank + $card,
            'already_paid' => $alreadyPaid,
            'total_collected' => round($cash + $bank + $card + $alreadyPaid, 2),
            // Only physical cash is reconciled against the driver's hand-back.
            'cash_expected' => $cash,
        ];
    }

    private function refreshTripTotals(?Trip $trip): void
    {
        if ($trip === null) {
            return;
        }

        $totals = $this->calculateTotals($trip);

        $trip->update([
            'total_cash_collected' => $totals['cash_collected'],
            'total_bank_transfers' => $totals['bank_transfers_pending'],
            'total_already_paid' => $totals['already_paid'],
            'collection_amount' => $totals['total_collected'],
        ]);
    }

    private function assertMutable(TripSettlement $settlement): void
    {
        if ($settlement->isFinal()) {
            throw DistributionException::settlementFinal();
        }
    }

    private function assertTransition(TripSettlement $settlement, SettlementStatus $target): void
    {
        if (! $settlement->status->canTransitionTo($target)) {
            throw DistributionException::invalidSettlementTransition($settlement->status, $target);
        }
    }
}
