<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * One resolved operational cycle — the three boundaries of a single wave.
 *
 * Produced by WaveScheduleResolver from the configured wall-clock times and the
 * company timezone; persisted onto `preparation_waves` at creation so the cycle is a
 * stored fact rather than something recomputed (and therefore re-interpretable) on
 * every scheduler tick.
 *
 * The boundaries are strictly ordered by construction:
 *   startsAt < intakeClosesAt < endsAt
 * and may cross midnight in either gap.
 */
final readonly class WaveCycle
{
    public function __construct(
        /** Calendar identity of the cycle — the LOCAL date on which it starts. */
        public string $planningDate,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $intakeClosesAt,
        public CarbonImmutable $endsAt,
    ) {}

    /** Intake is open between startsAt (inclusive) and intakeClosesAt (exclusive). */
    public function isIntakeOpenAt(CarbonImmutable $now): bool
    {
        return $now->greaterThanOrEqualTo($this->startsAt)
            && $now->lessThan($this->intakeClosesAt);
    }

    public function hasEndedAt(CarbonImmutable $now): bool
    {
        return $now->greaterThanOrEqualTo($this->endsAt);
    }
}
