<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Moves an order to Scheduled from any pre-execution state.
 *
 * TASK-ECOS-COMMERCE-ORDERS-BATCH-02-SCHEDULED-LIFECYCLE-002 (§4/§7) — this is
 * the workflow the generic `/fulfillment/orders/{order}/transition` endpoint
 * resolves to for any (early|reserved) → scheduled request (Orders grid's
 * SmartStatusSelector, the desktop detail page, and the detail drawer all call
 * that one endpoint). It now REQUIRES a genuinely future `requested_delivery_date`
 * and persists it — the SAME canonical column ActivateScheduledOrdersCommand and
 * ProcessOrderWorkflow's D-1 guard already read. Previously this workflow set
 * Scheduled with no date at all ("date updates can be handled separately via the
 * reschedule endpoint" — that endpoint's own guard blocks Scheduled as a SOURCE,
 * so an order Scheduled here could never actually reach it), which meant every
 * order Scheduled through this path activated as "due now" on the very next
 * nightly cron run regardless of intent.
 *
 * Deliberately NOT RescheduleOrderWorkflow, which is a different feature —
 * temporarily postponing an order ALREADY in flight and remembering which
 * status to resume to afterwards, via `next_delivery_date`/`resume_from_status`.
 * This workflow never touches those fields, only the canonical schedule field
 * and the lifecycle status. Any existing inventory reservation is preserved.
 */
final class MarkRescheduledWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        $blocked = [
            OrderStatus::Scheduled,          // already in this state
            OrderStatus::ReadyForDispatch,   // locked in execution chain
            OrderStatus::OutForDelivery,     // locked in execution chain
            OrderStatus::Delivered,          // locked in execution chain
            OrderStatus::Returned,           // handled by Returns workflow
            OrderStatus::Cancelled,          // terminal
        ];

        if (in_array($ctx->order->status, $blocked, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] cannot be rescheduled from status [{$ctx->order->status->value}].",
            );
        }

        // TASK-...-SCHEDULED-LIFECYCLE-002 (§7) — Scheduled with no schedule date
        // is not permitted. The controller already validates the format
        // (date_format:Y-m-d) when present, so a plain string comparison against
        // today's date is sufficient here — same idiom
        // CreateManualOrderAction::resolveManualOrderStatus() already uses for its
        // own future-date fallback.
        $requestedDeliveryDate = trim((string) ($ctx->get('requested_delivery_date') ?? ''));

        if ($requestedDeliveryDate === '') {
            throw new WorkflowPreconditionException(
                'A future requested delivery date is required to move this Order to Scheduled.',
            );
        }

        if ($requestedDeliveryDate <= now()->toDateString()) {
            throw new WorkflowPreconditionException(
                "The requested delivery date [{$requestedDeliveryDate}] must be in the future to schedule this Order.",
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;
        $requestedDeliveryDate = (string) $ctx->get('requested_delivery_date');

        $order->update([
            'status' => OrderStatus::Scheduled,
            'requested_delivery_date' => $requestedDeliveryDate,
        ]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} scheduled for {$requestedDeliveryDate}.",
            [
                'actor_id' => $ctx->actorId,
                'requested_delivery_date' => $requestedDeliveryDate,
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
        return 'mark_rescheduled';
    }
}
