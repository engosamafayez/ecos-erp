<?php

declare(strict_types=1);

namespace Modules\Hr\Attendance\Domain\Enums;

/**
 * The lifecycle of one Attendance correction request.
 *
 * Deliberately simpler than LeaveStatus's transition table: an Approved
 * correction has already mutated the canonical AttendanceDay, so — unlike an
 * approved leave, which a plan change can still Cancel — there is no
 * transition out of Approved/Rejected/Cancelled. Un-applying an already
 * -applied correction would need a real reversal feature, not a status flip,
 * and nothing here invents one.
 */
enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
