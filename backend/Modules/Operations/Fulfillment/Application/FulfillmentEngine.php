<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;

/**
 * Central orchestrator for all fulfillment workflows.
 *
 * CONTRACT:
 *   1. guard()   — validates preconditions outside the transaction (fast-fail)
 *   2. execute() — runs inside DB::transaction (all-or-nothing)
 *   3. events()  — dispatched after commit (never rolled back)
 *   4. audit     — OrderEvent logged after commit
 *
 * Callers never touch inventory, status, or events directly.
 * All business consequences flow through a workflow.
 */
final class FulfillmentEngine
{
    /**
     * Run a workflow against an order.
     *
     * @param array<string, mixed> $data   Extra context for the workflow.
     */
    public function run(
        FulfillmentWorkflowInterface $workflow,
        Order $order,
        array $data = [],
        ?string $actorId = null,
    ): FulfillmentResult {
        $ctx = new FulfillmentContext($order, $data, $actorId);

        // 1. Preconditions — outside transaction so guard failures are cheap
        $workflow->guard($ctx);

        // 2. Execute inside transaction — single commit covers all mutations
        $result = DB::transaction(fn (): FulfillmentResult => $workflow->execute($ctx));

        // 3. Events — after commit so they are never rolled back
        foreach ($workflow->events($result) as $event) {
            event($event);
        }

        // 4. Audit trail
        OrderEvent::log(
            orderId:     $result->order->id,
            type:        $workflow->name(),
            description: $result->message,
            payload:     $result->meta,
            actorId:     $actorId,
        );

        return $result;
    }
}
