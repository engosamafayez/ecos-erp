<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Application\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Modules\CustomerEngagement\Domain\Models\SlaPolicy;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §3B — the ONE business-hours
 * authority for CustomerEngagement. `SlaPolicy.business_hours_only`/`business_hours` were stored
 * but unenforced before this task (architecture report gap #2); this service is what now reads
 * them. Voice's own after-hours transfer/fallback decision (§17) calls {@see isOpenAt()} against
 * the SAME resolved policy a Conversation already carries — it must never define a second
 * schedule of its own.
 *
 * `business_hours` shape: {"mon": ["09:00","18:00"], "tue": [...], ...} keyed by lowercase
 * three-letter day abbreviation (Carbon's own `format('D')` lowercased); a day absent from the
 * map is closed that day. A policy with business_hours_only=true but no schedule configured is
 * treated as always-open — this fails toward not silently inflating every SLA deadline, not
 * toward blocking Voice/SLA behavior on an unconfigured policy.
 */
final class BusinessHoursService
{
    public function isOpenAt(SlaPolicy $policy, CarbonInterface $at): bool
    {
        if (! $policy->business_hours_only) {
            return true;
        }

        $schedule = $policy->business_hours;

        if (! is_array($schedule) || $schedule === []) {
            return true;
        }

        $local = $at->copy()->setTimezone($policy->timezone ?? 'UTC');
        $window = $this->windowFor($schedule, $local);

        if ($window === null) {
            return false;
        }

        [$start, $end] = $window;

        return $local->between($start, $end);
    }

    /**
     * Adds $minutes of BUSINESS time to $from per the policy's schedule — minutes outside an
     * open window do not count, so a "60-minute first response" SLA started at 6pm on a
     * 9am-6pm policy is due at 10am the next open day, not 7pm the same day.
     *
     * Walks day-by-day (not minute-by-minute) so this stays cheap even for a multi-day
     * resolution window.
     */
    public function addBusinessMinutes(SlaPolicy $policy, CarbonInterface $from, int $minutes): CarbonInterface
    {
        if (! $policy->business_hours_only || ! is_array($policy->business_hours) || $policy->business_hours === []) {
            return $from->copy()->addMinutes($minutes);
        }

        $tz = $policy->timezone ?? 'UTC';
        $cursor = $from->copy()->setTimezone($tz);
        $remaining = $minutes;

        for ($guard = 0; $guard < 3660 && $remaining > 0; $guard++) {
            $window = $this->windowFor($policy->business_hours, $cursor);

            if ($window === null) {
                $cursor = $cursor->copy()->startOfDay()->addDay();

                continue;
            }

            [$start, $end] = $window;

            if ($cursor->lt($start)) {
                $cursor = $start->copy();
            }

            if ($cursor->gte($end)) {
                $cursor = $cursor->copy()->startOfDay()->addDay();

                continue;
            }

            $availableToday = (int) $cursor->diffInMinutes($end, true);

            if ($remaining <= $availableToday) {
                $cursor = $cursor->copy()->addMinutes($remaining);
                $remaining = 0;

                break;
            }

            $remaining -= $availableToday;
            $cursor = $cursor->copy()->startOfDay()->addDay();
        }

        return $cursor->setTimezone($from->getTimezone());
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    private function windowFor(array $schedule, CarbonInterface $localDay): ?array
    {
        $dayKey = strtolower($localDay->format('D'));
        $times = $schedule[$dayKey] ?? null;

        if (! is_array($times) || count($times) !== 2) {
            return null;
        }

        [$openTime, $closeTime] = $times;
        $date = $localDay->format('Y-m-d');

        $start = Carbon::parse("{$date} {$openTime}", $localDay->getTimezone());
        $end = Carbon::parse("{$date} {$closeTime}", $localDay->getTimezone());

        return $end->gt($start) ? [$start, $end] : null;
    }
}
