<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\Enums;

/**
 * ADR-047 §7 / §26.3: locked priority scale, always orthogonal to {@see NotificationCategory}
 * — priority is never inferred from category or vice versa.
 */
enum NotificationPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    /**
     * ADR-047 §26.4: default delivery policy by priority, for channels other than
     * in-app. In-app itself is never conditional on priority (§14) — see
     * {@see \Modules\Notifications\Domain\Contracts\NotificationDeliveryPolicyInterface}.
     * Popup/sound/push are not activated in V1; this only records the locked default
     * so Task 3/4 do not have to re-derive it.
     */
    public function popupDefaultOn(): bool
    {
        return match ($this) {
            self::LOW => false,
            self::NORMAL, self::HIGH, self::CRITICAL => true,
        };
    }
}
