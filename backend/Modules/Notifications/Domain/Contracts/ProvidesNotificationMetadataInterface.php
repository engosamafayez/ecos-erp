<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\Contracts;

use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;
use Modules\Notifications\Domain\ValueObjects\DeepLink;

/**
 * The shared producer contract (ADR-047 §24): one convention every source module's
 * `Notification` subclass conforms to, so the row `notifications-core` writes is
 * consistent regardless of which module fired it. A producer's existing `toDatabase()`
 * payload is untouched by this — it stays that producer's own business data; this
 * contract only supplies the cross-cutting columns (§19, §24): company_id, priority,
 * category, source_module, deep_link, dedupe_key, group_key.
 *
 * Implement via {@see \Modules\Notifications\Application\Concerns\HasNotificationMetadata}
 * for sensible defaults, overriding only what a given notification actually needs.
 */
interface ProvidesNotificationMetadataInterface
{
    public function notificationCategory(): NotificationCategory;

    public function notificationPriority(): NotificationPriority;

    /** The owning module, e.g. "Operations". Used for the existing frontend source-tab grouping. */
    public function notificationSourceModule(): string;

    public function notificationDeepLink(): ?DeepLink;

    /**
     * ADR-047 §10: a stable string identifying "this same underlying condition", or
     * null to opt out of dedup entirely. Uniqueness is enforced per-recipient (a
     * dedupe key is only ever compared against rows for the same notifiable) — two
     * different users may legitimately share the same key. Distinct genuine
     * occurrences (a new shortage on a different day) must produce a different key;
     * this method decides that, not the channel.
     */
    public function notificationDedupeKey(mixed $notifiable): ?string;

    /** ADR-047 §11: presentation-only collapsing key (e.g. "8 orders require payment review"), or null. */
    public function notificationGroupKey(mixed $notifiable): ?string;
}
