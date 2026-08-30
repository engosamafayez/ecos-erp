<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;

/**
 * Rejects an UPLOADED payment proof. TASK-PAYMENT-PROOF-LIFECYCLE-001 §6.
 *
 *   UPLOADED → REJECTED
 *
 * A validated free-text reason is required (no fixed enum — none exists in an
 * approved contract). Records rejector + rejected_at + reason. Rejection does NOT
 * mark payment paid, modify the payment amount, write Order status, or delete the
 * uploaded evidence — the rejected proof is retained.
 *
 * SEPARATION OF DUTIES — `uploaded_by != rejected_by`, enforced BY IDENTITY, exactly as on
 * verification. Reject is the other half of one reviewer capability (`proof_verify` and
 * `proof_reject` are granted together to `company-admin` and `finance-manager`, and to nobody
 * else), so exempting it would leave the submitter in control of half the review outcome space
 * and make the contract asymmetric for no reason. The rule itself lives on the model —
 * `PaymentProof::isSelfReviewBy()` — so there is one implementation, not two.
 *
 * @param  mixed  ...$arguments  [0] = PaymentProof, [1] = string reason
 */
final class RejectPaymentProofAction extends BaseAction
{
    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var PaymentProof $proof */
        $proof = $arguments[0];
        $reason = trim((string) ($arguments[1] ?? ''));

        if ($proof->state !== PaymentProofState::Uploaded) {
            abort(422, 'Only an uploaded proof can be rejected.');
        }

        if ($reason === '') {
            abort(422, 'A rejection reason is required.');
        }

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;

        if ($proof->isSelfReviewBy($actorId)) {
            abort(403, 'Separation of duties: the user who uploaded a payment proof may not reject it. Review must be performed by a different authorised reviewer.');
        }

        $proof->update([
            'state' => PaymentProofState::Rejected,
            'rejected_by' => $actorId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        OrderEvent::log(
            $proof->order_id,
            'payment_proof_rejected',
            'Payment proof rejected (evidence retained — payment amount and Order status unchanged).',
            ['proof_id' => $proof->id, 'reason' => $reason],
            $actorId,
        );

        return OperationResult::success($proof->fresh(), 'Payment proof rejected.');
    }
}
