<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\Contracts;

/**
 * ADR-047 §14 / §26.5: the precedence seam — MANDATORY SYSTEM POLICY > COMPANY DEFAULT >
 * USER PREFERENCE. Task 2 builds only the foundation this precedence needs: today there
 * is no company-default or user-preference tier to override anything with (both are a
 * Task 3 build, `UserNotificationPreference` does not exist yet), so the one fact V1 can
 * and must enforce is the top of that chain — in-app delivery can never be disabled by
 * any lower tier. This contract makes that fact an explicit, testable seam rather than
 * an implicit side effect of "no suppression code exists yet": when Task 3/4 add real
 * company/user preference lookups, they extend this method's implementation, not bypass
 * it — the mandatory floor stays enforced by construction.
 */
interface NotificationDeliveryPolicyInterface
{
    /**
     * Always true in V1 (ADR-047 §14: "in-app notifications are always delivered
     * regardless of preferences"). Takes the recipient and the notification so a future
     * tier can consult company/user preference without changing this method's signature.
     */
    public function inAppIsMandatory(mixed $notifiable, ProvidesNotificationMetadataInterface $notification): bool;
}
