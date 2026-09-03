<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReleaseOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;

/**
 * Places an order On Hold for manual intervention.
 *
 * V3 (TASK-ORDERS-LIFECYCLE-ARCH-002): Renamed from "Review" to "On Hold".
 * On Hold is a non-terminal hold state. Valid exits:
 *   → In Progress (via ProcessOrderWorkflow)
 *   → New        (via ReturnToPendingWorkflow)
 *   → Cancelled  (via CancelOrderWorkflow)
 *
 * TASK-...-BLOCKED-CUSTOMERS-009 (§15/§17/§20): the caller MAY pass
 * `hold_reason_code` in context — a machine-readable sub-reason persisted
 * alongside the status write (see the orders.hold_reason_code migration). When
 * it equals BlockedCustomerPolicy::HOLD_REASON_BLOCKED_CUSTOMER, an active
 * reservation is released through the canonical ReleaseOrderInventoryAction
 * (§20 forbids any direct mutation of reserved quantities). This release is
 * DELIBERATELY scoped to that one reason: every other On Hold caller (the
 * generic status-patch route, manual "Review" action, etc.) omits the flag and
 * sees no behaviour change — the long-standing "On Hold never releases
 * inventory" gap for those callers is reported, not silently fixed here, per
 * this task's own scope boundary (§47).
 */
final class MoveToReviewWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReleaseOrderInventoryAction $releaseInventory,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        $blocked = [
            OrderStatus::OnHold,           // already on hold
            OrderStatus::ReadyForDispatch, // locked in execution chain
            OrderStatus::OutForDelivery,   // locked in execution chain
            OrderStatus::Delivered,        // terminal
            OrderStatus::Returned,         // handled by Returns workflow
        ];

        if (in_array($order->status, $blocked, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be placed On Hold from status [{$order->status->value}].",
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;
        $reason = $ctx->get('reason');
        $holdReasonCode = $ctx->get('hold_reason_code');

        $order->update([
            'status' => OrderStatus::OnHold,
            'hold_reason_code' => $holdReasonCode,
        ]);
        $order->refresh();

        $reservationReleased = false;

        if ($holdReasonCode === BlockedCustomerPolicy::HOLD_REASON_BLOCKED_CUSTOMER
            && $order->assigned_warehouse_id !== null
            && $order->inventory_released_at === null
        ) {
            $this->releaseInventory->execute($order);
            $order->refresh();
            $reservationReleased = true;
        }

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} placed on hold.",
            [
                'reason' => $reason,
                'hold_reason_code' => $holdReasonCode,
                'reservation_released' => $reservationReleased,
                'actor_id' => $ctx->actorId,
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
        return 'put_on_hold';
    }
}
