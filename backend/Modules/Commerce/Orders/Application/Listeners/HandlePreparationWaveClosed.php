<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\DemandAnalysis\Application\Services\OrderPreparationCompletionReader;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnToProcessingWorkflow;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Throwable;

/**
 * Decides what happens to every order in a wave when its operational cycle ends — G-4.
 *
 * Wave end used to have NO order-side consequence at all. `WaveClosed` was published and
 * subscribed only by a documented no-op in DemandAnalysis, so orders simply kept whatever
 * status they held — in practice `ready_for_dispatch`, which the wave engine sets when
 * preparation STARTS. Nothing carried anything over, and nothing said so.
 *
 * The three cases, exactly as the contract states them:
 *
 *   CASE A — already in the shipping lifecycle → LEAVE UNTOUCHED.
 *            Shipped, delivered, returned or cancelled orders are none of Preparation's
 *            business once they have left it.
 *
 *   CASE B — fully preparation-complete (G-1) but not shipped → LEAVE UNTOUCHED.
 *            The work is done; returning it would re-prepare finished goods, duplicate
 *            demand in the next wave and strand a second reservation. It stays
 *            Ready for Dispatch, which is both correct and self-enforcing: that status is
 *            not fulfilment-eligible, so the collector cannot pick it up again.
 *
 *   CASE C — not shipped and not fully prepared → RETURN TO IN PROGRESS,
 *            through ReturnToProcessingWorkflow. In Progress IS fulfilment-eligible, so
 *            the next cycle collects it automatically — that is the carry-over, and it
 *            needs no special-case path of its own.
 *
 * The status write goes through the canonical workflow, never `$order->update(['status'…])`:
 * the workflow owns the guard, the OrderEvent audit line and the engine contract.
 * ReturnToProcessingWorkflow also deliberately does NOT release inventory — the
 * reservation stays with the order across the wave boundary, which is what keeps
 * carry-over from producing a second reservation (PART 22).
 *
 * Orders in some other state (`awaiting_stock` is the live example — preparation start
 * could not reserve for them) are left to the lifecycle that owns them: the workflow
 * guard would reject them anyway, and Inventory re-evaluates them when stock arrives.
 * They are counted and logged, never forced.
 */
final class HandlePreparationWaveClosed
{
    public function __construct(
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly ReturnToProcessingWorkflow $returnToProcessing,
        private readonly OrderPreparationCompletionReader $completion,
    ) {}

    public function handle(WaveClosed $event): void
    {
        // Every member of the ended cycle, including postponed and just-released rows:
        // the membership row is history now, but the ORDER behind it still needs a
        // decision.
        $orderIds = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $event->waveId)
            ->pluck('order_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($orderIds === []) {
            return;
        }

        $fullyPrepared = array_flip(
            $this->completion->fullyPreparedOrderIds($event->waveId, $orderIds),
        );

        // Terminal or shipping-side states — CASE A.
        $shippingLifecycle = [
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
            OrderStatus::Returned,
            OrderStatus::Cancelled,
        ];

        $returned = 0;
        $keptPrepared = 0;
        $keptShipping = 0;
        $keptOther = 0;

        $orders = Order::query()
            ->where('company_id', $event->companyId)
            ->whereIn('id', $orderIds)
            ->get();

        foreach ($orders as $order) {
            // CASE A — in or past shipping.
            if (in_array($order->status, $shippingLifecycle, true) || $order->inventory_shipped_at !== null) {
                $keptShipping++;

                continue;
            }

            // CASE B — done, waiting to be loaded.
            if (isset($fullyPrepared[$order->id])) {
                $keptPrepared++;

                continue;
            }

            // CASE C — unfinished work carries over. Only from the one status the
            // canonical workflow accepts; anything else belongs to another lifecycle.
            if ($order->status !== OrderStatus::ReadyForDispatch) {
                $keptOther++;

                continue;
            }

            try {
                $this->fulfillmentEngine->run(
                    $this->returnToProcessing,
                    $order,
                    ['wave_id' => $event->waveId, 'reason' => 'wave_ended'],
                    $event->closedBy,
                );
                $returned++;
            } catch (Throwable $e) {
                Log::channel('daily')->error('[WaveEnd] Failed to return order to In Progress', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'wave_id' => $event->waveId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[WaveEnd] Wave closed — order carry-over resolved', [
            'wave_id' => $event->waveId,
            'wave_number' => $event->waveNumber,
            'reason' => $event->reason,
            'members' => count($orderIds),
            'returned_to_in_progress' => $returned,
            'kept_fully_prepared' => $keptPrepared,
            'kept_shipping_lifecycle' => $keptShipping,
            'kept_other_lifecycle' => $keptOther,
        ]);
    }
}
