<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\Enums;

/**
 * ADR-047 §26.8: at most three sound profiles — never a unique sound per notification
 * type or per module. No business module hardcodes its own sound; only
 * {@see \Modules\Notifications\Application\Services\NotificationDeliveryPolicy} maps a
 * priority onto one of these.
 */
enum SoundProfile: string
{
    case NORMAL = 'normal';
    case IMPORTANT = 'important';
    case CRITICAL = 'critical';
}
