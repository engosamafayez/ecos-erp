<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Logistics\Distribution\Domain\Enums\PaymentType;

/** Money taken at a stop. Feeds the trip settlement. */
class PaymentCollection extends Model
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'distribution_payment_collections';

    /** @var array<string, mixed> */
    protected $attributes = [
        'amount' => 0,
        'status' => self::STATUS_RECORDED,
    ];

    protected $fillable = [
        'trip_id',
        'stop_id',
        'payment_type',
        'amount',
        'reference_number',
        'image_path',
        'notes',
        'status',
        'verified_at',
        'verified_by',
        'collected_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(DeliveryStop::class, 'stop_id');
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Would `$actorId` reviewing this collection be a self-review?
     *
     * THE separation-of-duties rule for the cash ledger, in one place. Both review
     * outcomes consult it — `SettlementService::verifyPayment()` and `rejectPayment()` —
     * because verify and reject are the two halves of one reviewer act, and a rule
     * covering only one half would let the collector pick the half they control.
     *
     * DELIBERATELY THE SAME SHAPE AS `PaymentProof::isSelfReviewBy()`, and for the same
     * reason recorded there: the role catalogue is a configuration fact, not a control.
     * Until TASK-DRIVER-02 both halves took the identical permission and the collector
     * could verify their own cash. The permission split is now real, but a permission
     * split alone can never establish that two different PEOPLE were involved — a single
     * user assigned both roles, or any `is_system` role, would still pass the middleware.
     * This check lives in the domain, after the middleware has let the actor through, so
     * it binds every actor including Super Admin.
     *
     * The comparison needs two identities to mean anything, so an unattributed collection
     * (`collected_by IS NULL`) is not a self-review. That is not a loophole: the record
     * route sits behind `auth:sanctum` and always stamps `collected_by` from the actor, so
     * a NULL collector can only originate from a console or test path where there is no
     * submitter identity to be independent of.
     */
    public function isSelfReviewBy(?int $actorId): bool
    {
        $collector = $this->collected_by;

        if ($actorId === null || $collector === null) {
            return false;
        }

        return (int) $collector === $actorId;
    }

    /** Only non-rejected physical cash is reconciled against the driver's hand-back. */
    public function countsTowardCashExpected(): bool
    {
        return ! $this->isRejected() && $this->payment_type->isPhysicalCash();
    }
}
