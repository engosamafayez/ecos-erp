<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Delivery\Domain\Enums\FailureReason;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToReviewFromDeliveryWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToReviewWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReleaseForReplanningWorkflow;
use Throwable;

/**
 * TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 §D/§E — the
 * operational-day-closure BACKSTOP for delivery attempts, reacting to the same
 * `WaveClosed` event `WaveClosureCustodyService` already reacts to (Task 022),
 * since `Trip.preparation_wave_id -> PreparationWave.planning_date` already IS
 * the company-timezone-resolved operational day
 * ({@see \Modules\Operations\Preparation\Application\Services\WaveEngine\CompanyTimezoneResolver}) —
 * there is no separate "is it past midnight for this company" trigger anywhere
 * in this codebase, and §B explicitly forbids building one.
 *
 * ┌─ THIS IS A BACKSTOP, NOT THE PRIMARY PATH ─────────────────────────────────┐
 * │ `ReleaseOrderOnRetryableOutcomeListener` ALREADY resolves the common case  │
 * │ (No Answer / Postponed) IMMEDIATELY, the instant the driver records the    │
 * │ outcome — correctly, and this service must never re-process an order it   │
 * │ already moved on: the `status === OutForDelivery` filter below is exactly  │
 * │ what makes that automatic (an order already released is no longer a       │
 * │ candidate). What that listener does NOT do, and nothing else in this       │
 * │ codebase does either:                                                     │
 * │   (a) a NON-retryable failure (customer refused, product fault, ...) is    │
 * │       left completely alone by design — stranded at OutForDelivery         │
 * │       forever, with no closure path at all. This is §E's "Cancelled"       │
 * │       case: the closest existing signal to a delivery-side "cancellation". │
 * │   (b) a stop that never reached ANY settled outcome (still Pending/        │
 * │       InProgress — the driver never got to it, or the app never recorded   │
 * │       one) has no resolution path either. §D's "no delivery attempt from   │
 * │       that closed day remains operationally open" is a correctness         │
 * │       guarantee, so this is treated the same as a retryable failure —      │
 * │       replanned, not stranded.                                            │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * NO INVENTORY ACTION, EITHER WORKFLOW. Goods already left the warehouse ledger
 * at load time; both {@see ReleaseForReplanningWorkflow} and
 * {@see MoveToReviewFromDeliveryWorkflow} touch only the Order's own status.
 * Physical custody stays with the Driver/Vehicle until an actual warehouse
 * receipt (§F) — "order execution state and physical goods custody are
 * separate authorities" (§J), enforced here by simply never writing to
 * inventory in either branch.
 *
 * BEST-EFFORT, PER ORDER, exactly like {@see WaveClosureCustodyService}: one
 * order's unexpected failure is logged and skipped, never allowed to stop the
 * rest of the sweep or escape into the `WaveClosed` dispatch chain.
 *
 * IDEMPOTENT BY STATUS FILTER: a replayed `WaveClosed` finds zero orders still
 * `OutForDelivery` on an already-swept trip (each was already moved to
 * InProgress/OnHold), so it is a safe no-op — the same pattern every other
 * closure operation in this codebase already uses.
 *
 * TASK-...-023-R1 GATE 4 — a SECOND, independent sweep below
 * (`sweepGenuinelyCancelledOrders()`) closes a real gap this task's first pass
 * missed: `CancelOrderWorkflow` allows cancelling a `ReadyForDispatch` order
 * with `force_cancel_preparation=true` — "physical preparation work is
 * complete" is its own guard's wording — with NO awareness of Loading/vehicle
 * custody at all (it releases only the WAREHOUSE-side reservation). An Order
 * can therefore reach genuine, terminal `OrderStatus::Cancelled` while its
 * goods are ALREADY physically loaded onto a vehicle under one of this
 * Wave's Trips, and nothing previously reviewed that case for office
 * attention. Reuses the EXISTING `MoveToReviewWorkflow` unmodified — `Cancelled`
 * is not in its blocked-source list, so no new workflow class was needed here
 * (unlike §E's non-retryable-failure case, which genuinely had no reachable
 * On-Hold edge before this task).
 */
final class DeliveryAttemptClosureService
{
    /** No settled outcome exists, or the recorded reason could not be resolved. */
    public const REASON_UNRESOLVED_AT_CLOSURE = 'wave_closed_attempt_unresolved';

    /** A genuinely Cancelled order still had an active custody claim on this Wave's Trip. */
    public const REASON_CANCELLED_WITH_CUSTODY = 'wave_closed_cancelled_goods_in_custody';

    public function __construct(
        private readonly TripService $trips,
        private readonly FulfillmentEngine $fulfillment,
        private readonly MoveToReviewWorkflow $moveToReview,
    ) {}

    /**
     * @return array{released_to_in_progress: int, moved_to_on_hold: int, cancelled_moved_to_on_hold: int}
     */
    public function sweepWave(string $waveId): array
    {
        $totals = ['released_to_in_progress' => 0, 'moved_to_on_hold' => 0, 'cancelled_moved_to_on_hold' => 0];

        $groupIds = VirtualCapacitySlot::query()
            ->where('preparation_wave_id', $waveId)
            ->pluck('id')
            ->all();

        if ($groupIds === []) {
            return $totals;
        }

        $tripIds = Trip::query()
            ->whereIn('virtual_slot_id', $groupIds)
            ->pluck('id')
            ->all();

        if ($tripIds === []) {
            return $totals;
        }

        // Every ACTIVE (non-superseded) order association on these trips — a
        // released one is already someone else's concern, not this sweep's.
        $tripOrderRows = DB::table('distribution_trip_orders')
            ->whereIn('trip_id', $tripIds)
            ->whereNull('superseded_at')
            ->get(['trip_id', 'order_id']);

        if ($tripOrderRows->isEmpty()) {
            return $totals;
        }

        $orderIds = $tripOrderRows->pluck('order_id')->unique()->values()->all();

        $tripsById = Trip::query()
            ->whereIn('id', $tripIds)
            ->get()
            ->keyBy(static fn (Trip $t): int => (int) $t->id);

        // Every order still carrying an active claim on one of this Wave's Trips,
        // regardless of its current status — the two sweeps below each filter to
        // the ONE status they care about, independently, so neither's early exit
        // can suppress the other (§R1 Gate 4: a Cancelled order must be reviewable
        // even on a Wave with zero still-OutForDelivery orders).
        $candidateOrders = Order::query()
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy(static fn (Order $o): string => (string) $o->id);

        // ── §D/§E — orders still OutForDelivery: the original backstop ──────────

        $outForDelivery = $candidateOrders->filter(
            static fn (Order $o): bool => $o->status === OrderStatus::OutForDelivery,
        );

        if ($outForDelivery->isNotEmpty()) {
            $stopsByTripAndOrder = DeliveryStop::query()
                ->whereIn('trip_id', $tripIds)
                ->whereIn('order_id', $outForDelivery->keys()->all())
                ->with('actions')
                ->get()
                ->keyBy(static fn (DeliveryStop $s): string => $s->trip_id.':'.$s->order_id);

            foreach ($tripOrderRows as $row) {
                $order = $outForDelivery->get((string) $row->order_id);
                if ($order === null) {
                    continue; // not (or no longer) a candidate
                }

                $trip = $tripsById->get((int) $row->trip_id);
                if ($trip === null) {
                    continue; // orphaned reference — defensive only, should not occur
                }

                $stop = $stopsByTripAndOrder->get($row->trip_id.':'.$row->order_id);
                [$isNonRetryable, $reasonValue] = $this->classify($stop);

                try {
                    $this->trips->releaseOrder($trip, (string) $order->id, $reasonValue, null);

                    if ($isNonRetryable) {
                        $this->fulfillment->run(
                            new MoveToReviewFromDeliveryWorkflow(),
                            $order,
                            ['reason' => $reasonValue, 'hold_reason_code' => $reasonValue, 'trip_id' => $trip->id],
                        );
                        $totals['moved_to_on_hold']++;
                    } else {
                        $this->fulfillment->run(
                            new ReleaseForReplanningWorkflow(),
                            $order,
                            ['reason' => $reasonValue, 'trip_id' => $trip->id],
                        );
                        $totals['released_to_in_progress']++;
                    }
                } catch (Throwable $e) {
                    Log::channel('daily')->error('[DeliveryAttemptClosureService] Failed to close a delivery attempt at Wave closure', [
                        'wave_id' => $waveId,
                        'trip_id' => $trip->id,
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── §R1 Gate 4 — orders already genuinely Cancelled, goods still in custody ──

        $cancelled = $candidateOrders->filter(
            static fn (Order $o): bool => $o->status === OrderStatus::Cancelled,
        );

        foreach ($tripOrderRows as $row) {
            $order = $cancelled->get((string) $row->order_id);
            if ($order === null) {
                continue;
            }

            $trip = $tripsById->get((int) $row->trip_id);
            if ($trip === null) {
                continue;
            }

            try {
                // Preserved as history (superseded, not deleted) exactly like the
                // OutForDelivery branch above — the order was never re-plannable
                // from Cancelled in the first place, but the stale claim on this
                // closing Trip must not linger past the Wave that produced it.
                $this->trips->releaseOrder($trip, (string) $order->id, self::REASON_CANCELLED_WITH_CUSTODY, null);

                $this->fulfillment->run(
                    $this->moveToReview,
                    $order,
                    ['reason' => self::REASON_CANCELLED_WITH_CUSTODY, 'hold_reason_code' => self::REASON_CANCELLED_WITH_CUSTODY, 'trip_id' => $trip->id],
                );
                $totals['cancelled_moved_to_on_hold']++;
            } catch (Throwable $e) {
                Log::channel('daily')->error('[DeliveryAttemptClosureService] Failed to move a cancelled order to review at Wave closure', [
                    'wave_id' => $waveId,
                    'trip_id' => $trip->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($totals['released_to_in_progress'] > 0 || $totals['moved_to_on_hold'] > 0 || $totals['cancelled_moved_to_on_hold'] > 0) {
            Log::info('distribution.delivery_attempt_closure_sweep', $totals + ['wave_id' => $waveId]);
        }

        return $totals;
    }

    /**
     * @return array{0: bool, 1: string} [isNonRetryable, reasonValue]
     */
    private function classify(?DeliveryStop $stop): array
    {
        if ($stop === null || $stop->status !== DeliveryStopStatus::Failed) {
            // Never settled at all (still Pending/InProgress), or settled some
            // other way that should not still leave the Order OutForDelivery
            // (Delivered/Partial/Returned/Skipped) — §D's backstop: replan it.
            return [false, self::REASON_UNRESOLVED_AT_CLOSURE];
        }

        $latestAction = $stop->actions->first(); // DeliveryStop::actions() is already ->latest()
        $reason = $latestAction?->reason !== null ? FailureReason::tryFrom($latestAction->reason) : null;

        if ($reason === null) {
            return [false, self::REASON_UNRESOLVED_AT_CLOSURE];
        }

        return $reason->isRetryable() ? [false, $reason->value] : [true, $reason->value];
    }
}
