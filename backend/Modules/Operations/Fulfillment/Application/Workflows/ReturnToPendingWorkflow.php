<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReleaseOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * UNLOCK — returns an order to In Progress, releasing its inventory reservation.
 *
 * ADR-042 §5.4. This is the successor to the V3 `return_to_new` action. The target
 * moved from `new` to `in_progress` because ADR-042 removes `new` and transfers the
 * unlocked entry role to `in_progress` (§2.2) — so this workflow's PURPOSE is
 * unchanged: release the order for structural edits (products, address, customer).
 *
 * The canonical use is `confirmed → in_progress`: undoing a confirmation. The
 * remaining sources are recovery paths that were already permitted.
 *
 * The class name is retained (it predates both V3 and ADR-042) to keep the diff
 * honest; the action key it publishes is `return_to_in_progress`.
 */
final class ReturnToPendingWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReleaseOrderInventoryAction $releaseInventory,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $allowed = [
            OrderStatus::Confirmed,
            OrderStatus::AwaitingPayment,
            OrderStatus::AwaitingStock,
            OrderStatus::OnHold,
            OrderStatus::Cancelled,
            OrderStatus::Scheduled,
        ];

        if (! in_array($ctx->order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] cannot return to In Progress from status [{$ctx->order->status->value}].",
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;
        $released = false;

        if ($order->assigned_warehouse_id !== null && $order->inventory_released_at === null && $order->inventory_reserved_at !== null) {
            $this->releaseInventory->execute($order);
            $released = true;
        }

        // DRIFT-009 fix: clear reservation_status so it does not stay stale as 'Released'.
        // H-4 fix: also clear partial_reservation_approved_at — shortage profile may change.
        $order->update([
            'status' => OrderStatus::InProgress,
            // Unlocking undoes the confirmation, so the stamp must not survive it.
            'confirmed_at' => null,
            'inventory_reserved_at' => null,
            'inventory_released_at' => null,
            'reservation_status' => null,
            'reservation_failure_reason' => null,
            'partial_reservation_approved_at' => null,
        ]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} returned to In Progress.".($released ? ' Inventory reservation released.' : ''),
            [
                'inventory_released' => $released,
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
        return 'return_to_in_progress';
    }
}
