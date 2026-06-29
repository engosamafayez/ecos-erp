<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Exceptions\OrderNotFoundException;

/**
 * Transitions an order to the Preparing status and triggers manufacturing
 * for every eligible order line.
 *
 * PREPARE FLOW:
 *   1. Validate order exists
 *   2. Set order.status = preparing (committed immediately)
 *   3. Call PrepareOrderManufacturingAction — processes each line independently
 *   4. Return updated order
 *
 * IDEMPOTENCY:
 *   Calling prepare on an already-preparing order re-runs only lines that
 *   are not yet Executed (i.e., lines in Failed state are retried automatically).
 *
 * ERROR POLICY:
 *   Infrastructure exceptions propagate unchanged.
 *   Business failures (policy rejected, manufacturing blocked) are captured
 *   per-line in manufacturing_state without corrupting the order.
 */
final class PrepareOrderAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly PrepareOrderManufacturingAction $manufacturing,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');

        $order = $this->orders->findById($id);

        if ($order === null) {
            throw new OrderNotFoundException($id);
        }

        // Transition to Preparing (idempotent if already in this state)
        $order->update(['status' => OrderStatus::Preparing->value]);

        // Trigger manufacturing per line — no wrapping transaction intentionally;
        // partial success must be preserved so that retry works correctly.
        $this->manufacturing->execute($order);

        $updated = $this->orders->findById($id) ?? $order->refresh();

        return OperationResult::success($updated, 'Order moved to preparing. Manufacturing triggered for eligible lines.');
    }
}
