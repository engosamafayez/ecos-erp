<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Domain\Enums;

enum WaveStatus: string
{
    case Draft = 'draft';
    case Collecting = 'collecting';      // Wave Engine: accepting orders before preparation starts
    case Planning = 'planning';
    case ShortageBlocked = 'shortage_blocked';
    case Preparing = 'preparing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Closed = 'closed';          // Wave Engine: time-based end-of-day closure

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => $next === self::Collecting || $next === self::Planning || $next === self::Cancelled,
            self::Collecting => $next === self::Preparing || $next === self::Cancelled,
            self::Planning => $next === self::ShortageBlocked || $next === self::Preparing || $next === self::Cancelled,
            self::ShortageBlocked => $next === self::Planning || $next === self::Preparing || $next === self::Cancelled,
            self::Preparing => $next === self::Completed || $next === self::Closed || $next === self::Cancelled,
            self::Completed => false,
            self::Cancelled => false,
            self::Closed => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled || $this === self::Closed;
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Draft, self::Collecting, self::Planning, self::ShortageBlocked, self::Preparing => true,
            default => false,
        };
    }

    /**
     * Backing values of the active statuses, for query filters.
     *
     * DERIVED from isActive() rather than re-listing the cases, so the workspace
     * filter and the domain predicate can never drift apart.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->isActive()),
        ));
    }

    /**
     * Backing values of the terminal statuses — the archive population.
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->isTerminal()),
        ));
    }
}
