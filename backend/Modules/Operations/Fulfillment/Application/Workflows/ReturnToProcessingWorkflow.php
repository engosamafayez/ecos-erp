<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Returns a Preparing order back to Processing.
 *
 * Used when: preparation was started in error or the order needs to be
 * re-queued. Only valid when no line quantities have been packed yet.
 */
final class ReturnToProcessingWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        if ($ctx->order->status !== OrderStatus::Preparing) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] must be in Preparing status to return to Processing. Current: [{$ctx->order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        $order->update(['status' => OrderStatus::Processing]);
        $order->refresh();

        // H-3 fix: log the status reversion so the order's event history reflects it.
        // A dedicated OrderReturnedToProcessingEvent (domain event) is pending; this audit
        // entry ensures the transition is visible in the order timeline in the interim.
        OrderEvent::log(
            orderId:     $order->id,
            type:        'returned_to_processing',
            description: "Order #{$order->order_number} returned from Preparing to Processing" .
                          ($ctx->get('reason') ? ': ' . $ctx->get('reason') : '.'),
            payload:     ['actor_id' => $ctx->actorId],
            module:      'fulfillment',
        );

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} returned to Processing.",
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
        return 'return_to_processing';
    }
}
