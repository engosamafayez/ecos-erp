<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;
use Modules\Operations\Preparation\Application\Notifications\QualityCheckFailedNotification;
use Tests\TestCase;

/**
 * The 2 not-yet-fired Preparation classes (QualityCheckFailedNotification,
 * ExceptionRaisedNotification) were deliberately left off the shared contract in this
 * task — ADR-047 §24 names the 3 already-firing producers as the pilot, and rewriting
 * dead code onto a new contract for its own sake is exactly what this task's own
 * instruction 5 says not to do. This test only pins that they remain constructible and
 * unaffected — see NotificationCoreFoundationTest for the 3 migrated producers.
 */
class HasNotificationMetadataDefaultsTest extends TestCase
{
    public function test_untouched_preparation_classes_remain_constructible(): void
    {
        $notification = new QualityCheckFailedNotification('W-1', 'wave-1', 'SKU-1', 'Widget');

        $this->assertSame(['database'], $notification->via(null));
        $this->assertSame('quality_check_failed', $notification->toDatabase(null)['type']);
    }
}
