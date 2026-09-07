import { ROUTES } from '@/router/routes';

import type { UiNotification } from '../types/notification';

/**
 * ADR-047 §8 — a notification's `deep_link` carries a typed reference
 * (`entity_type`/`entity_id`), never a raw URL. This resolver is the one place that
 * turns that typed reference into an actual ECOS route, and it is deliberately an
 * allowlist: an `entity_type` is only ever added here once its destination route is
 * independently verified to exist and take that id, per
 * TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004 §5's explicit
 * instruction not to guess. An unrecognised or absent `entity_type` resolves to `null` —
 * the notification stays fully readable, just without an action button. This is not a
 * defect; most current producers set no deep link at all.
 */
const KNOWN_ENTITY_ROUTES: Partial<Record<string, (entityId: string) => string>> = {
  customer: (id) => ROUTES.customerDetail.replace(':customerId', id),
  // The Price Review Center (TASK-ECOS-NOTIFICATIONS-USER-REVIEW-REMEDIATION-007) has no
  // per-record route today — a bulk-select list page only — so every review resolves to
  // the same list surface regardless of `entityId` (same "don't fabricate a URL that
  // doesn't exist" constraint `order` failed under investigation in Task 4).
  'pricing-review': () => ROUTES.costManagementPriceReview,
};

/** @returns the route to navigate to, or null when this notification has no supported target. */
export function resolveNotificationTarget(notification: Pick<UiNotification, 'deepLink'>): string | null {
  const link = notification.deepLink;
  if (!link) return null;

  const build = KNOWN_ENTITY_ROUTES[link.entityType];
  return build ? build(link.entityId) : null;
}
