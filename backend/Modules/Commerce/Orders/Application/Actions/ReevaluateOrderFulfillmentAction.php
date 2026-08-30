<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ReturnToPaymentWorkflow;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * THE canonical re-evaluation entry point after a payment fact changes.
 *
 * TASK-…-IMPLEMENTATION-001 (advance direction) + TASK-…-IMPLEMENTATION-002 (return direction).
 *
 * The orchestration defect this originally repaired: recording payment and verifying a payment
 * proof both updated financial facts and then stopped. Nothing re-evaluated the payment gate, so
 * an order could be fully paid, carry a VERIFIED proof, and still sit in `awaiting_payment`
 * forever — never reaching the statuses Preparation collects. Observed live on ORD-00003.
 *
 * ONE entry point, deliberately. Every trigger calls this same action:
 *
 *   - payment recorded            (RecordOrderPaymentAction)
 *   - payment proof verified      (VerifyPaymentProofAction)
 *   - payment method changed      (PatchOrderAction, UpdateOrderAction)   <- IMPLEMENTATION-002
 *
 * Two independently implemented transition paths would double the concurrency surface and make
 * the "at most one transition" guarantee untestable.
 *
 * TWO DIRECTIONS, ONE AUTHORITY (D1-A). A payment fact can now move in either direction, so the
 * re-evaluation must too:
 *
 *   awaiting_payment          + gate satisfied   -> ProcessOrderWorkflow    (advance)
 *   in_progress / confirmed   + gate unsatisfied -> ReturnToPaymentWorkflow (return)
 *
 * ADR-042 §7.1 / §3.1 (amended 2026-08-23, owner decision). The ADVANCE direction lands on
 * `in_progress`, NOT on `confirmed`: Confirm is an explicit operator action (§5 rule 3), and
 * "Payment Method Change != Order Confirmation". Neither direction confirms an order.
 *
 * The return direction is what makes the control mandatory rather than advisory: switching a
 * COD order to instapay must not leave it sitting in a fulfilment-eligible status on a payment
 * method whose proof was never supplied. Both directions run EXISTING workflows through the
 * EXISTING engine — no new state, no new workflow, no second engine, and no direct status write.
 *
 * WHAT THIS DOES NOT DO. It never writes Order.status. `ProcessOrderWorkflow` remains the sole
 * authority on advancing (its guard evaluates the allowed source statuses and the ADR-027
 * reservation rules), and `ReturnToPaymentWorkflow` — which already existed and already
 * releases held inventory — remains the sole authority on returning. This action only asks the
 * question again at a moment when the answer may have changed, and it asks
 * `PaymentFulfillmentGate`, the same single implementation `ConfirmOrderWorkflow` consults for
 * the explicit-operator path — so the automatic and the manual routes cannot drift apart on
 * whether payment permits fulfilment.
 *
 * IT NEVER CONFIRMS. `ConfirmOrderWorkflow` is deliberately no longer reachable from here.
 * `awaiting_payment` remains a legal Confirm SOURCE for the explicit operator action
 * (ADR-042 §6 as amended), and that path is untouched.
 *
 * CONCURRENCY. Unchanged from the certified model. The decision and the transition share one
 * transaction boundary and the order row is locked for the whole of it, reusing the pattern
 * established in ReserveOrderInventoryAction and WaveLifecycleService. A pre-check outside the
 * transaction would not be sufficient: record-payment, proof-verification and a payment-method
 * change can fire concurrently, and each would otherwise read the same stale status, all pass,
 * and all transition — producing duplicate events and duplicate audit rows.
 *
 * IDEMPOTENT. The status is re-read INSIDE the lock, so a second evaluation observes the
 * already-transitioned order and becomes a no-op. A gate that is still unsatisfied is likewise a
 * no-op: `WorkflowPreconditionException` means "not yet"/"not applicable", never "error", so the
 * payment fact that triggered the re-evaluation always stays committed.
 *
 * @param  mixed  ...$arguments  [0] = Order
 */
final class ReevaluateOrderFulfillmentAction extends BaseAction
{
    private const OUTCOME_NONE = 'none';

    private const OUTCOME_ADVANCED = 'advanced';

    private const OUTCOME_RETURNED = 'returned';

    public function __construct(
        private readonly FulfillmentEngine $engine,
        // ADR-042 §7.1: the ADVANCE direction runs ProcessOrderWorkflow (-> in_progress),
        // never ConfirmOrderWorkflow. Confirm stays an explicit operator action.
        private readonly ProcessOrderWorkflow $processWorkflow,
        private readonly ReturnToPaymentWorkflow $returnToPaymentWorkflow,
        private readonly PaymentFulfillmentGate $paymentGate,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var Order $order */
        $order = $arguments[0];
        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        $outcome = DB::transaction(function () use ($order, $actorId): string {
            // Row lock held for the decision AND the transition, so a concurrent trigger
            // waits here and then observes the committed state rather than racing it.
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();

            if ($locked === null) {
                return self::OUTCOME_NONE;
            }

            // ── Advance ──────────────────────────────────────────────────────────
            // ADR-042 §7.1 (amended 2026-08-23, owner decision): a payment-fact trigger
            // that satisfies the gate advances `awaiting_payment -> in_progress`. It must
            // NEVER automatically advance to `confirmed` — Confirm is an explicit operator
            // action (§5 rule 3), and a change to HOW a customer will pay is not a decision
            // THAT the order is confirmed.
            //
            // This previously ran ConfirmOrderWorkflow, which advanced straight to
            // `confirmed` in one hop. Live evidence of the defect: ORD-00019 went
            // `awaiting_payment -> confirmed` with `deposit_amount = 0.00`, one second after
            // a payment-method edit.
            //
            // ProcessOrderWorkflow is the correct vehicle: it already accepts
            // `AwaitingPayment` as a source, it writes `in_progress`, and it RESERVES —
            // which matters because the return direction released the reservation on the way
            // in. `ReturnToPendingWorkflow` was rejected for this transition precisely
            // because it RELEASES inventory (its docblock: "UNLOCK — returns an order to In
            // Progress, releasing its inventory reservation").
            if ($locked->status === OrderStatus::AwaitingPayment) {
                // The gate is consulted EXPLICITLY here, because ProcessOrderWorkflow's
                // guard does not evaluate it — unlike ConfirmOrderWorkflow's, which did.
                // This is not a second implementation of the rule: `permits()` is the very
                // method ConfirmOrderWorkflow::paymentPermitsConfirmation() delegates to, so
                // both paths still consult one authority.
                // ADVANCE decision -> permitsAdvance(): fails closed on a blank method, so
                // blanking cannot buy passage into fulfilment (BL-2-A). The RETURN branch
                // below deliberately keeps `permits()`, which stays permissive on a blank
                // method so a method-less order is never demoted merely for lacking one.
                if (! $this->paymentGate->permitsAdvance($locked)) {
                    return self::OUTCOME_NONE;
                }

                try {
                    $this->engine->run($this->processWorkflow, $locked, [], $actorId);

                    return self::OUTCOME_ADVANCED;
                } catch (WorkflowPreconditionException) {
                    // A precondition other than payment — e.g. a scheduled order whose
                    // activation date has not arrived. Left parked, not forced.
                    return self::OUTCOME_NONE;
                }
            }

            // ── Return ───────────────────────────────────────────────────────────
            // Already fulfilment-eligible, but the payment contract no longer permits it —
            // the order's payment method changed to one whose proof requirement is not met.
            // `fulfilmentEligible()` is the same closed list Preparation, Distribution and
            // the Wave Engine use (ADR-042 §7), so this is exactly the set of statuses from
            // which an order could otherwise be collected without satisfying the control.
            //
            // Orders already downstream (ready_for_dispatch and later) are NOT pulled back:
            // ReturnToPaymentWorkflow's own guard blocks them, and that exception is caught
            // here as a no-op. Unwinding physical execution is not a payment concern.
            if (in_array($locked->status, OrderStatus::fulfilmentEligible(), true)
                && ! $this->paymentGate->permits($locked)
            ) {
                try {
                    $this->engine->run($this->returnToPaymentWorkflow, $locked, [], $actorId);

                    return self::OUTCOME_RETURNED;
                } catch (WorkflowPreconditionException) {
                    return self::OUTCOME_NONE;
                }
            }

            return self::OUTCOME_NONE;
        });

        $fresh = $order->fresh();

        return OperationResult::success($fresh, match ($outcome) {
            self::OUTCOME_ADVANCED => 'Order advanced through the fulfillment workflow.',
            self::OUTCOME_RETURNED => 'Order returned to Awaiting Payment: its payment method requires verified payment proof.',
            default => 'Order re-evaluated; no lifecycle transition applied.',
        });
    }
}
