<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;
use RuntimeException;

/**
 * OPERATIONS review of a driver trip movement — TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 §9/§10.
 *
 * The canonical, domain-authoritative Approve / Reject transition. It is the ONLY writer of the
 * review decision — the controller never mutates status directly. A movement may be reviewed ONLY
 * from Pending (§18); a repeated approve/reject on an already-decided movement is safely refused
 * (§27-18), never a second contradictory decision. The row is locked for the decision so two
 * concurrent reviewers cannot both win. Amount / type / direction / trip / evidence are never
 * touched here — approval is immutable of the movement's facts (§11); it only records the verdict,
 * the reviewer and the timestamp. This is operational, not Finance (§25).
 */
final class ReviewDriverTripMovementAction
{
    public function approve(DriverTripMovement $movement, string $actorId, ?string $note = null): DriverTripMovement
    {
        return $this->decide($movement, DriverTripMovementStatus::Approved, $actorId, $note);
    }

    public function reject(DriverTripMovement $movement, string $actorId, string $reason): DriverTripMovement
    {
        return $this->decide($movement, DriverTripMovementStatus::Rejected, $actorId, $reason);
    }

    private function decide(
        DriverTripMovement $movement,
        DriverTripMovementStatus $target,
        string $actorId,
        ?string $note,
    ): DriverTripMovement {
        return DB::transaction(function () use ($movement, $target, $actorId, $note): DriverTripMovement {
            /** @var DriverTripMovement $locked */
            $locked = DriverTripMovement::query()->whereKey($movement->getKey())->lockForUpdate()->firstOrFail();

            $current = $locked->status instanceof DriverTripMovementStatus
                ? $locked->status
                : DriverTripMovementStatus::from((string) $locked->status);

            // Only a Pending movement can be reviewed. A repeat decision (already Approved/Rejected/
            // Settled) is refused rather than silently re-applied — approval is immutable (§11/§18).
            if ($current !== DriverTripMovementStatus::Pending) {
                throw new RuntimeException("This movement is already {$current->value} and cannot be reviewed again.");
            }

            $locked->forceFill([
                'status' => $target->value,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_note' => $note,
                'updated_by' => $actorId,
            ])->save();

            return $locked->refresh();
        });
    }
}
