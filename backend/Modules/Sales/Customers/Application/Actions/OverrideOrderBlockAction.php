<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Application\Actions\ReevaluateOrderFulfillmentAction;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Sales\Customers\Domain\Models\OrderBlockOverride;
use Modules\Sales\Customers\Domain\Services\BlockedCustomerPolicy;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§25-§27).
 *
 * Grants a ONE-ORDER override: the Customer/phone remains blocked (§26/§27 — this
 * is never implemented as unblock-then-reblock, which would destroy audit
 * semantics and open a race window), only the named Order becomes eligible for
 * canonical reevaluation. §26 names the exact mechanism: after recording the
 * grant, invoke ReevaluateOrderFulfillmentAction — never a manual reserve/
 * confirm/prepare call from here, and never a second engine.
 *
 * CONCURRENCY (§39-C). `order_block_overrides.order_id` is UNIQUE — a second
 * concurrent grant attempt for the same Order loses the race on that index
 * (caught below and treated as "already granted", not an error), so two
 * concurrent overrides can never leave conflicting effective state.
 */
final class OverrideOrderBlockAction extends BaseAction
{
    public function __construct(
        private readonly BlockedCustomerPolicy $policy,
        private readonly ReevaluateOrderFulfillmentAction $reevaluate,
    ) {}

    /**
     * @param  mixed  ...$arguments  [0] Order (already tenant-scoped by the caller), [1] reason, [2] ?actorId
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var Order $order */
        $order = $arguments[0];
        $reason = trim((string) $arguments[1]);
        $actorId = $arguments[2] ?? null;

        $activeBlock = $this->policy->activeBlockForOrder($order);

        DB::transaction(function () use ($order, $activeBlock, $reason, $actorId): void {
            $existing = OrderBlockOverride::query()->where('order_id', $order->id)->first();

            if ($existing !== null) {
                return;
            }

            try {
                OrderBlockOverride::create([
                    'order_id' => $order->id,
                    'company_id' => $order->company_id,
                    'customer_block_id' => $activeBlock?->id,
                    'granted_by' => $actorId,
                    'reason' => $reason,
                    'granted_at' => now(),
                    'created_at' => now(),
                ]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
                // Lost the race to a concurrent override grant for this same Order —
                // the winner's row already carries the audit record. No-op.
            }
        });

        // §26 — the ONLY mechanism this task's own instruction names. It re-derives
        // the decision from scratch (ProcessOrderWorkflow's guard re-checks the
        // block); nothing is reserved/confirmed/prepared directly here.
        $result = $this->reevaluate->execute($order->fresh());

        return OperationResult::success($result->data(), 'Override granted; order re-evaluated.');
    }
}
