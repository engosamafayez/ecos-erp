<?php

declare(strict_types=1);

namespace Modules\Notifications\Infrastructure\Notifications\Channels;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Modules\Notifications\Domain\Contracts\NotificationDeliveryPolicyInterface;
use Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface;
use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;

/**
 * ADR-047 §24: the shared producer contract's delivery mechanism. A drop-in replacement
 * for Laravel's stock `database` channel — same row shape (id, type, notifiable, data,
 * read_at, created_at/updated_at) plus the Task 2 schema extension (company_id, priority,
 * category, source_module, deep_link, dedupe_key, group_key) populated from
 * {@see ProvidesNotificationMetadataInterface} when the notification implements it.
 *
 * A notification that does NOT implement the metadata interface still works — it falls
 * back to the same defaults {@see \Modules\Notifications\Application\Concerns\HasNotificationMetadata}
 * would supply — so adopting this channel is never a precondition for a notification to
 * send; only the metadata columns stay at their defaults until the class opts in.
 */
final class CoreDatabaseChannel
{
    public function __construct(
        private readonly NotificationDeliveryPolicyInterface $deliveryPolicy,
    ) {}

    public function send(mixed $notifiable, Notification $notification): ?DatabaseNotification
    {
        $metadata = $notification instanceof ProvidesNotificationMetadataInterface ? $notification : null;

        // V1: always true. The call exists so the mandatory-floor precedence (ADR-047
        // §14) is an explicit, testable decision point — not an accident of no code
        // path existing yet to suppress it.
        if ($metadata !== null && ! $this->deliveryPolicy->inAppIsMandatory($notifiable, $metadata)) {
            return null; // unreachable in V1; see NotificationDeliveryPolicy.
        }

        $dedupeKey = $metadata?->notificationDedupeKey($notifiable);

        if ($dedupeKey !== null && $this->alreadyDelivered($notifiable, $dedupeKey)) {
            return null;
        }

        $deepLink = $metadata?->notificationDeepLink();

        /** @var \Illuminate\Database\Eloquent\Relations\MorphMany<DatabaseNotification, *> $relation */
        $relation = $notifiable->routeNotificationFor('database', $notification);

        return $relation->create([
            'id' => $notification->id,
            'type' => get_class($notification),
            'data' => $this->data($notifiable, $notification),
            'read_at' => null,
            'company_id' => $this->companyIdOf($notifiable),
            'priority' => ($metadata?->notificationPriority() ?? NotificationPriority::NORMAL)->value,
            'category' => ($metadata?->notificationCategory() ?? NotificationCategory::ALERT)->value,
            'source_module' => $metadata?->notificationSourceModule() ?? $this->sourceModuleOf($notification),
            'deep_link' => $deepLink !== null ? json_encode($deepLink->toArray()) : null,
            'dedupe_key' => $dedupeKey,
            'group_key' => $metadata?->notificationGroupKey($notifiable),
        ]);
    }

    /** @return array<string, mixed> */
    private function data(mixed $notifiable, Notification $notification): array
    {
        if (method_exists($notification, 'toDatabase')) {
            $data = $notification->toDatabase($notifiable);

            return is_array($data) ? $data : (array) $data;
        }

        if (method_exists($notification, 'toArray')) {
            return $notification->toArray($notifiable);
        }

        throw new \RuntimeException(get_class($notification).' is missing a toDatabase()/toArray() method.');
    }

    private function alreadyDelivered(mixed $notifiable, string $dedupeKey): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $notifiable->getMorphClass())
            ->where('notifiable_id', $notifiable->getKey())
            ->where('dedupe_key', $dedupeKey)
            ->exists();
    }

    private function companyIdOf(mixed $notifiable): mixed
    {
        return $notifiable->company_id ?? null;
    }

    private function sourceModuleOf(Notification $notification): string
    {
        $segments = explode('\\', get_class($notification));

        return $segments[1] ?? 'Unknown';
    }
}
