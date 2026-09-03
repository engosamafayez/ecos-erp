<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\MoveToReviewWorkflow;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§16-§19).
 *
 * Evaluates a newly-blocked Customer's EXISTING non-terminal Orders and classifies
 * each using the existing ADR-042 helpers — never a hardcoded status list:
 *
 *   - isTerminal()        → C. terminal, unchanged (§19).
 *   - hasLeftPreparation() → B. physical execution — preserved, exception recorded,
 *                             never rolled back (§18). (Delivered is excluded here
 *                             because isTerminal() already caught it above.)
 *   - everything else      → A. safe/recoverable — moved to On Hold through the
 *                             canonical transition authority (MoveToReviewWorkflow
 *                             via FulfillmentEngine), which releases any active
 *                             reservation through ReleaseOrderInventoryAction (§17/
 *                             §20 — see that workflow's own hold_reason_code branch).
 *
 * Only Orders belonging to a REAL Customer are swept: `customer_id === null` means
 * this is a phone-before-Customer block (§4/§10), and every Order in this system
 * always carries a resolved customer_id, so no Order can exist yet for that
 * identity — nothing to sweep, by construction, not by special-casing.
 *
 * Called from inside BlockCustomerOrPhoneAction's own transaction so a block is
 * never left half-applied to its Customer's open Orders.
 */
final class ApplyBlockToExistingOrdersAction
{
    public function __construct(
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly MoveToReviewWorkflow $reviewWorkflow,
    ) {}

    public function execute(CustomerBlock $block): void
    {
        if ($block->customer_id === null) {
            return;
        }

        $orders = Order::query()
            ->where('company_id', $block->company_id)
            ->where('customer_id', $block->customer_id)
            ->get();

        foreach ($orders as $order) {
            $this->applyTo($order, $block);
        }
    }

    private function applyTo(Order $order, CustomerBlock $block): void
    {
        if ($order->status->isTerminal()) {
            return;
        }

        if ($order->status === OrderStatus::OnHold) {
            // Already excluded from fulfilment/Preparation/Distribution. Left as-is
            // rather than re-running the workflow — its guard() would reject a
            // same-status transition anyway, and any existing hold_reason_code (from
            // an unrelated cause) is not this action's to overwrite.
            return;
        }

        if ($order->status->hasLeftPreparation()) {
            OrderEvent::log(
                orderId: $order->id,
                type: 'blocked_customer_exception_physical_execution',
                description: "Order #{$order->order_number} is beyond the safe hold boundary (status [{$order->status->value}]) — NOT moved to On Hold for the new Customer block. Requires manual review.",
                payload: ['status' => $order->status->value, 'customer_block_id' => $block->id],
                actorId: $block->blocked_by,
                module: 'orders',
            );

            return;
        }

        $this->fulfillmentEngine->run(
            $this->reviewWorkflow,
            $order,
            [
                'reason' => $block->block_reason,
                'hold_reason_code' => BlockedCustomerPolicy::HOLD_REASON_BLOCKED_CUSTOMER,
            ],
            $block->blocked_by,
        );
    }
}
