<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Services\WaveEngine;

use BackedEnum;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Events\OrderAddedToWave;
use Modules\Operations\Preparation\Domain\Events\OrderRemovedFromWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWaveOrder;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;

final class WaveMembershipService
{
    public function __construct(
        private readonly DemandRefreshDispatcher $demandDispatcher,
    ) {}

    /**
     * Scan the DB for eligible orders with no active wave membership and attach them.
     * Returns the number of orders newly attached.
     *
     * INTAKE IS OPEN ONLY WHILE THE WAVE IS COLLECTING (PART 8). `Preparing` means intake
     * has closed; preparation carries on, but no new order may enter, which is also what
     * freezes the wave's Required against new intake (PART 9). Accepting `Preparing` here
     * — as this did — is what let an order arriving at 08:01 still land in a wave whose
     * cutoff was 08:00 and inflate its demand mid-cycle.
     */
    public function attachEligibleOrders(
        PreparationWave $wave,
        WaveEngineConfiguration $config,
        string $actorId = 'system',
    ): int {
        if ($wave->status !== WaveStatus::Collecting) {
            return 0;
        }

        $orders = Order::where('company_id', $wave->company_id)
            ->where('assigned_warehouse_id', $wave->warehouse_id)
            ->whereIn('status', $config->eligible_order_statuses)
            // ACTIVE membership, not "has ever been a member" (PART 15).
            //
            // Without `released_at IS NULL` this excluded every order that had ever
            // joined any wave, of any date, in any status — so carry-over was impossible
            // and a single historical row removed an order from the engine permanently.
            //
            // A postponed row is NOT released, so it still excludes the order: postponing
            // must not be undone by the collector 60 seconds later (REFINEMENT-002).
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('preparation_wave_orders')
                ->whereColumn('preparation_wave_orders.order_id', 'orders.id')
                ->whereNull('preparation_wave_orders.released_at'),
            )
            ->get();

        $attached = 0;

        foreach ($orders as $order) {
            if ($this->attachOrder($wave, $order, $actorId) !== null) {
                $attached++;
            }
        }

        return $attached;
    }

    /**
     * Attach a single order to a wave.
     * Returns null (silently) when the order is already a member — idempotent by DB UNIQUE constraint.
     *
     * Collecting only: see attachEligibleOrders(). After intake closes, an eligible order
     * waits for the next cycle rather than joining this one.
     */
    public function attachOrder(
        PreparationWave $wave,
        Order $order,
        string $actorId = 'system',
    ): ?PreparationWaveOrder {
        if ($wave->status !== WaveStatus::Collecting) {
            return null;
        }

        try {
            $waveOrder = DB::transaction(function () use ($wave, $order, $actorId): PreparationWaveOrder {
                return PreparationWaveOrder::create([
                    'company_id' => $wave->company_id,
                    'preparation_wave_id' => $wave->id,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_confirmed_at' => $order->confirmed_at ?? now(),
                    'customer_name_snapshot' => $order->customer_name ?? null,
                    'delivery_zone_snapshot' => $order->delivery_zone ?? null,
                    'governorate_snapshot' => $order->governorate ?? null,
                    'zone_code_snapshot' => $order->zone_code ?? null,
                    'shipping_cost_snapshot' => $order->shipping_cost ?? null,
                    'is_paid' => in_array(
                        $order->payment_status instanceof BackedEnum
                            ? $order->payment_status->value
                            : (string) ($order->payment_status ?? ''),
                        ['paid', 'partially_paid'],
                        true,
                    ),
                    'payment_status_snapshot' => $order->payment_status instanceof BackedEnum
                        ? $order->payment_status->value
                        : ($order->payment_status ?? null),
                    'preparation_priority' => 5,
                    'added_at' => now(),
                    'added_by' => $actorId,
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return null;
        }

        $now = now()->toIso8601String();

        event(new OrderAddedToWave(
            waveId: $wave->id,
            waveNumber: $wave->wave_number,
            companyId: $wave->company_id,
            warehouseId: $wave->warehouse_id,
            orderId: $order->id,
            orderNumber: $order->order_number,
            waveStatus: $wave->status->value,
            addedBy: $actorId,
            addedAt: $now,
        ));

        // No OrderMovedToPreparing is published here any more: attachment can only happen
        // while the wave is Collecting, so a newly attached order is by definition not yet
        // in the preparation phase. It receives that event with everyone else when the
        // wave crosses its intake cutoff (WavePreparationService::startPreparation).

        $wave->increment('orders_count');

        $this->demandDispatcher->dispatch($wave, 'order_added', $actorId);

        return $waveOrder;
    }

    /**
     * Remove an order from a wave. Returns true if a row was deleted.
     */
    public function detachOrder(
        PreparationWave $wave,
        string $orderId,
        string $actorId = 'system',
        string $reason = 'manual',
    ): bool {
        $deleted = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->where('order_id', $orderId)
            ->delete();

        if ($deleted > 0) {
            event(new OrderRemovedFromWave(
                waveId: $wave->id,
                orderId: $orderId,
                companyId: $wave->company_id,
                warehouseId: $wave->warehouse_id,
                reason: $reason,
                removedBy: $actorId,
            ));

            $wave->decrement('orders_count');

            $this->demandDispatcher->dispatch($wave, 'order_removed', $actorId);
        }

        return $deleted > 0;
    }

    /**
     * Release an order from ACTIVE wave membership because it is no longer eligible.
     *
     * ┌─ RC-3: THE STALE-SNAPSHOT GAP THIS CLOSES ───────────────────────────────┐
     * │ `attachEligibleOrders()` evaluates eligibility ONCE, at insert. Nothing    │
     * │ re-checked it afterwards, so an order admitted while eligible stayed an    │
     * │ active member forever after it stopped being eligible.                     │
     * │                                                                          │
     * │ Measured live: ORD-00017 was attached at 21:50:00 while `confirmed`, and  │
     * │ demoted to `awaiting_payment` at 21:50:05 — a 13-second eligibility       │
     * │ window. It then sat in the active wave indefinitely, and was the entire   │
     * │ difference between Preparation's 12 and Distribution's 11.                 │
     * │                                                                          │
     * │ `released_at` was written by exactly two places, both wave-lifecycle      │
     * │ (WaveLifecycleService on close, CancelWaveAction on cancel). Neither      │
     * │ reacts to an order's own status changing.                                  │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * RELEASE, NOT DELETE. `detachOrder()` DELETES the membership row; this sets
     * `released_at`, which is the same mechanism wave close and cancel already use and
     * the same predicate `attachEligibleOrders()` reads. Three reasons it must be release:
     *
     *   1. AUDITABILITY. The row survives as history — who was in this cycle, and when
     *      they left it. A delete erases the only record that the order was ever admitted.
     *   2. RE-COLLECTION STILL WORKS. A released row does not block re-attachment (the
     *      collector's `whereNotExists` looks for an ACTIVE membership), so an order that
     *      becomes eligible again is picked up by the next sweep with no special case.
     *   3. `active_membership` IS A STORED GENERATED COLUMN derived from `released_at`, so
     *      the `uq_prep_wave_orders_company_order_active` index maintains itself. Nothing
     *      here writes it, and no second uniqueness rule is introduced.
     *
     * NOT `postponeOrder()` EITHER. Postponement means an operator deliberately deferred
     * work to a later cycle; this is the order becoming ineligible on its own. Conflating
     * them would put an operator's decision and a lifecycle consequence in one field, and
     * postponed rows carry their own distribution semantics.
     *
     * THIS NEVER TOUCHES `orders`. No status write, no transition, no cancellation — the
     * order is exactly as ineligible after this call as before it. Quoting
     * WaveLifecycleService on the same field: *"release is about membership, not about
     * order status."* Whatever should happen to the order's lifecycle is the payment or
     * fulfilment contract's business, not Preparation's.
     *
     * REUSES THE EXISTING EVENT. `OrderRemovedFromWave` already carries a `reason`, so the
     * audit trail needs no new event type and no new column.
     *
     * Idempotent: a row already released is not re-released, emits nothing, and does not
     * decrement the count twice.
     *
     * @param  string  $reason  machine-readable, e.g. `status_ineligible:awaiting_payment`
     * @return bool true only when THIS call performed the release
     */
    public function releaseIneligibleOrder(
        PreparationWave $wave,
        string $orderId,
        string $reason,
        string $actorId = 'system',
    ): bool {
        $released = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->where('order_id', $orderId)
            // The NULL check is the idempotency guarantee, asserted in the UPDATE itself
            // rather than pre-read: two concurrent status writes cannot both release.
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        if ($released === 0) {
            return false;
        }

        event(new OrderRemovedFromWave(
            waveId: $wave->id,
            orderId: $orderId,
            companyId: $wave->company_id,
            warehouseId: $wave->warehouse_id,
            reason: $reason,
            removedBy: $actorId,
        ));

        // Same bookkeeping detachOrder() performs, so the denormalised counter and the
        // demand projection stay in step with the membership they describe.
        $wave->decrement('orders_count');

        $this->demandDispatcher->dispatch($wave, 'order_removed', $actorId);

        return true;
    }

    /**
     * Postpone an order out of the CURRENT preparation cycle, without deleting anything.
     *
     * Deliberately NOT `detachOrder()`. That method deletes the membership row, which makes
     * `attachEligibleOrders()`'s `whereNotExists` true again — and `wave:run-scheduler` runs
     * every minute, so a "postponed" order would be re-attached to the same wave within 60
     * seconds. Retaining the row with `postponed_at` set keeps the collector excluding it
     * with no change to any eligibility rule, and preserves the membership as history.
     *
     * The order itself is untouched: no `orders` write, no status transition, no cancellation.
     * Postponement is a wave-membership decision, not an order-lifecycle one.
     *
     * Idempotent: a second call finds `postponed_at` already set, changes nothing, emits no
     * event and does not decrement the count again — it simply reports false.
     *
     * @return bool true only when this call performed the postponement
     */
    public function postponeOrder(
        PreparationWave $wave,
        string $orderId,
        string $actorId = 'system',
        string $reason = 'postponed',
    ): bool {
        // Scoped to this wave AND to a still-active row, so the update is the idempotency
        // guard itself — two concurrent calls cannot both match.
        $postponed = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->where('order_id', $orderId)
            ->whereNull('postponed_at')
            ->update(['postponed_at' => now()]);

        if ($postponed === 0) {
            return false;
        }

        event(new OrderRemovedFromWave(
            waveId: $wave->id,
            orderId: $orderId,
            companyId: $wave->company_id,
            warehouseId: $wave->warehouse_id,
            reason: $reason,
            removedBy: $actorId,
        ));

        $wave->decrement('orders_count');

        // The canonical route by which Product Demand, Raw Materials and Missing Materials
        // are recomputed from backend state — the same one detachOrder() uses.
        $this->demandDispatcher->dispatch($wave, 'order_postponed', $actorId);

        return true;
    }

    /**
     * Return a POSTPONED order to the wave's active membership.
     *
     * The exact inverse of postponeOrder(), and deliberately an UPDATE clearing
     * `postponed_at` — never an INSERT. Re-attaching by insert is structurally impossible:
     * `uq_preparation_wave_orders_wave_order` and `uq_prep_wave_orders_company_order_active`
     * both reject a second row while the original is unreleased, and attachOrder() swallows
     * that violation as a silent null. Clearing the flag restores the row the collector
     * already knows about, so no eligibility rule and no unique index is touched.
     *
     * CUTOFF IS NOT A MEMBERSHIP LOCK — TASK-OPERATIONS-PREPARATION-DEFERRED-ORDER-CUTOFF-
     * RETURN-001. This guard previously required `WaveStatus::Collecting`, which silently
     * conflated two different rules:
     *
     *   `intake_closes_at`  -> Collecting becomes Preparing. STOPS NEW ADMISSIONS ONLY.
     *   closeWave()         -> terminal status + `released_at` stamped. ENDS THE WAVE.
     *
     * An order postponed out of the preparation screen never stopped being a member of this
     * wave — its row is retained precisely so that stays true. Refusing its return because
     * intake closed punished it for a rule that governs orders trying to JOIN, and stranded
     * work that the operator had deliberately parked until stock arrived. New admissions are
     * still refused after cutoff, by attachOrder()/attachEligibleOrders(), which keep their
     * own Collecting-only guards and are untouched.
     *
     * Wave lifecycle is still respected, not routed around: a wave that has actually ENDED is
     * refused here. The approved path for such an order is the existing carry-over —
     * closeWave() stamps `released_at` on every row and the next cycle's
     * attachEligibleOrders() collects it automatically. This method never opens a closed wave,
     * never edits a cutoff and never writes `released_at` (that word belongs to closure alone).
     *
     * `released_at IS NULL` is part of the predicate because a released row belongs to a
     * finished cycle; un-postponing it would resurrect history rather than restore membership.
     *
     * The order itself is untouched: no `orders` write, no status transition. Returning is a
     * wave-membership decision, exactly as postponing is.
     *
     * Idempotent: an order that is not postponed matches nothing and reports false.
     *
     * @return bool true only when this call performed the return
     */
    public function returnPostponedOrder(
        PreparationWave $wave,
        string $orderId,
        string $actorId = 'system',
    ): bool {
        // Open, not "still collecting" — see the cutoff/close distinction above. `isTerminal()`
        // is the existing domain predicate for a wave that has ended (Completed, Cancelled,
        // Closed); no new state and no new concept is introduced.
        if ($wave->status->isTerminal()) {
            return false;
        }

        // Scoped to a postponed, still-unreleased row, so the update is its own idempotency
        // guard — two concurrent calls cannot both match.
        $returned = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->where('order_id', $orderId)
            ->whereNotNull('postponed_at')
            ->whereNull('released_at')
            ->update(['postponed_at' => null]);

        if ($returned === 0) {
            return false;
        }

        // The existing membership event — the order is once again part of this wave's work.
        // Constructed exactly as attachOrder() does; the order number comes off the retained
        // membership row, which is the whole point of postponement keeping it.
        $membership = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->where('order_id', $orderId)
            ->first();

        event(new OrderAddedToWave(
            waveId: $wave->id,
            waveNumber: $wave->wave_number,
            companyId: $wave->company_id,
            warehouseId: $wave->warehouse_id,
            orderId: $orderId,
            orderNumber: (string) ($membership->order_number ?? ''),
            waveStatus: $wave->status->value,
            addedBy: $actorId,
            addedAt: now()->toIso8601String(),
        ));

        $wave->increment('orders_count');

        // Same canonical recomputation route postponeOrder() uses, so Product Demand, Raw
        // Materials and Missing Materials all re-derive from backend state.
        $this->demandDispatcher->dispatch($wave, 'order_returned_to_preparation', $actorId);

        return true;
    }
}
