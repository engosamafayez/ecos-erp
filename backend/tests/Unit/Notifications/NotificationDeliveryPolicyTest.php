<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use Modules\Notifications\Application\Concerns\HasNotificationMetadata;
use Modules\Notifications\Application\Services\NotificationDeliveryPolicy;
use Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface;
use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;
use Tests\TestCase;

/**
 * ADR-047 §14: in-app delivery is mandatory regardless of preference tier. This is the
 * one fact V1 can enforce today — no company/user preference tier exists to override it
 * with yet (Task 3). This test pins that the seam exists and cannot be talked out of
 * "true" by any input, not that a full precedence chain is implemented.
 *
 * Uses a bare metadata-only stub rather than a real Preparation notification — the
 * policy only depends on {@see ProvidesNotificationMetadataInterface}, not on any
 * concrete producer (all of which are `final` and so cannot be extended anyway).
 */
class NotificationDeliveryPolicyTest extends TestCase
{
    public function test_in_app_is_always_mandatory_regardless_of_category_or_priority(): void
    {
        // Resolved through the container, not `new` — Task 3 gave the policy real
        // dependencies (company/user preference lookups); this method's own behavior is
        // unaffected, but the class can no longer be constructed with zero arguments.
        $policy = app(NotificationDeliveryPolicy::class);

        foreach (NotificationCategory::cases() as $category) {
            foreach (NotificationPriority::cases() as $priority) {
                $notification = new class($category, $priority) implements ProvidesNotificationMetadataInterface
                {
                    use HasNotificationMetadata;

                    public function __construct(
                        private readonly NotificationCategory $cat,
                        private readonly NotificationPriority $prio,
                    ) {}

                    public function notificationCategory(): NotificationCategory
                    {
                        return $this->cat;
                    }

                    public function notificationPriority(): NotificationPriority
                    {
                        return $this->prio;
                    }
                };

                $this->assertTrue(
                    $policy->inAppIsMandatory(notifiable: null, notification: $notification),
                    "in-app must be mandatory for category={$category->value} priority={$priority->value}",
                );
            }
        }
    }
}
