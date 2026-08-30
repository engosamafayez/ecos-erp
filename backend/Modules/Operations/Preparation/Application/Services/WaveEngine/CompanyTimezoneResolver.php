<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Services\WaveEngine;

use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The authoritative operational timezone for a company — G-2.
 *
 * `companies.timezone` is the authority. NOT `config('app.timezone')`, NOT the server
 * clock, NOT `wave_engine_configurations.timezone`, NOT the browser.
 *
 * Why this exists at all: the audit found four clocks in play. `config('app.timezone')`
 * and `wave_engine_configurations.timezone` both read UTC, while every company in the
 * database declares `Africa/Cairo`. The wave engine was resolving its operational day
 * against the UTC clock, so a Cairo warehouse's day started two to three hours before
 * its business day did, and orders placed locally between midnight and 02:00/03:00 were
 * filed into the previous cycle.
 *
 * Read through the query builder rather than the Company model on purpose: this runs in
 * the scheduler, where there is no authenticated user, and a company-scoped global scope
 * on the model would silently return nothing.
 *
 * FAILS CLOSED. A company with no timezone, or an unrecognised one, resolves to null and
 * the caller skips it with a log line. It deliberately does NOT fall back to UTC —
 * silently substituting a plausible-but-wrong value is exactly the failure mode that
 * produced the drift this method is here to end.
 */
final class CompanyTimezoneResolver
{
    /** @var array<string, string|null> */
    private array $cache = [];

    /** @return string|null null = not configured or not a valid IANA identifier */
    public function resolve(string $companyId): ?string
    {
        if (array_key_exists($companyId, $this->cache)) {
            return $this->cache[$companyId];
        }

        $timezone = DB::table('companies')
            ->where('id', $companyId)
            ->value('timezone');

        return $this->cache[$companyId] = $this->validate(
            is_string($timezone) ? $timezone : null,
        );
    }

    private function validate(?string $timezone): ?string
    {
        if ($timezone === null || trim($timezone) === '') {
            return null;
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            return null;
        }

        return $timezone;
    }
}
