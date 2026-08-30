<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;

/**
 * Verifies an UPLOADED payment proof. TASK-PAYMENT-PROOF-LIFECYCLE-001 §5.
 *
 *   UPLOADED → VERIFIED
 *
 * Records verifier + verified_at. Verification is evidence review only: it does
 * NOT write Order status, create a second payment, or modify deposit_amount — the
 * payment amount stays controlled by RecordOrderPaymentAction.
 *
 * SEPARATION OF DUTIES — `uploaded_by != verified_by`, enforced BY IDENTITY.
 *
 * The role catalog already splits the two verbs (`sales*` roles hold `proof_upload`; only
 * `company-admin` and `finance-manager` hold `proof_verify`/`proof_reject`, and no role holds
 * both). That split is a configuration fact, not a control: it holds only for as long as nobody
 * is assigned one role from each column, and `RequirePermissionMiddleware` passes any
 * `is_system` role — such as `super-admin` — unconditionally, so route middleware alone can
 * never establish that two different people were involved.
 *
 * The check therefore lives HERE, in the action, comparing user ids. Being in the action is what
 * makes it unbypassable: it is evaluated after the middleware has already let the actor through,
 * so a system role is subject to it exactly like every other actor. This mirrors the certified
 * supplier-payment control, which likewise rejects maker = approver by user id and likewise does
 * not exempt Super Admin.
 *
 * @param  mixed  ...$arguments  [0] = PaymentProof
 */
final class VerifyPaymentProofAction extends BaseAction
{
    public function __construct(
        private readonly ReevaluateOrderFulfillmentAction $reevaluate,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var PaymentProof $proof */
        $proof = $arguments[0];

        if ($proof->state !== PaymentProofState::Uploaded) {
            abort(422, 'Only an uploaded proof can be verified.');
        }

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        if ($proof->isSelfReviewBy($actorId)) {
            abort(403, 'Separation of duties: the user who uploaded a payment proof may not verify it. Verification must be performed by a different authorised reviewer.');
        }

        $proof->update([
            'state' => PaymentProofState::Verified,
            'verified_by' => $actorId,
            'verified_at' => now(),
        ]);

        OrderEvent::log(
            $proof->order_id,
            'payment_proof_verified',
            'Payment proof verified (evidence only — payment amount and Order status unchanged).',
            ['proof_id' => $proof->id],
            $actorId,
        );

        // The proof state change is committed, so re-ask the lifecycle question through the
        // SAME canonical entry point record-payment uses. Verification remains evidence-only:
        // it writes no payment amount and no Order status. ConfirmOrderWorkflow decides, and a
        // still-unsatisfied gate (e.g. verified proof but the balance is short) is a no-op.
        $order = Order::find($proof->order_id);

        if ($order !== null) {
            $this->reevaluate->execute($order);
        }

        return OperationResult::success($proof->fresh(), 'Payment proof verified.');
    }
}
