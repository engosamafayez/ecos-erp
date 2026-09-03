<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;

/**
 * Resumes an order from On Hold or Awaiting Stock back to In Progress.
 *
 * V3 (TASK-ORDERS-LIFECYCLE-ARCH-002): Rescheduled is removed; OnHold replaces Review.
 */
final class ResumeOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        // TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-FINAL-CLOSURE-011 (§3/§4). The SAME
        // single read authority ProcessOrderWorkflow/ConfirmOrderWorkflow already
        // consult — see the guard below. This route (POST .../resume, and its bulk
        // equivalent) previously had no knowledge of an active Customer block at all.
        private readonly BlockedCustomerPolicy $blockedCustomerPolicy,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $allowed = [OrderStatus::OnHold, OrderStatus::AwaitingStock];

        if (! in_array($ctx->order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] can only be resumed from On Hold or Awaiting Stock. Current: [{$ctx->order->status->value}].",
            );
        }

        // TASK-...-FINAL-CLOSURE-011 (§3/§4) — mirrors ProcessOrderWorkflow::guard()'s
        // identical check verbatim. Checked regardless of WHY the order is on hold: if
        // the Customer/phone is currently blocked, this route must not resume it either
        // — only a canonical one-order override (or the block being lifted) may.
        if ($ctx->order->status === OrderStatus::OnHold && $this->blockedCustomerPolicy->isOrderBlocked($ctx->order)) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] cannot resume: its Customer/phone is currently blocked. Grant a one-order override to proceed with just this Order.",
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        $order->update([
            'status'             => OrderStatus::InProgress,
            'rescheduled_at'     => null,
            'next_delivery_date' => null,
            'resume_from_status' => null,
            'reschedule_reason'  => null,
        ]);

        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} resumed to In Progress.",
            ['actor_id' => $ctx->actorId],
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
