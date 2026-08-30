<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Domain\Enums\PaymentState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;

/**
 * Records a payment received against an order (partial or full).
 * TASK-WAREHOUSE-BRAND-PAYMENT-IMPLEMENTATION-001 §B2.
 *
 * Payment is captured through the EXISTING architecture: deposit_amount is the
 * cumulative amount received. The order becomes PAID only when the full order
 * total is received — a deposit stays PARTIALLY PAID. Overpayment beyond the
 * outstanding balance is rejected. This action NEVER writes Order.status;
 * lifecycle transitions remain the workflow's responsibility (P9).
 *
 * @param  mixed  ...$arguments  [0] = Order, [1] = float amount
 */
final class RecordOrderPaymentAction extends BaseAction
{
    public function __construct(
        private readonly ReevaluateOrderFulfillmentAction $reevaluate,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var Order $order */
        $order = $arguments[0];
        $amount = round((float) ($arguments[1] ?? 0), 2);

        if ($amount <= 0.0) {
            abort(422, 'Payment amount must be greater than zero.');
        }

        $total = round((float) $order->total, 2);
        $alreadyPaid = round((float) $order->deposit_amount, 2);
        $outstanding = round($total - $alreadyPaid, 2);

        // Idempotent: a fully-paid order accepts no further payment.
        if ($outstanding <= 0.0) {
            return OperationResult::success($order->fresh(), 'Order is already fully paid.');
        }

        // Overpayment guard — the existing contract records deposits, not credit balances.
        if ($amount - 0.001 > $outstanding) {
            abort(422, 'Payment amount exceeds the outstanding balance ('.number_format($outstanding, 2).').');
        }

        $newPaid = round($alreadyPaid + $amount, 2);
        $order->update(['deposit_amount' => $newPaid]);   // amount received — NOT status

        $state = PaymentState::fromAmounts($newPaid, $total);
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        OrderEvent::log(
            $order->id,
            'payment_recorded',
            'Payment of '.number_format($amount, 2).' recorded; order is now '.$state->value.'.',
            [
                'amount' => $amount,
                'total_paid' => $newPaid,
                'order_total' => $total,
                'payment_state' => $state->value,
            ],
            $actorId,
        );

        // The payment fact is now committed, so ask the lifecycle question again through the
        // ONE canonical entry point. This action still never writes Order.status: the
        // re-evaluation runs ConfirmOrderWorkflow, which re-applies the payment gate and every
        // other guard. If the gate is still unsatisfied the call is a safe no-op and the
        // recorded payment stands.
        $this->reevaluate->execute($order->fresh());

        return OperationResult::success($order->fresh(), 'Payment recorded. Order is now '.$state->value.'.');
    }
}
