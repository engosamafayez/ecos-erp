<?php

declare(strict_types=1);

namespace Modules\Notifications\Application\Services;

use Modules\Notifications\Domain\Contracts\NotificationDeliveryPolicyInterface;
use Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface;

final class NotificationDeliveryPolicy implements NotificationDeliveryPolicyInterface
{
    /** V1: unconditional. See the interface docblock for why this seam exists at all. */
    public function inAppIsMandatory(mixed $notifiable, ProvidesNotificationMetadataInterface $notification): bool
    {
        return true;
    }
}
