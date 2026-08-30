<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Services\WaveEngine;

use Carbon\CarbonImmutable;
use Modules\Operations\Preparation\Application\DTOs\WaveCycle;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;

/**
 * Turns three configured wall-clock times into one absolute operational cycle — PART 3.
 *
 * THE RULE: each boundary is the NEXT OCCURRENCE of its configured time, strictly after
 * the previous boundary, evaluated in the company's timezone.
 *
 *   startsAt        = the most recent occurrence of collection_start_time at or before now
 *   intakeClosesAt  = the next occurrence of preparation_start_time after startsAt
 *   endsAt          = the next occurrence of wave_end_time after intakeClosesAt
 *
 * That single rule is what makes cross-day work, and it is why no day-offset columns were
 * added. Attaching each time to `planning_date` — what the engine did before — turns
 * 18:00 / 08:00 / 15:00 into three points on Day 1 with the cycle ending seven hours
 * before it starts. Resolving forward instead yields Day 1 18:00 → Day 2 08:00 →
 * Day 2 15:00, and leaves the ordinary same-day configuration (06:00 / 09:00 / 18:00)
 * resolving exactly as it always did.
 *
 * It also subsumes the ordering invariant that the dropped CHECK constraint used to
 * enforce: startsAt < intakeClosesAt < endsAt holds by construction, for every
 * configuration, because each step only ever moves forward.
 *
 * THE GAP IS REAL. `resolveCycleAt()` returns null when now has passed the current
 * cycle's end but not yet reached the next start — 15:00 → 18:00 in the contract's
 * example. Null means "no operational cycle right now", so no wave is created and no
 * order is collected, which is exactly the intended quiet window (PART 26).
 */
final class WaveScheduleResolver
{
    /**
     * The operational cycle that is current at $now, or null if $now falls in the gap
     * between cycles.
     *
     * $timezone is the company timezone (G-2) — resolved by the caller so this class
     * stays a pure function of (config, timezone, now) and is trivially testable at any
     * instant, including across midnight and across a DST shift.
     */
    public function resolveCycleAt(
        WaveEngineConfiguration $config,
        string $timezone,
        CarbonImmutable $now,
    ): ?WaveCycle {
        $local = $now->setTimezone($timezone);

        // Anchor: today's start if it has already happened, otherwise yesterday's — the
        // cycle that is (or might still be) running right now.
        $startsAt = $this->atTime($local, $config->collection_start_time);

        if ($startsAt->greaterThan($local)) {
            $startsAt = $this->atTime($local->subDay(), $config->collection_start_time);
        }

        $intakeClosesAt = $this->nextOccurrenceAfter($startsAt, $config->preparation_start_time);
        $endsAt = $this->nextOccurrenceAfter($intakeClosesAt, $config->wave_end_time);

        // Past its end: this cycle is over and the next has not opened yet.
        if ($local->greaterThanOrEqualTo($endsAt)) {
            return null;
        }

        return new WaveCycle(
            // The LOCAL start date. Company-local on purpose: a cycle opening at 18:00
            // Cairo belongs to that Cairo day, not to whatever date it is in UTC.
            planningDate: $startsAt->toDateString(),
            startsAt: $startsAt->utc(),
            intakeClosesAt: $intakeClosesAt->utc(),
            endsAt: $endsAt->utc(),
        );
    }

    /**
     * The first occurrence of $time strictly after $after, in $after's timezone.
     *
     * Strictly: a boundary configured at the same clock time as the previous one lands
     * on the following day rather than collapsing onto it, so a cycle can never have
     * zero length.
     */
    private function nextOccurrenceAfter(CarbonImmutable $after, string $time): CarbonImmutable
    {
        $candidate = $this->atTime($after, $time);

        return $candidate->greaterThan($after) ? $candidate : $this->atTime($after->addDay(), $time);
    }

    /** Same calendar day as $day, at the wall-clock $time ("HH:MM" or "HH:MM:SS"). */
    private function atTime(CarbonImmutable $day, string $time): CarbonImmutable
    {
        [$hour, $minute, $second] = array_pad(
            array_map('intval', explode(':', trim($time))),
            3,
            0,
        );

        return $day->setTime($hour, $minute, $second);
    }
}
