<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\Catalog;

/**
 * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §8 — one entry in the
 * canonical Notification Type Catalog. A definition describes a real, already-firing
 * producer class; it never invents a condition the codebase doesn't actually raise.
 */
final readonly class NotificationTypeDefinition
{
    public function __construct(
        /** Stable, short, snake_case identifier — what a user's preference payload keys on. */
        public string $key,
        /** The exact producing Notification class this definition describes. */
        public string $notificationClass,
        /** Business module/domain grouping, for the Preferences page's section headers. */
        public string $module,
        public string $nameAr,
        public string $descriptionAr,
        public bool $defaultEnabled,
        public bool $userCanDisable,
        public bool $hasDestination,
        /** Free-text note on how recipients are actually resolved — documentation only. */
        public string $recipientAuthority,
    ) {}
}
