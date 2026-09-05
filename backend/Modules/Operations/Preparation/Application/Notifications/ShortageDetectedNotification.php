<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Notifications\Application\Concerns\HasNotificationMetadata;
use Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface;
use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;

/**
 * TASK-ECOS-NOTIFICATIONS-FOUNDATION-002: migrated onto the shared producer contract
 * (ADR-047 §24) — channel and metadata only. The business trigger (constructor,
 * toDatabase() payload, dead toMail()) is unchanged.
 */
final class ShortageDetectedNotification extends Notification implements ProvidesNotificationMetadataInterface
{
    use HasNotificationMetadata;

    /** @param list<array<string, mixed>> $shortages */
    public function __construct(
        private readonly string $waveNumber,
        private readonly string $waveId,
        private readonly array $shortages,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['notifications-core'];
    }

    public function notificationCategory(): NotificationCategory
    {
        return NotificationCategory::EXCEPTION;
    }

    public function notificationPriority(): NotificationPriority
    {
        return NotificationPriority::HIGH;
    }

    public function notificationDedupeKey(mixed $notifiable): ?string
    {
        return "shortage_detected:{$this->waveId}";
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type' => 'shortage_detected',
            'wave_id' => $this->waveId,
            'wave_number' => $this->waveNumber,
            'message' => "Wave {$this->waveNumber} blocked — material shortage detected",
            'shortages' => $this->shortages,
            'severity' => 'blocking',
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Wave {$this->waveNumber} — Shortage Detected")
            ->line("Wave {$this->waveNumber} is blocked due to a material shortage.")
            ->line(count($this->shortages).' material(s) are below required levels.')
            ->action('View Wave', url("/operations/preparation/waves?search={$this->waveNumber}"));
    }
}
