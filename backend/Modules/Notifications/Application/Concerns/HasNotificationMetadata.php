<?php

declare(strict_types=1);

namespace Modules\Notifications\Application\Concerns;

use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;
use Modules\Notifications\Domain\ValueObjects\DeepLink;

/**
 * Sensible defaults for {@see \Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface},
 * so a migrating producer only overrides what is actually meaningful for it — matching
 * the smallest-possible-change intent of ADR-047 §24's "migrate onto the shared
 * contract" instruction.
 */
trait HasNotificationMetadata
{
    public function notificationCategory(): NotificationCategory
    {
        return NotificationCategory::ALERT;
    }

    public function notificationPriority(): NotificationPriority
    {
        return NotificationPriority::NORMAL;
    }

    /** Default: the second FQCN segment, e.g. `Modules\Operations\...` -> "Operations". */
    public function notificationSourceModule(): string
    {
        $segments = explode('\\', static::class);

        return $segments[1] ?? 'Unknown';
    }

    public function notificationDeepLink(): ?DeepLink
    {
        return null;
    }

    public function notificationDedupeKey(mixed $notifiable): ?string
    {
        return null;
    }

    public function notificationGroupKey(mixed $notifiable): ?string
    {
        return null;
    }
}
