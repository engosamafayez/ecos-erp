<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Notifications\Application\Concerns\HasNotificationMetadata;
use Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface;
use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;
use Modules\Notifications\Domain\ValueObjects\DeepLink;

/**
 * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §7 — closes a real,
 * confirmed gap: DriverAssigned already fires (AssignDriverAction) but, before this
 * task, had zero listeners, so the assigned driver never learned of it. This is the
 * one and only notification driven by that existing event — no duplicate Loading
 * business rule, no second notification engine.
 */
final class DriverAssignedNotification extends Notification implements ProvidesNotificationMetadataInterface
{
    use HasNotificationMetadata;

    public function __construct(
        private readonly string $driverAssignmentId,
        private readonly string $vehicleId,
        private readonly string $vehicleLabel,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['notifications-core'];
    }

    public function notificationCategory(): NotificationCategory
    {
        return NotificationCategory::ASSIGNMENT;
    }

    public function notificationPriority(): NotificationPriority
    {
        return NotificationPriority::NORMAL;
    }

    public function notificationSourceModule(): string
    {
        return 'Operations';
    }

    /**
     * No per-assignment route exists today — /driver/loading is the driver's own
     * loading workspace (a list, not a per-record page), the same honest constraint
     * already applied to `pricing-review`.
     */
    public function notificationDeepLink(): ?DeepLink
    {
        return new DeepLink(entityType: 'driver-assignment', entityId: $this->driverAssignmentId);
    }

    /** One notification per assignment — DriverAssigned fires once per AssignDriverAction call. */
    public function notificationDedupeKey(mixed $notifiable): ?string
    {
        return "driver_assigned:{$this->driverAssignmentId}";
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type' => 'driver_assigned',
            'driver_assignment_id' => $this->driverAssignmentId,
            'vehicle_id' => $this->vehicleId,
            'message' => "You've been assigned to vehicle {$this->vehicleLabel}.",
        ];
    }
}
