import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';

import { toast } from '@/components/ds/use-toast';

import { findNewlyArrivedIds } from '../lib/diff-new-notifications';
import { playAttentionSound } from '../lib/notification-sound';
import { toUiNotification, type NotificationPriority, type RawNotification } from '../types/notification';
import { useAttentionPolicy, useNotifications } from './use-notifications';

const TOAST_TYPE_BY_PRIORITY: Record<NotificationPriority, 'info' | 'warning' | 'error'> = {
  low: 'info',
  normal: 'info',
  high: 'warning',
  critical: 'error',
};

/**
 * The attention layer's arrival sequence (ADR-047 §26.7), transport-agnostic: with no
 * realtime substrate, "arrival" is the next poll tick revealing a row this session has
 * not observed before. Persistence (the notification already being in the feed/table)
 * always precedes this — this hook only decides whether to layer a popup/sound on top,
 * never whether the notification exists.
 *
 * Mount once (inside `NotificationCenter`, the bell's single mount point) — this is
 * intentionally not a second polling loop: it shares `useNotifications()`'s query cache.
 *
 * The first successful fetch establishes a baseline and pops nothing, so opening the app
 * does not replay a popup/sound for every notification that already existed — only
 * notifications that arrive after that baseline do. Each id can trigger at most once for
 * the lifetime of this hook: once observed, an id is never treated as new again.
 */
export function useNotificationAttention(): void {
  const { t } = useTranslation('common');
  const feed = useNotifications();
  const policy = useAttentionPolicy();
  const knownIds = useRef<Set<string> | null>(null);

  useEffect(() => {
    const rows = feed.data?.data;
    if (!rows) return;

    if (knownIds.current === null) {
      knownIds.current = new Set(rows.map((r) => r.id));
      return;
    }

    const newIds = findNewlyArrivedIds(knownIds.current, rows.map((r) => r.id));
    newIds.forEach((id) => knownIds.current!.add(id));

    if (newIds.length === 0 || !policy.data) return;

    const byId = new Map(rows.map((r) => [r.id, r] as const));
    const attentionPolicy = policy.data;

    // Oldest-first: a burst of arrivals pops/sounds in the order they actually happened.
    [...newIds].reverse().forEach((id) => {
      const raw = byId.get(id) as RawNotification | undefined;
      if (!raw) return;

      const priority: NotificationPriority = raw.priority ?? 'normal';
      const attention = attentionPolicy[priority];
      if (!attention) return;

      if (attention.popup) {
        const ui = toUiNotification(raw);
        const toastType = TOAST_TYPE_BY_PRIORITY[priority];
        toast[toastType](ui.message === '' ? t(($) => $.notifications.noMessage) : ui.message);
      }

      if (attention.sound && attention.sound_profile) {
        playAttentionSound(attention.sound_profile);
      }
    });
  }, [feed.data, policy.data, t]);
}
