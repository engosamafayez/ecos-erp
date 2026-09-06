import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { notificationsService } from '../services/notifications-service';
import type { NotificationPreferences } from '../types/notification';

/**
 * The notification feed.
 *
 * Deliberately NOT company-scoped, unlike every other query key in the app: a
 * notification is addressed to a person, not to a company, and re-keying it per
 * active company would empty the bell every time the user switched context.
 */
const KEY = ['notifications'] as const;

export function useNotifications(enabled = true) {
  return useQuery({
    queryKey: [...KEY, 'list'],
    queryFn: () => notificationsService.list({ perPage: 50 }),
    // The bell is always mounted; a background refresh keeps the unread count
    // honest without the user reopening the drawer.
    refetchInterval: 60_000,
    staleTime: 30_000,
    enabled,
  });
}

/**
 * The canonical unread count — one source, not a value every consumer re-derives from
 * the feed inline. Shares `useNotifications()`'s query cache (same key), so this never
 * issues a second network request.
 */
export function useUnreadNotificationCount(): number {
  const feed = useNotifications();
  return feed.data?.unread_count ?? 0;
}

/**
 * TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004 — the Notification
 * Center's own paginated browsing, deliberately a *different* query key from
 * `useNotifications()`: the bell's unread count and Task 3's arrival-attention detection
 * must always watch the single most-recent page regardless of what page the user has
 * paginated to in the Center. Both still read the same backend endpoint/table (ADR-047
 * §14 — one persistent authority) and both are invalidated together by every mark-read
 * mutation below, via the shared `KEY` prefix.
 *
 * `placeholderData: keepPreviousData` keeps the current page's rows on screen while the
 * next page loads, instead of flashing a loading state on every page change.
 */
export function useNotificationCenterFeed(page: number) {
  return useQuery({
    queryKey: [...KEY, 'center', page],
    queryFn: () => notificationsService.list({ page }),
    placeholderData: keepPreviousData,
  });
}

/**
 * The caller's resolved popup/sound policy by priority (ADR-047 §14/§26.4-§26.8).
 * Preferences change far less often than the feed refreshes, so this polls on its own,
 * much slower cadence rather than the feed's 60s interval — refetched on window refocus
 * (the query default) so a preference change in another tab is picked up promptly.
 */
export function useAttentionPolicy() {
  return useQuery({
    queryKey: [...KEY, 'attention-policy'],
    queryFn: () => notificationsService.attentionPolicy(),
    staleTime: 5 * 60_000,
  });
}

export function useMarkNotificationRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationsService.markRead(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => notificationsService.markAllRead(),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

/** The permitted set, not everything — see notificationsService.markReadSet. */
export function useMarkNotificationsReadSet() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (ids: string[]) => notificationsService.markReadSet(ids),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  });
}

/** `null` means the user has never set a preference — the UI falls back to the resolved defaults. */
export function useNotificationPreferences() {
  return useQuery({
    queryKey: [...KEY, 'preferences'],
    queryFn: () => notificationsService.getPreferences(),
    staleTime: 5 * 60_000,
  });
}

export function useUpdateNotificationPreferences() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: NotificationPreferences) => notificationsService.updatePreferences(payload),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: [...KEY, 'preferences'] }).then(() =>
        // The effective per-priority table depends on the preference just written.
        queryClient.invalidateQueries({ queryKey: [...KEY, 'attention-policy'] }),
      ),
  });
}
