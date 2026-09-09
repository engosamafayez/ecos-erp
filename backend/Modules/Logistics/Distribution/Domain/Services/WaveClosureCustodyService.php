<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Throwable;

/**
 * TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §E/§F — the Wave-closure
 * custody sweep `CloseWaveDistributionGroupsListener`'s own docblock explicitly
 * disclaims: "it does not... touch a Trip, Driver, Vehicle or Loading record."
 * That was true and, until this task, left a real gap: nothing ever closed an
 * in-flight Trip or its Loading execution when the Wave that produced it ended.
 *
 * ┌─ THE THREE OUTCOMES (§E), ALL DECIDED BY ONE FACT ─────────────────────────┐
 * │ `Trip::hasFullDriverAcceptance()` — the same three-boolean "Dispatch Gate"  │
 * │ attestation `dispatchBlockers()` already gates real dispatch on — is the    │
 * │ single signal this sweep trusts for "did the driver actually accept        │
 * │ custody". It is chosen deliberately over the OTHER, per-product custody     │
 * │ mechanism (`LoadingCustodyService`, keyed on `loading_tasks`): the Dispatch │
 * │ Gate is this codebase's own canonical "custody boundary" (see              │
 * │ `Trip::isTrackable()`'s docblock, which already calls it exactly that).    │
 * │                                                                            │
 * │  (a) accepted             -> untouched. Nothing here can run at all: the   │
 * │                              loop below skips a Trip the instant this is   │
 * │                              true, so its Loading execution and Order stay │
 * │                              exactly where the Wave left them.            │
 * │  (b) loaded, not accepted -> cancel the Loading execution (VehicleAssignment│
 * │                              -> Cancelled) and the Trip itself, and RELEASE │
 * │                              the Order back to the unassigned pool         │
 * │                              (TripService::releaseOrder(), the existing    │
 * │                              post-dispatch-retry primitive — never deleted, │
 * │                              so the loaded/attempted history stays         │
 * │                              auditable). The Order's status is untouched:  │
 * │                              it was never anything but ReadyForDispatch,   │
 * │                              because nothing in this codebase marks an     │
 * │                              Order dispatched before a Trip reaches        │
 * │                              Dispatched, which itself requires acceptance. │
 * │  (c) never loaded         -> same cancel+release, distinguished only by    │
 * │                              the recorded reason — there is no physical    │
 * │                              custody to return, only an execution record   │
 * │                              (and possibly a still-open Order assignment)  │
 * │                              to close out so it does not survive the Wave. │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * NOTHING IS DELETED. `VehicleAssignment`/`LoadingTask` rows are left exactly as
 * they are — their `quantity_loaded`/`driver_confirmed_loaded_qty` values ARE the
 * audit trail §G's History view reads (loaded vs. accepted vs. returned). Only
 * `status`/`cancelled_at`/`cancellation_reason` are stamped on the assignment, and
 * `TripOrder.superseded_at` on the order link — the same "supersede, never delete"
 * contract `releaseOrder()` already documents.
 *
 * NEVER TOUCHES `LoadingSession.status` (the warehouse+day parent row). A session
 * is shared across every Wave/Group active that day for that warehouse — closing
 * ONE Wave must never assume every other Trip still loading under the same
 * session is also done. `LoadingSessionProgressCoordinator` already owns deciding
 * when the parent session may advance, driven by genuine loading-completion
 * events; re-triggering it from here would risk firing a "loading complete"
 * signal for a session that actually just had part of its work cancelled.
 *
 * BEST-EFFORT, PER TRIP. One Trip's unexpected failure is logged and skipped,
 * never allowed to stop the rest of the sweep or escape into the `WaveClosed`
 * dispatch chain — Wave/Group closure (already a tested, working operation) must
 * never be put at risk by this newer, additive cleanup.
 */
final class WaveClosureCustodyService
{
    /** No physical custody ever existed — only an execution record to close out. */
    public const REASON_NOT_LOADED = 'wave_closed_not_loaded';

    /** Real goods were loaded; the driver never confirmed taking custody of them. */
    public const REASON_LOADED_NOT_ACCEPTED = 'wave_closed_loaded_not_accepted';

    public function __construct(
        private readonly TripService $trips,
    ) {}

    /**
     * @return array{trips_closed: int, assignments_cancelled: int, orders_released: int}
     */
    public function sweepWave(string $waveId): array
    {
        $groupIds = VirtualCapacitySlot::query()
            ->where('preparation_wave_id', $waveId)
            ->pluck('id')
            ->all();

        $totals = ['trips_closed' => 0, 'assignments_cancelled' => 0, 'orders_released' => 0];

        if ($groupIds === []) {
            return $totals;
        }

        // Every Trip still open under this Wave's Groups. Terminal Trips
        // (Closed/Cancelled) are excluded — nothing to close twice, which is what
        // makes a replayed/duplicated WaveClosed event idempotent here too.
        $trips = Trip::query()
            ->whereIn('virtual_slot_id', $groupIds)
            ->whereNotIn('status', [TripStatus::Closed->value, TripStatus::Cancelled->value])
            ->get();

        foreach ($trips as $trip) {
            if ($trip->hasFullDriverAcceptance()) {
                // §E case (a) — preserve Driver/Vehicle custody. Nothing to do.
                continue;
            }

            try {
                $result = $this->closeUnacceptedTrip($trip);
                $totals['trips_closed'] += $result['trip_closed'];
                $totals['assignments_cancelled'] += $result['assignments_cancelled'];
                $totals['orders_released'] += $result['orders_released'];
            } catch (Throwable $e) {
                Log::error('distribution.wave_closure_custody_sweep_failed', [
                    'wave_id' => $waveId,
                    'trip_id' => $trip->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($totals['trips_closed'] > 0 || $totals['assignments_cancelled'] > 0) {
            Log::info('distribution.wave_closure_custody_sweep', $totals + ['wave_id' => $waveId]);
        }

        return $totals;
    }

    /**
     * @return array{trip_closed: int, assignments_cancelled: int, orders_released: int}
     */
    private function closeUnacceptedTrip(Trip $trip): array
    {
        return DB::transaction(function () use ($trip): array {
            // Re-read the assignments under lock semantics of the enclosing
            // transaction; excluding already-Cancelled ones keeps a genuine prior
            // cancellation's own reason/timestamp from being overwritten.
            $assignments = VehicleAssignment::query()
                ->where('trip_id', $trip->id)
                ->where('status', '!=', VehicleAssignmentStatus::Cancelled->value)
                ->get();

            $hadLoadingActivity = $assignments->contains(
                static fn (VehicleAssignment $a): bool => $a->loading_started_at !== null
                    || $a->status !== VehicleAssignmentStatus::Pending,
            );

            $reason = $hadLoadingActivity ? self::REASON_LOADED_NOT_ACCEPTED : self::REASON_NOT_LOADED;

            foreach ($assignments as $assignment) {
                $assignment->update([
                    'status' => VehicleAssignmentStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ]);
            }

            // Release every still-active Order execution on this Trip — the Order
            // itself is untouched (still whatever status it already was), it is
            // only freed from THIS Trip so a future Wave can plan it again.
            // Historical/superseded rows and the trip's own past are preserved.
            $releasedCount = 0;

            foreach ($trip->tripOrders as $tripOrder) {
                $this->trips->releaseOrder($trip, $tripOrder->order_id, $reason);
                $releasedCount++;
            }

            $tripClosed = 0;

            if ($trip->status->canTransitionTo(TripStatus::Cancelled)) {
                $this->trips->changeStatus($trip, TripStatus::Cancelled, reason: $reason);
                $tripClosed = 1;
            } else {
                // Defensive only: every non-accepted, non-terminal status this
                // service's own query can select already allows -> Cancelled (see
                // TripStatus::allowedTransitions()). Logged rather than thrown so
                // one unexpected state does not block the rest of the sweep.
                Log::warning('distribution.wave_closure_custody_trip_transition_refused', [
                    'trip_id' => $trip->id,
                    'from_status' => $trip->status->value,
                ]);
            }

            return [
                'trip_closed' => $tripClosed,
                'assignments_cancelled' => $assignments->count(),
                'orders_released' => $releasedCount,
            ];
        });
    }
}
