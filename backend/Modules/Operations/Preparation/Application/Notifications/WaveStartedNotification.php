<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Notifications\Application\Concerns\HasNotificationMetadata;
use Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface;
use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;

/**
 * TASK-ECOS-NOTIFICATIONS-FOUNDATION-002: migrated onto the shared producer contract
 * (ADR-047 §24) — channel and metadata only. The business trigger (constructor,
 * toDatabase() payload) is unchanged.
 */
final class WaveStartedNotification extends Notification implements ProvidesNotificationMetadataInterface
{
    use HasNotificationMetadata;

    public function __construct(
        private readonly string $waveNumber,
        private readonly string $waveId,
        private readonly string $role,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['notifications-core'];
    }

    public function notificationCategory(): NotificationCategory
    {
        return NotificationCategory::ALERT;
    }

    public function notificationPriority(): NotificationPriority
    {
        return NotificationPriority::LOW;
    }

    public function notificationDedupeKey(mixed $notifiable): ?string
    {
        return "wave_started:{$this->waveId}";
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type' => 'wave_started',
            'wave_id' => $this->waveId,
            'wave_number' => $this->waveNumber,
            'message' => "You've been assigned to wave {$this->waveNumber} as {$this->role}",
            'severity' => 'info',
        ];
    }
}
