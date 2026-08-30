<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;

/**
 * Uploads a payment proof for an order. TASK-PAYMENT-PROOF-LIFECYCLE-001 §4/§7.
 *
 * Creates a first-class PaymentProof (state = UPLOADED) via the existing storage
 * abstraction. If an active proof already exists it is SUPERSEDED (retained as
 * history, never deleted) and this new proof becomes active — so this one action
 * covers both first upload and replacement.
 *
 * Uploading proof does NOT mark payment paid, change Order status, touch
 * deposit_amount, bypass ConfirmOrderWorkflow, or create/release a reservation.
 *
 * SUPERSESSION IS A PAYMENT FACT (ADR-042 §3.1 as amended; owner decision D1-A).
 * Condition 2 of the control names an ACTIVE verified proof — `superseded_at IS NULL` —
 * so replacing a proof can falsify it. Before this action re-evaluated, an order that had
 * reached `in_progress`/`confirmed` on a verified proof kept that status after the proof was
 * superseded, leaving it fulfilment-eligible with no active verified evidence. That was
 * reachable by the LOWEST-privileged role in the model: `sales` holds `proof_upload` and
 * nothing else, so a control that Finance had to clear could be undone without Finance.
 * The re-evaluation below closes it through the same canonical entry point every other
 * payment fact uses — no second gate, no new workflow, no direct status write.
 *
 * @param  mixed  ...$arguments  [0] = Order, [1] = UploadedFile
 */
final class UploadPaymentProofAction extends BaseAction
{
    public function __construct(
        private readonly ReevaluateOrderFulfillmentAction $reevaluate,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var Order $order */
        $order = $arguments[0];
        /** @var UploadedFile $file */
        $file = $arguments[1];

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        // PRIVATE disk — payment proofs are sensitive financial evidence and must be
        // served only through the tenant-scoped download endpoint, never a public URL.
        $disk = 'local';
        $ext = strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin'));
        $path = 'payment-proofs/'.$order->company_id.'/'.Str::ulid().'.'.$ext;

        // Store through the filesystem abstraction.
        Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));

        $proof = DB::transaction(function () use ($order, $file, $disk, $path, $actorId): PaymentProof {
            // Supersede the current active proof (retained, never deleted).
            $prevActive = PaymentProof::query()
                ->where('order_id', $order->id)
                ->whereNull('superseded_at')
                ->latest('uploaded_at')
                ->first();

            if ($prevActive !== null) {
                $prevActive->update(['superseded_at' => now()]);
            }

            return PaymentProof::query()->create([
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'state' => PaymentProofState::Uploaded,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                // getMimeType() sniffs the real content — client MIME is never trusted alone.
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $actorId,
                'uploaded_at' => now(),
                'replaces_proof_id' => $prevActive?->id,
            ]);
        });

        OrderEvent::log(
            $order->id,
            'payment_proof_uploaded',
            'Payment proof uploaded (evidence only — payment state unchanged).',
            ['proof_id' => $proof->id, 'replaces_proof_id' => $proof->replaces_proof_id],
            $actorId,
        );

        // Re-ask the lifecycle question ONLY when a proof was actually superseded, and only
        // after the supersession has committed and been audited — the same ordering
        // RecordOrderPaymentAction and VerifyPaymentProofAction use, so a transition it causes
        // is attributed to its own workflow rather than to this upload.
        //
        // A FIRST upload needs no re-evaluation and deliberately does not get one: the new proof
        // is created `uploaded`, never `verified`, so with no prior proof neither condition of
        // the control can have changed. Skipping it avoids taking an order row lock on the
        // common path.
        //
        // The action owns the concurrency contract (transaction + lockForUpdate + status re-read
        // inside the lock), so it is called OUTSIDE this action's own transaction. A gate that is
        // still satisfied, or an order too far downstream to pull back, is a no-op — the uploaded
        // evidence always stays committed either way.
        if ($proof->replaces_proof_id !== null) {
            $this->reevaluate->execute($order);
        }

        return OperationResult::success($proof, 'Payment proof uploaded.');
    }
}
