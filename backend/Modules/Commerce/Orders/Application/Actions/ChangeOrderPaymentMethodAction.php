<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Exceptions\PaymentMethodChangeRejectedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;

/**
 * Change an order's payment method and re-evaluate fulfilment — the canonical orchestration
 * for that one transition, reused by the operator paths' authority rather than a second copy.
 *
 * TASK-DRIVER-APP-PHASE-4-PAYMENT-METHOD-CLOSURE-001.
 *
 * THE AUTHORITY IS REUSED, NOT DUPLICATED. This action does exactly what `PatchOrderAction`
 * and `UpdateOrderAction` do on a payment-method change — write `payment_method_manual`, then
 * call the ONE re-evaluation entry point `ReevaluateOrderFulfillmentAction` — but wraps them in
 * a single transaction and enforces a consistency invariant the operator paths leave implicit.
 * It defines no new fulfilment engine, no new gate, and never writes `Order.status`.
 *
 * WHY THE CONSISTENCY INVARIANT (§7/§8). `ReevaluateOrderFulfillmentAction` converts a blocked
 * demotion into a NO-OP SUCCESS (a WorkflowPreconditionException is caught and returned as
 * `OperationResult::success`). So for an order already in physical execution — the driver's case
 * is `out_for_delivery`, which is neither `awaiting_payment` nor in `fulfilmentEligible()` —
 * both re-evaluation branches are skipped and the method would otherwise commit onto an order
 * the gate forbids (a proof-required method with no verified proof, still fulfilling). A
 * wrapping transaction ALONE cannot catch that, because nothing throws. So after re-evaluation
 * we re-consult the SAME authority, `PaymentFulfillmentGate::permits()`, directly: the change is
 * consistent only if the gate now permits the order OR the order was parked at `awaiting_payment`
 * (demoted). Otherwise we throw — rolling back the write — so a new method is never committed
 * beside a stale fulfilment state.
 */
final class ChangeOrderPaymentMethodAction
{
    public function __construct(
        private readonly ReevaluateOrderFulfillmentAction $reevaluate,
        private readonly PaymentFulfillmentGate $gate,
    ) {}

    public function execute(Order $order, string $newMethod): OperationResult
    {
        $newMethod = trim($newMethod);

        return DB::transaction(function () use ($order, $newMethod): OperationResult {
            $current = trim((string) ($order->payment_method_manual ?? ''));

            // §12 idempotency — an unchanged method has no side effect and triggers no
            // re-evaluation, so a repeated (e.g. COD → COD) request cannot duplicate any
            // fulfilment effect.
            if ($current === $newMethod) {
                return OperationResult::success($order->fresh(), 'Payment method unchanged.');
            }

            // 1. Write the canonical method column. The gate reads `payment_method_manual`
            //    first (methodOf()), and the driver read exposes the same effective value.
            $order->update(['payment_method_manual' => $newMethod]);

            // 2. Canonical fulfilment re-evaluation — the SINGLE authority. It may advance
            //    (awaiting_payment → in_progress), demote (in_progress/confirmed →
            //    awaiting_payment, releasing inventory), or no-op. It never writes Order.status
            //    directly and never throws for a precondition — a blocked transition returns a
            //    successful no-op.
            $this->reevaluate->execute($order->refresh());

            $fresh = $order->fresh();

            // 3. Consistency invariant (§7/§8). See the class docblock: the no-op success above
            //    is not trusted. The order must end up either permitted by the gate, or parked
            //    at awaiting_payment. Anything else — a proof-required method left on a still
            //    fulfilling order the demotion could not reach — is rolled back and rejected.
            if (! $this->gate->permits($fresh) && $fresh->status !== OrderStatus::AwaitingPayment) {
                throw new PaymentMethodChangeRejectedException;
            }

            return OperationResult::success($fresh, 'Payment method updated.');
        });
    }
}
