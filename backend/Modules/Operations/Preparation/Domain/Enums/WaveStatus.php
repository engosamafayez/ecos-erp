<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Domain\Enums;

enum WaveStatus: string
{
    case Draft           = 'draft';
    case Planning        = 'planning';
    case ShortageBlocked = 'shortage_blocked';
    case Preparing       = 'preparing';
    case Completed       = 'completed';
    case Cancelled       = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft           => $next === self::Planning || $next === self::Cancelled,
            self::Planning        => $next === self::ShortageBlocked || $next === self::Preparing || $next === self::Cancelled,
            self::ShortageBlocked => $next === self::Planning || $next === self::Preparing || $next === self::Cancelled,
            self::Preparing       => $next === self::Completed || $next === self::Cancelled,
            self::Completed       => false,
            self::Cancelled       => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Draft, self::Planning, self::ShortageBlocked, self::Preparing => true,
            default => false,
        };
    }
}
