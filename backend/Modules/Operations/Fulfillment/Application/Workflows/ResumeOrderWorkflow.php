<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Resumes an order from Rescheduled, Review, or AwaitingStock back to the operational workflow.
 *
 * For Rescheduled: restores to the stored resume_from_status (or Processing as safe default).
 * For Review: moves to Processing for further operational steps.
 * For AwaitingStock: moves to Processing (stock has become available).
 *
 * ADR Part 3 (Review exits: Processing) and Part 4 (Rescheduled resumption).
 */
final class ResumeOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        $allowed = [OrderStatus::Rescheduled, OrderStatus::Review, OrderStatus::AwaitingStock];

        if (! in_array($ctx->order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] can only be resumed from Rescheduled, Review, or AwaitingStock. Current: [{$ctx->order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        // For rescheduled orders: restore to stored resume status; default to Processing
        // For review/awaiting_stock orders: resume to Processing
        if ($order->status === OrderStatus::Rescheduled && $order->resume_from_status !== null) {
            $targetStatus = OrderStatus::from($order->resume_from_status);
        } else {
            $targetStatus = OrderStatus::Processing;
        }

        $order->update([
            'status'             => $targetStatus,
            'rescheduled_at'     => null,
            'next_delivery_date' => null,
            'resume_from_status' => null,
            'reschedule_reason'  => null,
        ]);

        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} resumed to [{$targetStatus->label()}].",
            ['target_status' => $targetStatus->value, 'actor_id' => $ctx->actorId],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        return [];
    }

    public function name(): string
    {
        return 'resume_order';
    }
}
