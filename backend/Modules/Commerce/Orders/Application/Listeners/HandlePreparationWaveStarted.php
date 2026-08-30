<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Application\Actions\PrepareOrderManufacturingAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToPreparationWorkflow;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;
use Throwable;

/**
 * Transitions all orders in a started wave into Ready for Dispatch AND triggers
 * made-to-order manufacturing for every eligible line.
 *
 * P6 fix: replaced direct $order->update(['status' => Preparing]) with
 * FulfillmentEngine::run(MoveToPreparationWorkflow) so that:
 *  - Inventory reservation is guaranteed before Preparing is set.
 *  - Orders with insufficient stock route to AwaitingStock instead of silently entering preparation.
 *  - All guard/audit/event contracts of the fulfillment pipeline are honoured.
 *  - One bad order does not halt the wave — errors are caught per-order and logged.
 *
 * BREAK B fix (TASK-MTO-MANUFACTURING-TRIGGER-GAP-DIAGNOSIS-001): the automated wave
 * is the real production path, yet it only reserved and never manufactured — the
 * canonical `PrepareOrderManufacturingAction` had a single caller, the MANUAL
 * `PrepareOrderAction`. So made-to-order finished goods were never produced into
 * warehouse stock for any wave-driven order. This listener now mirrors the manual
 * path exactly: after the order becomes Ready for Dispatch, it invokes the SAME
 * canonical action (no second engine, no duplicated logic). Manufacturing runs
 * AFTER the fulfilment transaction commits (never wrapped in it) so a per-line
 * manufacturing failure is captured as line state, not a wave-halting rollback.
 * The action's own idempotency guard (Executed lines are skipped) keeps repeated
 * wave execution from producing twice.
 */
final class HandlePreparationWaveStarted
{
    public function __construct(
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly MoveToPreparationWorkflow $moveToPreparation,
        private readonly PrepareOrderManufacturingAction $manufacturing,
    ) {}

    public function handle(WaveStarted $event): void
    {
        if (empty($event->orderIds)) {
            return;
        }

        // Guard: orders already at or past Preparing must not be regressed.
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

        // Load eligible orders via Eloquent (respects global company scope + soft deletes).
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

                // BREAK B: trigger made-to-order manufacturing exactly as the manual
                // PrepareOrderAction does — only when the order actually became Ready
                // for Dispatch. MoveToPreparationWorkflow can reroute to Awaiting Stock
                // (insufficient inventory), in which case there is no active position to
                // manufacture against and the trigger must NOT run.
                if ($result->order->status === OrderStatus::ReadyForDispatch) {
                    $this->manufacturing->execute($result->order);
                }
            } catch (Throwable $e) {
                Log::channel('daily')->error('[WaveActivation] Failed to transition order to Preparing via FulfillmentEngine', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'wave_id' => $waveId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
