<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Observers;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Preparation\Application\Services\DailyPreparationSessionManager;
use Modules\Operations\Preparation\Application\Services\PreparationReleaseEngine;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveMembershipService;
use Modules\Operations\Preparation\Domain\Models\PreparationWaveOrder;

/**
 * CR-PREP-001HF — Auto Detach.
 *
 * Watches Order model changes and auto-detaches from any active Preparation Session
 * when the order is no longer eligible. Four triggers:
 *
 *  1. Status became ineligible (cancelled, rejected, on_hold, or any status absent
 *     from the policy's eligible_order_statuses list).
 *  2. Warehouse reassigned — order no longer belongs to this session's warehouse.
 *
 * RC-3 (2026-08-23) extends the SAME hook to PREPARATION WAVE membership, which had no
 * status reactivity at all. See reconcileWaveMembership(). Waves use the canonical
 * `OrderStatus::fulfilmentEligible()` predicate (ADR-042 §7); sessions keep their policy
 * list. Neither store writes the order's status.
 *
 * Eligibility decisions are fully delegated to PreparationReleaseEngine.
 * No hardcoded status strings exist in this class.
 */
final class OrderPreparationObserver
{
    public function __construct(
        private readonly DailyPreparationSessionManager $manager,
        private readonly PreparationReleaseEngine $releaseEngine,
        // RC-3 — the EXISTING wave membership service. Eviction reuses its release path
        // rather than writing `preparation_wave_orders` here, so there is still exactly one
        // writer of wave membership.
        private readonly WaveMembershipService $waveMembership,
    ) {}

    public function updated(Order $order): void
    {
        // Quick exit — only act when preparation-relevant fields change.
        if (! $order->wasChanged(['status', 'assigned_warehouse_id'])) {
            return;
        }

        // RC-3 — WAVE membership first, and deliberately OUTSIDE the session early-returns
        // below. The two stores are independent: an order can hold an active wave membership
        // with no session row at all (sessions are per operational day, waves are not), so
        // returning early on a missing session would have left the wave gap exactly as it
        // was. This is the whole reason the defect survived: the hook existed, but every
        // path out of it was session-shaped.
        $this->reconcileWaveMembership($order);

        // Query the active session order directly (avoids stale cached relations).
        $sessionOrder = $order->activeSessionOrder()->first();
        if ($sessionOrder === null) {
            return;
        }

        $session = $sessionOrder->session;
        if ($session === null) {
            return;
        }

        // ── Trigger 1: Warehouse reassigned ──────────────────────────────────
        // The order's warehouse changed — it belongs to a different warehouse now.
        // Detach from the current session; WarehouseAssignedListener will attach
        // it to the new warehouse's session.
        if ($order->wasChanged('assigned_warehouse_id')
            && $order->assigned_warehouse_id !== $session->warehouse_id) {
            $this->manager->detachOrder(
                sessionOrder: $sessionOrder,
                reason: 'warehouse_reassigned',
                detachedBy: 'system',
            );

            return;
        }

        // ── Trigger 2: Status changed — check via Release Engine ─────────────
        if ($order->wasChanged('status')) {
            $policy = $this->releaseEngine->resolvePolicy(
                $session->company_id,
                $session->warehouse_id,
            );

            if (! $this->releaseEngine->isEligible($order, $policy)) {
                $reason = $this->releaseEngine->ineligibilityReason($order, $policy)
                    ?? 'status_ineligible';
                $this->manager->detachOrder(
                    sessionOrder: $sessionOrder,
                    reason: $reason,
                    detachedBy: 'system',
                );
            }
        }
    }

    /**
     * RC-3 — evict an order from its active PREPARATION WAVE once it stops being eligible.
     *
     * ┌─ WHY THIS SITS BESIDE THE SESSION LOGIC ABOVE ───────────────────────────┐
     * │ Preparation has TWO membership stores, and only one of them reacted to a  │
     * │ status change:                                                            │
     * │                                                                          │
     * │   preparation_session_orders  status-reactive  (updated(), above)          │
     * │   preparation_wave_orders     write-once       ← the RC-3 gap              │
     * │                                                                          │
     * │ That asymmetry is why an `awaiting_payment` order could sit in an active   │
     * │ wave indefinitely. The fix belongs in the SAME observer because the hook   │
     * │ is the same fact — `wasChanged('status')` — and a second watcher would be  │
     * │ a second thing to keep in step.                                           │
     * │                                                                          │
     * │ An event-driven design was NOT available: the demotion that produces this  │
     * │ case runs `ReturnToPaymentWorkflow`, whose `events()` returns `[]`. There  │
     * │ is no domain event to subscribe to, so the Eloquent hook is the only one.  │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * THE PREDICATE IS THE CANONICAL ONE. `OrderStatus::fulfilmentEligible()` — ADR-042 §7,
     * `['in_progress','confirmed']`. No second predicate is introduced and no status string
     * is written here. An order that is legitimately `in_progress` or `confirmed` is never
     * released by this method.
     *
     * WAREHOUSE REASSIGNMENT IS PART OF ELIGIBILITY; A CLEARED WAREHOUSE IS NOT. A wave
     * belongs to one warehouse, so an order reassigned to a different one no longer belongs
     * to it. An order whose warehouse was merely CLEARED is left alone — see the block
     * comment on that branch below for why evicting on NULL broke the certified carry-over
     * contract. No warehouse is ever invented and none is auto-assigned.
     *
     * IT NEVER WRITES `orders`. Release is a membership decision. Whatever should happen to
     * the order's lifecycle is the payment/fulfilment contract's business.
     */
    private function reconcileWaveMembership(Order $order): void
    {
        $membership = PreparationWaveOrder::query()
            ->activeMembership()
            ->where('order_id', $order->id)
            ->first();

        if ($membership === null) {
            return;
        }

        $wave = $membership->wave;

        if ($wave === null) {
            return;
        }

        // ── RETENTION IS NOT ADMISSION ────────────────────────────────────────────────
        // An order that already PASSED preparation keeps its membership until the wave
        // itself closes: `released_at` is written only by closeWave() and CancelWaveAction,
        // never by order completion. So a prepared order sits here as an active member all
        // the way through ready_for_dispatch -> out_for_delivery -> delivered, and none of
        // those three is in `fulfilmentEligible()`.
        //
        // Without this guard the eviction below would fire on ordinary forward progress and
        // treat a successfully dispatched order as "became ineligible" — releasing the row,
        // decrementing `orders_count` (which CompleteWaveAction reports as the count of what
        // the wave actually prepared), and dispatching a demand recompute per dispatch. The
        // wave would end the day understating its own output.
        //
        // Eviction exists for orders that went BACKWARD or were abandoned. It must never
        // punish an order for finishing.
        if ($order->status instanceof OrderStatus && $order->status->hasLeftPreparation()) {
            return;
        }

        // Warehouse before status: an order moved to a DIFFERENT warehouse is ineligible for
        // THIS wave regardless of its status, and reporting a status reason would be wrong.
        //
        // ┌─ A CLEARED WAREHOUSE IS NOT A REASSIGNMENT ──────────────────────────────┐
        // │ `assigned_warehouse_id === null` deliberately does NOT evict, and that is  │
        // │ a correction to this method's first cut.                                   │
        // │                                                                          │
        // │ Treating NULL as a warehouse mismatch made every order with no warehouse   │
        // │ ineligible for any wave — and wave membership is not warehouse-keyed in the │
        // │ store, only in the collector that fills it. The certified carry-over        │
        // │ contract (HandlePreparationWaveClosed, WaveCarryOverDependencyTest) models  │
        // │ membership purely by status and G-1 completion; its fixtures never assign a  │
        // │ warehouse at all. Evicting on NULL released those members early, decremented │
        // │ `orders_count`, and flipped the wave-closed decision from CASE C (carry the  │
        // │ unfinished order back to In Progress) to CASE B (leave it Ready for          │
        // │ Dispatch) — silently cancelling carry-over for five certified scenarios.     │
        // │                                                                          │
        // │ A missing warehouse is its own operational exception ("Warehouse            │
        // │ Unassigned"): it is visible on the order itself, needs no wave eviction to   │
        // │ be true, and must not be repaired by inventing an assignment. Eviction is    │
        // │ for an order that now belongs somewhere ELSE — a fact only a non-null,       │
        // │ different warehouse can establish.                                          │
        // └──────────────────────────────────────────────────────────────────────────┘
        if ($order->assigned_warehouse_id !== null
            && (string) $order->assigned_warehouse_id !== (string) ($wave->warehouse_id ?? '')
        ) {
            $this->waveMembership->releaseIneligibleOrder(
                wave: $wave,
                orderId: (string) $order->id,
                reason: 'warehouse_reassigned',
            );

            return;
        }

        if (! in_array($order->status, OrderStatus::fulfilmentEligible(), true)) {
            $status = $order->status instanceof OrderStatus
                ? $order->status->value
                : (string) $order->status;

            $this->waveMembership->releaseIneligibleOrder(
                wave: $wave,
                orderId: (string) $order->id,
                reason: 'status_ineligible:'.$status,
            );
        }
    }
}
