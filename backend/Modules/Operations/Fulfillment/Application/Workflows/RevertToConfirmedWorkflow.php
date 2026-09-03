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
 * Returns a Processing / AwaitingStock / Review order back to Confirmed.
 *
 * Used when: the order was moved out of Confirmed prematurely and no
 * preparation work has started. Inventory reservation is preserved; only
 * the status is rolled back.
 */
final class RevertToConfirmedWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        // TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-FINAL-CLOSURE-011 (§3/§4). The SAME
        // single read authority ProcessOrderWorkflow/ConfirmOrderWorkflow already
        // consult — see the guard below. This route (POST .../revert-to-confirmed)
        // previously had no knowledge of an active Customer block at all.
        private readonly BlockedCustomerPolicy $blockedCustomerPolicy,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $allowed = [
            OrderStatus::InProgress,
            OrderStatus::AwaitingStock,
            OrderStatus::OnHold,
        ];

        if (! in_array($ctx->order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] must be in InProgress, AwaitingStock, or OnHold to revert. Current: [{$ctx->order->status->value}].",
            );
        }

        // TASK-...-FINAL-CLOSURE-011 (§3/§4) — mirrors ProcessOrderWorkflow::guard()'s
        // identical check verbatim. Checked regardless of WHY the order is on hold: if
        // the Customer/phone is currently blocked, this route must not revert it out of
        // On Hold either — only a canonical one-order override (or the block being
        // lifted) may.
        if ($ctx->order->status === OrderStatus::OnHold && $this->blockedCustomerPolicy->isOrderBlocked($ctx->order)) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] cannot revert to confirmed: its Customer/phone is currently blocked. Grant a one-order override to proceed with just this Order.",
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        $order->update(['status' => OrderStatus::InProgress]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} reverted to In Progress.",
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
        return 'revert_to_confirmed';
    }
}
