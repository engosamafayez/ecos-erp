<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Loading\Domain\Enums\LoadingTaskStatus;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Services\VehicleInventoryService;
use RuntimeException;

/**
 * Records an actual load against a vehicle assignment and moves the loaded
 * quantity into vehicle inventory via VehicleInventoryService.
 *
 * TWO GRAINS, ONE EXECUTION PATH. The operator/pool flow passes a real
 * `poolEntryId` + `preparationWaveId` (Preparation-pool provenance). The
 * owner-approved Group-as-Shipment driver flow (Option 1) passes NULL for
 * both — the Group grain has no pool entry, and fabricating one is forbidden.
 * The over-load ceiling, idempotent absolute-set, and inventory delta are
 * identical for both; only the provenance columns differ (nullable since the
 * 2026_08_25 migration). Pool-based loading is unchanged.
 */
final class LoadProductAction
{
    /** Float tolerance mirroring the delivery-side guard in RecordProductDeliveryAction. */
    private const EPSILON = 0.00005;

    public function __construct(
        private readonly VehicleInventoryService $inventoryService,
    ) {}

    public function execute(
        VehicleAssignment $assignment,
        ?string $poolEntryId,
        string $productId,
        string $skuSnapshot,
        string $nameSnapshot,
        ?string $preparationWaveId,
        float $quantityPlanned,
        float $quantityLoaded,
        string $loadedBy,
        bool $requiresRefrigeration = false,
        ?string $shortReason = null,
        ?string $notes = null,
    ): LoadingTask {
        return DB::transaction(function () use (
            $assignment,
            $poolEntryId,
            $productId,
            $skuSnapshot,
            $nameSnapshot,
            $preparationWaveId,
            $quantityPlanned,
            $quantityLoaded,
            $loadedBy,
            $requiresRefrigeration,
            $shortReason,
            $notes,
        ): LoadingTask {
            // Fail closed on OVER-LOAD. The loading path is the symmetric twin of the
            // delivery path, which already refuses delivered > allocated
            // (RecordProductDeliveryAction). Loading had no such ceiling: a spoofed or
            // fat-fingered quantity_loaded silently over-loaded the vehicle inventory.
            // quantity_planned is the allocated/planned quantity for this pool entry;
            // loaded may fall short (ShortLoaded) but must never exceed it. There is no
            // approved over-load contract, so — like over-delivery — it is refused, not
            // invented into a write-off. Surfaced as 422 by the controller catch.
            if ($quantityLoaded - $quantityPlanned > self::EPSILON) {
                throw new RuntimeException(
                    "Loaded quantity ({$quantityLoaded}) exceeds the planned/allocated quantity "
                    ."({$quantityPlanned}) for product '{$skuSnapshot}'. Over-loading has no approved "
                    .'contract, so it is refused.',
                );
            }

            $isShort = $quantityLoaded < $quantityPlanned;
            $quantityShort = max(0.0, $quantityPlanned - $quantityLoaded);

            // IDEMPOTENCY (absolute set, not increment).
            //
            // This previously called LoadingTask::create() unconditionally, so a
            // retry — or two operators loading the same product — produced two
            // tasks and DOUBLE the loaded quantity, with nothing in the schema to
            // stop it. The row is now located under a lock and its quantity is
            // SET to the value the operator states, never added to. Re-sending
            // the same quantity is therefore a no-op by construction, which is
            // the same shape Group Loading Preparation already uses.
            //
            // The unique index on (vehicle_assignment_id, product_id) is what
            // makes this safe rather than merely well-behaved: it collapses the
            // race between the lookup and the write.
            $existing = LoadingTask::query()
                ->where('vehicle_assignment_id', $assignment->id)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // The delta is what the vehicle inventory must move by, so a
                // correction downwards is reflected rather than ignored.
                $previouslyLoaded = (float) $existing->quantity_loaded;

                $existing->update([
                    'quantity_planned' => $quantityPlanned,
                    'quantity_loaded' => $quantityLoaded,
                    'quantity_short' => $quantityShort,
                    'status' => $isShort
                        ? LoadingTaskStatus::ShortLoaded->value
                        : LoadingTaskStatus::Loaded->value,
                    'loaded_by' => $loadedBy,
                    'loaded_at' => now(),
                    'short_reason' => $shortReason,
                    'notes' => $notes,
                    'updated_by' => $loadedBy,
                ]);

                $delta = $quantityLoaded - $previouslyLoaded;

                if (abs($delta) > self::EPSILON) {
                    // The vehicle-inventory ledger stores a positive MAGNITUDE and puts
                    // the direction in `movement_type` (`CHECK (quantity > 0)`), so the
                    // signed delta is routed to the matching canonical method instead of
                    // being pushed through recordLoad() as a negative quantity — which
                    // violated the constraint and made a downward correction impossible
                    // (TASK-DRIVER-02).
                    if ($delta > 0) {
                        $this->inventoryService->recordLoad(
                            assignment: $assignment,
                            task: $existing,
                            quantity: $delta,
                            actorId: $loadedBy,
                        );

                        $assignment->increment('loading_weight_kg', $delta);
                    } else {
                        $this->inventoryService->recordLoadCorrection(
                            assignment: $assignment,
                            task: $existing,
                            quantityRemoved: -$delta,
                            actorId: $loadedBy,
                        );

                        // Decrement explicitly rather than incrementing by a negative:
                        // `vehicle_assignments` carries CHECK (loading_weight_kg >= 0),
                        // and an increment-with-negative aimed at a non-negative column
                        // reads like an accident waiting to underflow.
                        $assignment->decrement('loading_weight_kg', -$delta);
                    }

                    // TASK-...-IMPLEMENTATION-002-R1 — INVALIDATE THE STALE CONFIRMATION
                    // ITSELF, NOT JUST THE ASSIGNMENT STATUS.
                    //
                    // ┌─ WHY 002's OWN APPROACH WAS NOT ENOUGH (CTO R1 review) ──────────┐
                    // │ 002 left driver_confirmed_at / driver_confirmed_loaded_qty in      │
                    // │ place and relied on isDriverConfirmationCurrent() (a DERIVED       │
                    // │ comparison) plus reopening the assignment. That protects the ONE   │
                    // │ gate that happens to call isDriverConfirmationCurrent() — but any   │
                    // │ future/other reader that checks only "driver_confirmed_at IS NOT    │
                    // │ NULL" would be misled, and an assignment still at Loading (not yet   │
                    // │ complete) never got its stale task-level confirmation cleared at     │
                    // │ all, since the reopen logic below only fires for an already-         │
                    // │ complete assignment.                                                │
                    // └──────────────────────────────────────────────────────────────────┘
                    //
                    // Clearing these four columns makes "no longer authoritative" a STORED
                    // fact rather than something only correctly derived by one specific
                    // caller. The driver's confirmation ceases to exist the moment the
                    // warehouse number it was made against actually changes — applies
                    // regardless of the assignment's current status, and only inside this
                    // delta-guarded block, so a no-op resubmission never churns a fresh,
                    // still-valid confirmation (R1 §9).
                    if ($existing->driver_confirmed_at !== null) {
                        $existing->update([
                            'driver_confirmed_at' => null,
                            'driver_confirmed_by' => null,
                            'driver_received_qty' => null,
                            'driver_confirmed_loaded_qty' => null,
                        ]);
                    }

                    // REOPEN ON POST-COMPLETION CORRECTION (from 002, preserved). The task-
                    // level fix above already makes the confirmation non-authoritative for
                    // any reader; this additionally corrects the ASSIGNMENT's own status for
                    // the specific case where it had already reached LoadingComplete, so a
                    // shipment already marked done is truthfully reopened rather than left
                    // claiming "done" while carrying an unconfirmed item underneath it.
                    $lockedAssignment = VehicleAssignment::query()
                        ->whereKey($assignment->id)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedAssignment !== null) {
                        $assignmentStatus = $lockedAssignment->status instanceof VehicleAssignmentStatus
                            ? $lockedAssignment->status
                            : VehicleAssignmentStatus::from((string) $lockedAssignment->status);

                        if ($assignmentStatus === VehicleAssignmentStatus::LoadingComplete) {
                            $lockedAssignment->update([
                                'status' => VehicleAssignmentStatus::Loading->value,
                                'loading_completed_at' => null,
                                'updated_by' => $loadedBy,
                            ]);
                        }
                    }
                }

                return $existing->fresh() ?? $existing;
            }

            $task = LoadingTask::create([
                'company_id' => $assignment->company_id,
                'loading_session_id' => $assignment->loading_session_id,
                'vehicle_assignment_id' => $assignment->id,
                'pool_entry_id' => $poolEntryId,
                'product_id' => $productId,
                'sku_snapshot' => $skuSnapshot,
                'name_snapshot' => $nameSnapshot,
                'preparation_wave_id' => $preparationWaveId,
                'quantity_planned' => $quantityPlanned,
                'quantity_loaded' => $quantityLoaded,
                'quantity_short' => $quantityShort,
                'status' => $isShort
                    ? LoadingTaskStatus::ShortLoaded->value
                    : LoadingTaskStatus::Loaded->value,
                'requires_refrigeration' => $requiresRefrigeration,
                'loaded_by' => $loadedBy,
                'loaded_at' => now(),
                'short_reason' => $shortReason,
                'notes' => $notes,
                'created_by' => $loadedBy,
                'updated_by' => $loadedBy,
            ]);

            if ($quantityLoaded > 0) {
                $this->inventoryService->recordLoad(
                    assignment: $assignment,
                    task: $task,
                    quantity: $quantityLoaded,
                    actorId: $loadedBy,
                );

                $assignment->increment('loading_weight_kg', $quantityLoaded);
            }

            return $task->fresh() ?? $task;
        });
    }
}
