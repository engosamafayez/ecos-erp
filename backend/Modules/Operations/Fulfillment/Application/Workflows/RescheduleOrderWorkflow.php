<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Reschedules an order to a future delivery date.
 *
 * Supported scenarios (ADR Part 4):
 *   A) Pending | AwaitingPayment → Rescheduled (no inventory change)
 *   B) Processing | AwaitingStock | Confirmed → Rescheduled (reservation kept)
 *   C) Preparing → Rescheduled (resume to Confirmed; product freshness policy)
 *   D) OutForDelivery → Rescheduled (inventory stays in Vehicle Warehouse)
 *   Also: Review | Returned → Rescheduled
 */
final class RescheduleOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        $blocked = [
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Rescheduled,
        ];

        if (in_array($order->status, $blocked, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be rescheduled from status [{$order->status->value}]."
            );
        }

        if (empty($ctx->get('next_delivery_date'))) {
            throw new WorkflowPreconditionException(
                'A next_delivery_date is required to reschedule an order.'
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order         = $ctx->order;
        $currentStatus = $order->status;

        // Determine the status to resume to after the rescheduled window:
        // Scenario C (Preparing) → resume to Confirmed (avoid stale prep data)
        // Scenario D (OutForDelivery) → resume to out_for_delivery (vehicle inventory unchanged)
        // All others → resume to the current status
        $defaultResume = match ($currentStatus) {
            OrderStatus::Preparing      => OrderStatus::Confirmed->value,
            OrderStatus::OutForDelivery => OrderStatus::OutForDelivery->value,
            default                     => $currentStatus->value,
        };

        $resumeFromStatus  = $ctx->get('resume_from_status') ?? $defaultResume;
        $nextDeliveryDate  = $ctx->get('next_delivery_date');
        $rescheduleReason  = $ctx->get('reschedule_reason') ?? $ctx->get('reason');

        $order->update([
            'status'             => OrderStatus::Rescheduled,
            'rescheduled_at'     => now(),
            'next_delivery_date' => $nextDeliveryDate,
            'resume_from_status' => $resumeFromStatus,
            'reschedule_reason'  => $rescheduleReason,
        ]);

        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} rescheduled for {$nextDeliveryDate}.",
            [
                'resume_from_status' => $resumeFromStatus,
                'next_delivery_date' => $nextDeliveryDate,
                'reason'             => $rescheduleReason,
                'actor_id'           => $ctx->actorId,
            ],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        return [];
    }

    public function name(): string
    {
        return 'reschedule_order';
    }
}
