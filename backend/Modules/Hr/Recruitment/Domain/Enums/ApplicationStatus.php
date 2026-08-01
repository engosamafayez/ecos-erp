<?php

declare(strict_types=1);

namespace Modules\Hr\Recruitment\Domain\Enums;

/**
 * Where a candidacy stands.
 *
 * The stage says how far along the pipeline someone is; this says what has been
 * DECIDED about them. Only `OfferAccepted` opens the door to hiring — an
 * accepted application is a decision, and hiring is a separate, deliberate act.
 */
enum ApplicationStatus: string
{
    case InPipeline = 'in_pipeline';
    case Hold = 'hold';
    case Accepted = 'accepted';
    case OfferSent = 'offer_sent';
    case OfferAccepted = 'offer_accepted';
    case OfferDeclined = 'offer_declined';
    case Rejected = 'rejected';
    case TalentPool = 'talent_pool';
    case Withdrawn = 'withdrawn';

    /** Still being considered. */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::InPipeline, self::Hold, self::Accepted, self::OfferSent,
        ], true);
    }

    /** Nothing further will happen on this candidacy. */
    public function isClosed(): bool
    {
        return in_array($this, [
            self::Rejected, self::OfferDeclined, self::Withdrawn, self::TalentPool,
        ], true);
    }

    /**
     * Whether an employee may be created from here.
     *
     * Accepting is a decision; an accepted offer is a commitment. Both qualify,
     * but nothing earlier does.
     */
    public function canBeHired(): bool
    {
        return in_array($this, [self::Accepted, self::OfferAccepted], true);
    }

    /** @return array<int, self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::InPipeline => [
                self::Hold, self::Accepted, self::Rejected, self::TalentPool,
                self::OfferSent, self::Withdrawn,
            ],
            self::Hold => [self::InPipeline, self::Accepted, self::Rejected, self::TalentPool, self::Withdrawn],
            self::Accepted => [self::OfferSent, self::Rejected, self::Withdrawn],
            self::OfferSent => [self::OfferAccepted, self::OfferDeclined, self::Withdrawn],
            self::OfferDeclined => [self::TalentPool],
            self::Rejected => [self::TalentPool],
            // Hiring closes the candidacy; the talent pool is where the rest rest.
            self::OfferAccepted, self::TalentPool, self::Withdrawn => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::InPipeline => 'In Pipeline',
            self::Hold => 'Hold',
            self::Accepted => 'Accepted',
            self::OfferSent => 'Offer Sent',
            self::OfferAccepted => 'Offer Accepted',
            self::OfferDeclined => 'Offer Declined',
            self::Rejected => 'Rejected',
            self::TalentPool => 'Talent Pool',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
