<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Application\Actions\PrepareOrderManufacturingAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToPreparationWorkflow;
use Modules\Operations\Preparation\Domain\Events\WavePreparationStarted;
use Throwable;

/**
 * Transitions all orders in an engine-driven wave into Ready for Dispatch AND triggers
 * made-to-order manufacturing for every eligible line.
 *
 * C-1 fix: WavePreparationService (engine path) fires WavePreparationStarted, NOT WaveStarted.
 * Previously there was no listener for this event, so the engine-path wave start never
 * transitioned individual orders to Preparing. This listener closes that gap.
 *
 * Mirrors HandlePreparationWaveStarted but handles the WavePreparationStarted event.
 *
 * BREAK B fix (TASK-MTO-MANUFACTURING-TRIGGER-GAP-DIAGNOSIS-001): the automated
 * WaveEngine scheduler path fires THIS event, so — exactly like its WaveStarted sibling —
 * it must reach the canonical made-to-order manufacturing trigger after the order becomes
 * Ready for Dispatch. Reuses the SAME PrepareOrderManufacturingAction (no second engine,
 * no duplicated logic); the action's idempotency guard keeps repeated scheduler runs from
 * producing twice.
 */
final class HandlePreparationWavePreparationStarted
{
    public function __construct(
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly MoveToPreparationWorkflow $moveToPreparation,
        private readonly PrepareOrderManufacturingAction $manufacturing,
    ) {}

    public function handle(WavePreparationStarted $event): void
    {
        if (empty($event->orderIds)) {
            return;
        }

        $terminalStatuses = [
            OrderStatus::ReadyForDispatch->value,
            OrderStatus::OutForDelivery->value,
            OrderStatus::Delivered->value,
            OrderStatus::Cancelled->value,
            OrderStatus::Returned->value,
        ];

        $actorId = $event->startedBy;
        $waveId = $event->waveId;
        $waveNumber = $event->waveNumber;

        $orders = Order::query()
            ->where('company_id', $event->companyId)
            ->whereIn('id', $event->orderIds)
            ->whereNotIn('status', $terminalStatuses)
            ->get();

        foreach ($orders as $order) {
            try {
                $result = $this->fulfillmentEngine->run(
                    $this->moveToPreparation,
                    $order,
                    ['wave_id' => $waveId, 'wave_number' => $waveNumber],
                    $actorId,
                );

                // BREAK B: trigger made-to-order manufacturing only when the order
                // actually became Ready for Dispatch (Awaiting Stock reroute has no
                // active position to manufacture against). Same canonical action as
                // the manual PrepareOrderAction and the WaveStarted sibling.
                if ($result->order->status === OrderStatus::ReadyForDispatch) {
                    $this->manufacturing->execute($result->order);
                }
            } catch (Throwable $e) {
                Log::channel('daily')->error('[WavePreparationEngine] Failed to transition order to Preparing', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'wave_id' => $waveId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
