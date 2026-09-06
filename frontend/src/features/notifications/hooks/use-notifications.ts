import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { notificationsService } from '../services/notifications-service';

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
