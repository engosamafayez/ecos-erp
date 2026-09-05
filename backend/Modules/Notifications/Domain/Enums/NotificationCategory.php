<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\Enums;

/**
 * The 8-value semantic taxonomy locked by ADR-047 §6 / NOTIFICATION-UX-STANDARD.md §2.
 *
 * This is the one system of record for "what kind of notification is this" — never
 * introduce a competing taxonomy. Distinct from the `notifications.type` column, which
 * stores the producing Notification class's FQCN (the existing, stable discriminator);
 * `category` is the human-facing classification of that same row.
 */
enum NotificationCategory: string
{
    case ALERT = 'alert';
    case TASK = 'task';
    case APPROVAL = 'approval';
    case ASSIGNMENT = 'assignment';
    case WARNING = 'warning';
    case MENTION = 'mention';
    case AI_NOTIFICATION = 'ai_notification';
    case EXCEPTION = 'exception';

    /** ADR-047 §6: each category carries a fixed "Requires Action" flag. */
    public function requiresAction(): bool
    {
        return match ($this) {
            self::TASK, self::APPROVAL, self::EXCEPTION => true,
            self::ALERT, self::ASSIGNMENT, self::WARNING, self::MENTION, self::AI_NOTIFICATION => false,
        };
    }
}
