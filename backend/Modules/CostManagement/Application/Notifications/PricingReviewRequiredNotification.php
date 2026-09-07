<?php

declare(strict_types=1);

namespace Modules\CostManagement\Application\Notifications;

use Illuminate\Notifications\Notification;
use Modules\Notifications\Application\Concerns\HasNotificationMetadata;
use Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface;
use Modules\Notifications\Domain\Enums\NotificationCategory;
use Modules\Notifications\Domain\Enums\NotificationPriority;
use Modules\Notifications\Domain\ValueObjects\DeepLink;

/**
 * TASK-ECOS-NOTIFICATIONS-USER-REVIEW-REMEDIATION-007 — the missing half of
 * CostImpactEngine's own documented workflow ("[future] publish cost-change
 * notifications"). PricingReviewService::upsertForProduct() only dispatches
 * PriceReviewCreated when a genuinely NEW review is opened (never on an update to an
 * already-open one), so that event's existing dispatch condition is the sole "does this
 * need a notification" decision — this class never re-derives it.
 */
final class PricingReviewRequiredNotification extends Notification implements ProvidesNotificationMetadataInterface
{
    use HasNotificationMetadata;

    public function __construct(
        private readonly string $reviewId,
        private readonly string $productId,
        private readonly string $productName,
        private readonly float $previousCost,
        private readonly float $newCost,
        private readonly string $triggerReason,
    ) {}

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['notifications-core'];
    }

    public function notificationCategory(): NotificationCategory
    {
        return NotificationCategory::APPROVAL;
    }

    public function notificationPriority(): NotificationPriority
    {
        return NotificationPriority::HIGH;
    }

    public function notificationSourceModule(): string
    {
        return 'CostManagement';
    }

    /**
     * No per-review URL exists in the Price Review Center today (bulk-select list page
     * only, no addressable single-record route) — same honest constraint Task 4 already
     * applied to `order`. The list surface itself is real and addressable, so `entityType`
     * is registered in the frontend allowlist; `entityId` is carried for future use once
     * (if ever) a per-record route exists.
     */
    public function notificationDeepLink(): ?DeepLink
    {
        return new DeepLink(entityType: 'pricing-review', entityId: $this->reviewId);
    }

    /**
     * One notification per review, ever: PriceReviewCreated fires exactly once per
     * review row (see class docblock), so a stable per-review key is sufficient — the
     * existing CoreDatabaseChannel dedupe (per-recipient, keyed on this string) is the
     * only duplicate-protection mechanism this relies on.
     */
    public function notificationDedupeKey(mixed $notifiable): ?string
    {
        return "price_review_created:{$this->reviewId}";
    }

    /** @return array<string, mixed> */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type' => 'pricing_review_required',
            'review_id' => $this->reviewId,
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'previous_cost' => $this->previousCost,
            'new_cost' => $this->newCost,
            'trigger_reason' => $this->triggerReason,
            'message' => "{$this->productName} needs a pricing review — cost changed from {$this->previousCost} to {$this->newCost}.",
        ];
    }
}
