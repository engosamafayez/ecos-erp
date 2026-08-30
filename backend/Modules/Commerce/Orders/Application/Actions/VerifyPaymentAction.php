<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;

/**
 * Legacy compatibility endpoint for POST /orders/{order}/verify-payment.
 *
 * ADR-042 §7.1 (amended 2026-08-23) — a payment-fact re-evaluation advances
 *   awaiting_payment -> in_progress,  NEVER  awaiting_payment -> confirmed.
 * Confirmation is the explicit operator action of §5 rule 3 only.
 *
 * D1 REMEDIATION (TASK-ECOS-ADR-042-TARGETED-REMEDIATION-001). This action USED to run
 * ConfirmOrderWorkflow directly, which auto-advanced a paid, proof-verified order straight to
 * `confirmed` — a §7.1 violation and a second, competing transition authority alongside the
 * canonical payment-proof-verify flow. It is now a THIN ADAPTER that delegates to
 * {@see ReevaluateOrderFulfillmentAction} — THE canonical re-evaluation entry point that
 * record-payment and payment-proof-verify already use. That authority advances via
 * ProcessOrderWorkflow (-> in_progress), consults the single PaymentFulfillmentGate, writes no
 * Order.status directly, and is an idempotent no-op when the gate is not yet satisfied. No second
 * engine, no duplicated gate, and no direct status write here.
 *
 * For a proof-required method this endpoint is redundant with POST /orders/{order}/payment-proofs
 * + POST /payment-proofs/{proof}/verify, which re-evaluate the gate on their own; it is retained
 * only for response/API compatibility. Non-proof methods (cod/cash/credit_card) are unaffected.
 */
final class VerifyPaymentAction extends BaseAction
{
    public function __construct(
        // ADR-042 §7.1: the canonical re-evaluation authority. Its ADVANCE direction runs
        // ProcessOrderWorkflow (-> in_progress); ConfirmOrderWorkflow is deliberately unreachable
        // from it, so this endpoint can no longer auto-confirm.
        private readonly ReevaluateOrderFulfillmentAction $reevaluate,
    ) {}

    /**
     * @param  mixed  ...$arguments  [0] = Order model, [1] = optional proof path string
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var Order $order */
        $order = $arguments[0];
        $proofPath = isset($arguments[1]) && $arguments[1] !== '' ? (string) $arguments[1] : null;

        if ($order->status !== OrderStatus::AwaitingPayment) {
            abort(422, 'Order must be in Awaiting Payment status to verify payment.');
        }

        // A non-status DISPLAY field only (validated nullable|string|max:500). Since
        // TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-001 it carries NO gate
        // authority: the canonical gate reads `payment_proofs` rows, so a path string alone can
        // never clear a proof-required gate. Real evidence is uploaded through
        // POST /orders/{order}/payment-proofs and accepted through POST /payment-proofs/{proof}/verify.
        if ($proofPath !== null) {
            $order->update(['payment_proof_path' => $proofPath]);
            $order->refresh();
        }

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        // ADR-042 §7.1: delegate to THE canonical re-evaluation authority. A satisfied gate
        // advances awaiting_payment -> in_progress (via ProcessOrderWorkflow); it never confirms,
        // and an unsatisfied gate is a no-op. This action no longer holds any transition authority
        // of its own.
        $result = $this->reevaluate->execute($order);

        $fresh = $order->fresh();
        OrderEvent::log(
            $order->id,
            'payment_verified',
            'Payment re-evaluated via the canonical fulfilment gate (ADR-042 §7.1: advances to In Progress, never Confirmed).',
            ['proof_path' => $proofPath, 'to_status' => $fresh?->status->value],
            $actorId,
        );

        return OperationResult::success($fresh, $result->message !== '' ? $result->message : 'Payment verified.');
    }
}
