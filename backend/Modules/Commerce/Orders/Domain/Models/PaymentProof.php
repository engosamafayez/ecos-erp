<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Commerce\Orders\Domain\Enums\PaymentProofState;

/**
 * A payment proof — evidence for an electronic payment on an order.
 * TASK-PAYMENT-PROOF-LIFECYCLE-001.
 *
 * `state` is the authoritative lifecycle (uploaded|verified|rejected); the storage
 * path is only a file reference. Tenant ownership flows through the order/company.
 * A superseded proof (replaced) keeps `superseded_at` set and is never deleted —
 * the active proof is the newest one with `superseded_at` NULL.
 *
 * @property string $id
 * @property string $company_id
 * @property string $order_id
 * @property PaymentProofState $state
 * @property string $storage_disk
 * @property string $storage_path
 * @property string|null $uploaded_by
 * @property string|null $verified_by
 * @property \Illuminate\Support\Carbon|null $superseded_at
 * @property string|null $replaces_proof_id
 */
class PaymentProof extends Model
{
    use HasUuids;

    protected $table = 'payment_proofs';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'order_id',
        'state',
        'storage_disk',
        'storage_path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'uploaded_by',
        'uploaded_at',
        'verified_by',
        'verified_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'superseded_at',
        'replaces_proof_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => PaymentProofState::class,
            'uploaded_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'superseded_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The active proof is the one that has not been superseded. */
    public function isActive(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * Would `$actorId` reviewing this proof be a self-review?
     *
     * THE separation-of-duties rule for the proof lifecycle, in one place. Both review outcomes
     * consult it — `VerifyPaymentProofAction` and `RejectPaymentProofAction` — because verify and
     * reject are the two halves of a single reviewer capability (every role that is granted one is
     * granted the other), and a rule that covered only one half would let the same actor control
     * the review outcome by choosing which half to use.
     *
     * The comparison needs two identities to mean anything, so an unattributed proof
     * (`uploaded_by IS NULL`) is not treated as a self-review. That is not a loophole: the upload
     * route sits behind `auth:sanctum`, so `UploadPaymentProofAction` always stamps a real user id,
     * and a NULL uploader can only originate from a console or test path where no submitter
     * identity exists to be independent of.
     */
    public function isSelfReviewBy(?string $actorId): bool
    {
        $uploader = $this->uploaded_by;

        if ($actorId === null || $uploader === null) {
            return false;
        }

        return (string) $uploader === $actorId;
    }
}
