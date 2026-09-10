<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Events\TripCashHandoverConfirmed;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\CompleteOrderWorkflow;
use Throwable;

/**
 * TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 §C/§K — the bridge from a
 * Trip-grain Treasury fact (cash physically handed over and confirmed) to the
 * per-Order lifecycle: every Order that was actually DELIVERED on that trip may
 * now advance from Delivered to the new terminal FinalCash status.
 *
 * Same cross-module direction convention as {@see HandlePreparationWaveClosed}
 * (Orders reacts to a Distribution event; Distribution knows nothing of Orders) —
 * a raw `DB::table()` read of `distribution_trip_orders`, never an Eloquent import
 * of a Distribution model, keeps the coupling to "reads one column," not a shared
 * domain object.
 *
 * IDEMPOTENT BY STATUS FILTER, not a processed-event ledger — the same pattern
 * every closure-like operation in this codebase already uses (see
 * `WaveClosureCustodyService`, `ReleaseOrderOnRetryableOutcomeListener`): only
 * Orders currently AT `Delivered` are candidates, so a replayed event (this one
 * is only ever dispatched once per handover, per
 * {@see \Modules\Logistics\Distribution\Domain\Services\CashHandoverService::confirmReceipt()}'s
 * own `$isNew` guard, but a second layer of safety costs nothing here) finds
 * nothing left to advance.
 *
 * ONLY THE ACTIVE TRIP-ORDER LINK COUNTS (`superseded_at IS NULL`). An Order that
 * failed on THIS trip, was released, and was later delivered on a DIFFERENT trip
 * must never be advanced by THIS trip's handover — its real cash was collected
 * (or not) on the other trip, which carries its own, separate handover fact.
 */
final class HandleTripCashHandoverConfirmed
{
    public function __construct(
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly CompleteOrderWorkflow $completeOrder,
    ) {}

    public function handle(TripCashHandoverConfirmed $event): void
    {
        $orderIds = DB::table('distribution_trip_orders')
            ->where('trip_id', $event->tripId)
            ->whereNull('superseded_at')
            ->pluck('order_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($orderIds === []) {
            return;
        }

        $orders = Order::query()
            ->where('company_id', $event->companyId)
            ->whereIn('id', $orderIds)
            ->where('status', OrderStatus::Delivered->value)
            ->get();

        $advanced = 0;

        foreach ($orders as $order) {
            try {
                $this->fulfillmentEngine->run(
                    $this->completeOrder,
                    $order,
                    [
                        'trip_id' => $event->tripId,
                        'cash_handover_id' => $event->handoverId,
                        'reason' => 'trip_cash_handover_confirmed',
                    ],
                    (string) $event->confirmedBy,
                );
                $advanced++;
            } catch (Throwable $e) {
                // Never allowed to lose the physical cash fact this event announces —
                // the handover is already durably committed regardless of this loop.
                // A stranded Delivered order here is a recoverable, visible defect
                // (findable by re-querying Delivered orders on this trip), not a
                // financial one.
                Log::channel('daily')->error('[TripCashHandoverConfirmed] Failed to advance order to Final Cash', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'trip_id' => $event->tripId,
                    'cash_handover_id' => $event->handoverId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[TripCashHandoverConfirmed] Trip cash handover confirmed — orders advanced to Final Cash', [
            'trip_id' => $event->tripId,
            'cash_handover_id' => $event->handoverId,
            'candidates' => count($orderIds),
            'advanced_to_final_cash' => $advanced,
        ]);
    }
}
