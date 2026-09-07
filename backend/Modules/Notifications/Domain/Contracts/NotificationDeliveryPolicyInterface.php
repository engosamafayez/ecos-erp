<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\Contracts;

use Modules\Notifications\Domain\Enums\NotificationPriority;
use Modules\Notifications\Domain\ValueObjects\NotificationAttention;

/**
 * ADR-047 §14 / §26.5: the precedence seam — MANDATORY SYSTEM POLICY > COMPANY DEFAULT >
 * USER PREFERENCE. Task 2 built only the foundation the in-app half of this precedence
 * needs. Task 3 extends the same seam to the popup/sound attention layer — it does not
 * introduce a second precedence concept.
 */
interface NotificationDeliveryPolicyInterface
{
    /**
     * Always true in V1 (ADR-047 §14: "in-app notifications are always delivered
     * regardless of preferences"). Takes the recipient and the notification so a future
     * tier can consult company/user preference without changing this method's signature.
     */
    public function inAppIsMandatory(mixed $notifiable, ProvidesNotificationMetadataInterface $notification): bool;

    /**
     * ADR-047 §26.4/§26.5/§26.8: resolves whether a popup and/or sound should accompany
     * a notification of this priority for this recipient — MANDATORY SYSTEM POLICY >
     * COMPANY DEFAULT > USER PREFERENCE. Priority-keyed only, matching §26.4's locked
     * default table (never category-keyed — nothing in the ADR ties attention to
     * category, and none should be invented here). Never affects in-app delivery, which
     * remains governed exclusively by {@see inAppIsMandatory()}.
     */
    public function resolveAttention(mixed $notifiable, NotificationPriority $priority): NotificationAttention;

    /**
     * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §9 — a NARROWING-ONLY
     * gate on top of recipient authorization, never a substitute for it: by the time this
     * is consulted, the caller has already decided $notifiable is an authorized recipient
     * (e.g. via AuthorizationGateway, or a directly-resolved actor/assignee) — this method
     * may only turn that into "and don't actually deliver it", never add a recipient who
     * wasn't already one. Types absent from the catalog, or marked non-disableable there,
     * always return true (unchanged V1 behavior). Checked in CoreDatabaseChannel::send()
     * before the row is created, for every notification regardless of whether it
     * implements ProvidesNotificationMetadataInterface (several real producers —
     * Collaboration's — do not).
     */
    public function isTypeEnabledFor(mixed $notifiable, string $notificationClass): bool;
}
