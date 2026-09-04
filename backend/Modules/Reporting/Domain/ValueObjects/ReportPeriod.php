<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\ValueObjects;

/**
 * The date-range metadata a report result was actually computed against.
 *
 * ENTERPRISE-REPORTING-PLATFORM.md §11: Reporting fixes business-day boundaries to
 * Africa/Cairo explicitly, server-side, regardless of APP_TIMEZONE. `$from`/`$to` are
 * always `Y-m-d`, inclusive on both ends — the caller-facing contract is a closed
 * interval, never a half-open one a frontend would have to reason about.
 *
 * `null` on either bound means that side is unbounded (e.g. "all orders up to today").
 */
final class ReportPeriod
{
    public const string BUSINESS_TIMEZONE = 'Africa/Cairo';

    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $timezone = self::BUSINESS_TIMEZONE,
    ) {}

    /**
     * @return array{from: string|null, to: string|null, timezone: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'timezone' => $this->timezone,
        ];
    }
}
