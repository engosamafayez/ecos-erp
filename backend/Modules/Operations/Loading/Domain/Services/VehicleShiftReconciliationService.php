<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Loading\Domain\Enums\ReconciliationStatus;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliation;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliationLine;
use RuntimeException;

/**
 * End-of-shift vehicle reconciliation (ADR-015 §6.4).
 *
 * Consumes the three quantity authorities; it is not itself any of them:
 *
 *   loaded    VehicleInventoryItem.quantity_loaded
 *             ← VehicleInventoryService::recordLoad() ← LoadProductAction
 *   delivered VehicleInventoryItem.quantity_delivered
 *             ← VehicleInventoryService::recordDelivery() ← RecordProductDeliveryAction
 *   returned  VehicleShiftReconciliationLine.quantity_returned_actual
 *             ← counted at the warehouse by the operator (ReconciliationLineRequest)
 *
 * ADR-015 §6.4 states the invariant as
 *   quantity_variance = loaded - delivered - returned   (must be 0)
 * The line model expresses it as expected − actual, where
 *   quantity_returned_expected = loaded - delivered
 * which is the same equation rearranged. Both forms are kept in sync below.
 *
 * The shift is the VehicleAssignment: VehicleAssignment hasOne
 * VehicleShiftReconciliation, and every VehicleInventoryItem belongs to exactly
 * one assignment, so Shift A can never read Shift B's quantities.
 */
final class VehicleShiftReconciliationService
{
    /** Quantities are decimal(18,4); compare below that resolution. */
    private const EPSILON = 0.00005;

    /**
     * Open (or return) the reconciliation for a shift and refresh its lines from
     * the current loaded/delivered facts.
     *
     * Idempotent: the hasOne relation means re-opening returns the same header, and
     * lines are keyed on the vehicle inventory item, so re-running updates in place
     * rather than duplicating. Any operator-counted quantity_returned_actual already
     * recorded on a line is preserved.
     */
    public function open(VehicleAssignment $assignment, string $actorId): VehicleShiftReconciliation
    {
        return DB::transaction(function () use ($assignment, $actorId): VehicleShiftReconciliation {
            $reconciliation = VehicleShiftReconciliation::query()
                ->where('vehicle_assignment_id', $assignment->id)
                ->lockForUpdate()
                ->first();

            if ($reconciliation === null) {
                // The shift date is the loading session's operational_date — the
                // assignment carries none, and reading "today" would misfile a shift
                // reconciled the following morning.
                $operationalDate = $assignment->loadingSession()->value('operational_date');

                if ($operationalDate === null) {
                    throw new RuntimeException(
                        "Vehicle assignment '{$assignment->id}' has no loading session operational date; "
                        .'the shift cannot be identified.',
                    );
                }

                // driver_assignment_id is a NOT NULL FK on vehicle_shift_reconciliations.
                // A shift with no driver never went out, so refuse explicitly rather
                // than surfacing a constraint violation.
                $driverAssignmentId = $assignment->driverAssignment()->value('id');

                if ($driverAssignmentId === null) {
                    throw new RuntimeException(
                        "Vehicle assignment '{$assignment->id}' has no driver assignment; "
                        .'a shift that was never crewed cannot be reconciled.',
                    );
                }

                $reconciliation = VehicleShiftReconciliation::create([
                    'company_id' => $assignment->company_id,
                    'vehicle_assignment_id' => $assignment->id,
                    'loading_session_id' => $assignment->loading_session_id,
                    'vehicle_id' => $assignment->vehicle_id,
                    'driver_assignment_id' => $driverAssignmentId,
                    'operational_date' => $operationalDate,
                    'status' => ReconciliationStatus::Open->value,
                    'opened_at' => now(),
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            } elseif ($reconciliation->status === ReconciliationStatus::Approved) {
                // Approved is terminal in ReconciliationStatus; a closed shift must not
                // be silently reopened and recomputed.
                throw new RuntimeException(
                    "Shift reconciliation '{$reconciliation->id}' is approved and cannot be recomputed.",
                );
            }

            $items = VehicleInventoryItem::query()
                ->where('vehicle_assignment_id', $assignment->id)
                ->get();

            foreach ($items as $item) {
                $line = VehicleShiftReconciliationLine::query()
                    ->where('reconciliation_id', $reconciliation->id)
                    ->where('vehicle_inventory_item_id', $item->id)
                    ->first();

                $loaded = (float) $item->quantity_loaded;
                $delivered = (float) $item->quantity_delivered;
                $returnedActual = $line !== null ? (float) $line->quantity_returned_actual : 0.0;

                $attributes = [
                    'company_id' => $item->company_id,
                    'reconciliation_id' => $reconciliation->id,
                    'vehicle_inventory_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'sku_snapshot' => $item->sku_snapshot,
                    'quantity_loaded' => $loaded,
                    'quantity_delivered' => $delivered,
                    'quantity_returned_expected' => max(0.0, $loaded - $delivered),
                    'quantity_returned_actual' => $returnedActual,
                    'variance' => $this->variance($loaded, $delivered, $returnedActual),
                    'updated_by' => $actorId,
                ];

                if ($line === null) {
                    VehicleShiftReconciliationLine::create($attributes + ['created_by' => $actorId]);
                } else {
                    $line->update($attributes);
                }
            }

            return $this->recalculateTotals($reconciliation->refresh(), $actorId);
        });
    }

    /**
     * Record the quantity physically counted back into the warehouse for one line.
     *
     * Absolute, not additive: re-submitting the same count is a no-op rather than a
     * double credit.
     */
    public function recordReturnedActual(
        VehicleShiftReconciliationLine $line,
        float $quantityReturnedActual,
        ?string $resolutionNotes,
        string $actorId,
    ): VehicleShiftReconciliationLine {
        if ($quantityReturnedActual < 0) {
            throw new RuntimeException(
                "Cannot record a negative returned quantity ({$quantityReturnedActual}) for line '{$line->id}'.",
            );
        }

        return DB::transaction(function () use ($line, $quantityReturnedActual, $resolutionNotes, $actorId): VehicleShiftReconciliationLine {
            /** @var VehicleShiftReconciliationLine $locked */
            $locked = VehicleShiftReconciliationLine::query()->lockForUpdate()->findOrFail($line->id);

            /** @var VehicleShiftReconciliation $header */
            $header = VehicleShiftReconciliation::query()->lockForUpdate()->findOrFail($locked->reconciliation_id);

            if ($header->status === ReconciliationStatus::Approved) {
                throw new RuntimeException(
                    "Shift reconciliation '{$header->id}' is approved and can no longer be amended.",
                );
            }

            $locked->quantity_returned_actual = $quantityReturnedActual;
            $locked->variance = $this->variance(
                (float) $locked->quantity_loaded,
                (float) $locked->quantity_delivered,
                $quantityReturnedActual,
            );

            if ($resolutionNotes !== null) {
                $locked->resolution_notes = $resolutionNotes;
            }

            $locked->updated_by = $actorId;
            $locked->save();

            $this->recalculateTotals($header, $actorId);

            return $locked->refresh();
        });
    }

    /**
     * ADR-015 §6.4 — quantity_variance = loaded - delivered - returned.
     */
    public function variance(float $loaded, float $delivered, float $returned): float
    {
        return $loaded - $delivered - $returned;
    }

    /** True when every line reconciles to zero, i.e. the shift is fully accounted for. */
    public function isReconciled(VehicleShiftReconciliation $reconciliation): bool
    {
        return abs((float) $reconciliation->total_variance) <= self::EPSILON
            && ! $reconciliation->has_variance;
    }

    private function recalculateTotals(
        VehicleShiftReconciliation $reconciliation,
        string $actorId,
    ): VehicleShiftReconciliation {
        $lines = VehicleShiftReconciliationLine::query()
            ->where('reconciliation_id', $reconciliation->id)
            ->get();

        $totalVariance = (float) $lines->sum('variance');

        $reconciliation->update([
            'total_quantity_loaded' => (float) $lines->sum('quantity_loaded'),
            'total_quantity_delivered' => (float) $lines->sum('quantity_delivered'),
            'total_quantity_returned' => (float) $lines->sum('quantity_returned_actual'),
            'total_variance' => $totalVariance,
            // A shift carries a variance when ANY line fails to reconcile — offsetting
            // lines must not net out to a false zero.
            'has_variance' => $lines->contains(
                fn (VehicleShiftReconciliationLine $l): bool => abs((float) $l->variance) > self::EPSILON,
            ),
            'updated_by' => $actorId,
        ]);

        return $reconciliation->refresh();
    }
}
