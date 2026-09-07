import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import {
  Bell,
  Boxes,
  CircleAlert,
  CircleCheck,
  Info,
  Megaphone,
  Monitor,
  Settings as SettingsIcon,
  Truck,
  TriangleAlert,
  Zap,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { EmptyState, ErrorState, LoadingState, Pagination } from '@/components/crud';
import { useFormatter } from '@/hooks/use-formatter';
import { NotificationPreferencesButton } from '@/features/notifications/components/notification-preferences';
import { useNotificationAttention } from '@/features/notifications/hooks/use-notification-attention';
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotificationCenterFeed,
  useUnreadNotificationCount,
  type NotificationReadFilter,
} from '@/features/notifications/hooks/use-notifications';
import { resolveNotificationTarget } from '@/features/notifications/lib/resolve-notification-target';
import {
  toUiNotification,
  type NotificationPriority,
  type NotificationSeverity,
  type NotificationSource,
  type UiNotification,
} from '@/features/notifications/types/notification';

/**
 * The notification centre in the top bar.
 *
 * Reads GET /api/notifications — the authenticated user's own feed, scoped
 * server-side by ownership. It shows what producers actually wrote and nothing
 * else: the message text is the producer's, the timestamp is the record's, and
 * a feed with no rows says so rather than filling the drawer.
 *
 * Filter tabs are derived from the sources present on the current page, not from a
 * fixed taxonomy. A notification from a module nobody has categorised still
 * appears, under `other`, instead of being dropped because no tab claimed it.
 *
 * TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004: this is the one
 * user-facing Notification Center (no separate inbox page — ADR-047's Vanilla-Plus V1
 * scope does not include the full Enterprise Inbox from `docs/ux/NOTIFICATION-UX-
 * STANDARD.md` §6; that document's layout/badge conventions are reused here for styling
 * only, not its Later-scoped feature set — labels, snooze, share, escalations, external
 * channels, and Reverb realtime all remain out of scope per that ADR and this task's own
 * instruction). Pagination now uses the project's own `Pagination` component/query-shape,
 * and the list is driven by `useNotificationCenterFeed(page)` — a separate query from
 * `useNotifications()` (unread count + attention detection) so browsing to page 2 never
 * changes what the badge or the arrival-toast logic is watching (§14 — one unread-count
 * authority, one persistent-notification authority).
 */

const SOURCE_ICONS: Record<NotificationSource, LucideIcon> = {
  operations: Boxes,
  logistics: Truck,
  marketing: Megaphone,
  pos: Monitor,
  system: SettingsIcon,
  other: Bell,
};

const SEVERITY_ICONS: Record<NotificationSeverity, LucideIcon> = {
  info: Info,
  success: CircleCheck,
  warning: TriangleAlert,
  error: CircleAlert,
};

const SEVERITY_TONE: Record<NotificationSeverity, string> = {
  info: 'text-muted-foreground',
  success: 'text-emerald-600',
  warning: 'text-amber-600',
  error: 'text-red-600',
};

/**
 * ADR-047 §7 / `docs/ux/NOTIFICATION-UX-STANDARD.md` §4's locked badge convention —
 * priority is presentation-only here (never inferred/upgraded client-side, §12): NORMAL
 * gets no badge at all, matching the standard exactly.
 */
function PriorityBadge({ priority }: { priority: NotificationPriority | undefined }) {
  const { t } = useTranslation('common');
  if (!priority || priority === 'normal') return null;

  const label = t(($) => $.notifications.priority[priority]);

  if (priority === 'critical') {
    return (
      <Badge variant="destructive" className="gap-1">
        <Zap className="size-3" aria-hidden />
        {label}
      </Badge>
    );
  }
  if (priority === 'high') {
    return (
      <Badge variant="outline" className="gap-1 border-amber-300 text-amber-700 dark:text-amber-500">
        {label}
      </Badge>
    );
  }
  // low
  return (
    <Badge variant="outline" className="text-muted-foreground/70">
      {label}
    </Badge>
  );
}

function NotificationRow({
  notification,
  onMarkRead,
  onView,
}: {
  notification: UiNotification;
  onMarkRead: (id: string) => void;
  /** TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §4 — marks read AND navigates, in that order. */
  onView: (id: string, path: string) => void;
}) {
  const { t } = useTranslation('common');
  const fmt = useFormatter();

  const SourceIcon = SOURCE_ICONS[notification.source];
  const SeverityIcon = SEVERITY_ICONS[notification.severity];
  const target = resolveNotificationTarget(notification);

  // The category taxonomy (ADR-047 §6) is the more meaningful, action-oriented title
  // once a producer supplies it; a row written before the schema extension (or by a
  // producer that never migrated) has none and falls back to the source label exactly
  // as before — never a crash, never a blank title.
  const title = notification.category
    ? t(($) => $.notifications.category[notification.category!])
    : t(($) => $.notifications.source[notification.source]);

  return (
    <div
      className={cn(
        'group flex gap-3 px-4 py-3 transition-colors hover:bg-accent/40',
        !notification.read && 'bg-primary/3',
      )}
    >
      <span
        className={cn(
          'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
          notification.read ? 'bg-muted text-muted-foreground' : 'bg-primary/10 text-primary',
        )}
        aria-hidden
      >
        <SourceIcon className="size-4" />
      </span>

      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <p
            className={cn(
              'flex flex-wrap items-center gap-1.5 text-sm leading-tight',
              notification.read ? 'font-medium' : 'font-semibold',
            )}
          >
            <SeverityIcon
              className={cn('size-3.5 shrink-0', SEVERITY_TONE[notification.severity])}
              aria-hidden
            />
            {title}
            <PriorityBadge priority={notification.priority} />
          </p>

          {!notification.read && (
            <span
              className="mt-1 size-2 shrink-0 rounded-full bg-primary"
              aria-label={t(($) => $.notifications.unread)}
            />
          )}
        </div>

        <p className="mt-0.5 line-clamp-3 text-xs text-muted-foreground">
          {notification.message === '' ? t(($) => $.notifications.noMessage) : notification.message}
        </p>

        <div className="mt-1.5 flex items-center gap-3">
          <span className="text-[10px] text-muted-foreground/70">
            {notification.createdAt ? fmt.dateTime(notification.createdAt) : ''}
          </span>
          {target && (
            <button
              type="button"
              onClick={() => onView(notification.id, target)}
              className="text-[10px] font-medium text-primary hover:text-primary/80"
            >
              {t(($) => $.notifications.viewDetails)}
            </button>
          )}
          {!notification.read && (
            <button
              type="button"
              onClick={() => onMarkRead(notification.id)}
              className="text-[10px] font-medium text-primary opacity-0 transition-opacity hover:text-primary/80 group-hover:opacity-100 focus-visible:opacity-100"
            >
              {t(($) => $.notifications.markRead)}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

export function NotificationCenter() {
  const { t } = useTranslation('common');
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  // TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §5 — Unread/Read, at
  // least; "Unread" is the default landing tab since that's what a user opening the
  // bell almost always wants to see first.
  const [readFilter, setReadFilter] = useState<NotificationReadFilter>('unread');
  const [activeSource, setActiveSource] = useState<NotificationSource | 'all'>('all');
  const [page, setPage] = useState(1);

  const centerFeed = useNotificationCenterFeed(page, readFilter);
  const markRead = useMarkNotificationRead();
  const markAllRead = useMarkAllNotificationsRead();
  const unreadCount = useUnreadNotificationCount();

  function selectReadFilter(filter: NotificationReadFilter) {
    setReadFilter(filter);
    setActiveSource('all');
    setPage(1);
  }

  // The bell is this hook's single mount point app-wide (TASK-ECOS-NOTIFICATIONS-
  // ATTENTION-EXPERIENCE-003) — it watches useNotifications()'s own query (always page 1),
  // never this component's paginated browsing state.
  useNotificationAttention();

  function selectSource(source: NotificationSource | 'all') {
    setActiveSource(source);
    // Changing filter/tab while deep into pagination would otherwise strand the user on
    // a now-out-of-context page number. Reset directly in the event handler, not a
    // reactive effect — this is a discrete user action, not state to synchronize.
    setPage(1);
  }

  const notifications = useMemo(
    () => (centerFeed.data?.data ?? []).map(toUiNotification),
    [centerFeed.data],
  );

  // Only sources actually present on the current page get a tab — an empty tab would
  // advertise a category the platform never produces (or simply isn't on this page).
  const sources = useMemo(() => {
    const seen = new Set<NotificationSource>();
    notifications.forEach((n) => seen.add(n.source));
    return [...seen];
  }, [notifications]);

  const visible =
    activeSource === 'all' ? notifications : notifications.filter((n) => n.source === activeSource);

  /**
   * §4 — every actionable notification: mark read, then update unread count/list
   * (`markRead.mutate`'s own `onSuccess` invalidates the whole notifications query
   * prefix, which is what actually removes this row from an active "Unread" tab), then
   * navigate. Unconditional mark-read here is safe even if already read (the backend
   * mutation is idempotent — `markAsRead()` on an already-read row is a no-op).
   */
  function handleView(id: string, path: string) {
    markRead.mutate(id);
    setOpen(false);
    navigate(path);
  }

  return (
    <>
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setOpen(true)}
        aria-label={
          unreadCount > 0
            ? t(($) => $.notifications.bellWithUnread, { count: unreadCount })
            : t(($) => $.notifications.bell)
        }
        className="relative"
      >
        <Bell className="size-5" aria-hidden />
        {unreadCount > 0 && (
          <span
            aria-hidden
            className="absolute -top-0.5 flex min-w-[1rem] items-center justify-center rounded-full bg-primary px-0.5 text-[9px] font-bold leading-4 text-primary-foreground end-[-0.125rem]"
          >
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </Button>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-sm">
          <div className="flex shrink-0 items-center justify-between border-b px-4 py-3 pe-12">
            <div>
              <SheetTitle className="text-base font-semibold">
                {t(($) => $.notifications.title)}
              </SheetTitle>
              <p className="text-xs text-muted-foreground">
                {unreadCount > 0
                  ? t(($) => $.notifications.unreadCount, { count: unreadCount })
                  : t(($) => $.notifications.allCaughtUp)}
              </p>
            </div>
            <div className="flex items-center gap-1">
              <NotificationPreferencesButton />
              <Button
                variant="ghost"
                size="sm"
                onClick={() => markAllRead.mutate()}
                disabled={unreadCount === 0 || markAllRead.isPending}
                className="h-7 text-xs"
              >
                {t(($) => $.notifications.markAllRead)}
              </Button>
            </div>
          </div>

          {/* TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §5 — the
              Unread/Read workspace split, independent of (and above) the existing
              per-source filter tabs below. */}
          <div className="flex shrink-0 gap-1 border-b px-3 py-2">
            {(['unread', 'read'] as const).map((filter) => (
              <button
                key={filter}
                type="button"
                onClick={() => selectReadFilter(filter)}
                aria-pressed={readFilter === filter}
                className={cn(
                  'rounded-full px-2.5 py-1 text-xs font-medium transition-colors',
                  readFilter === filter
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                )}
              >
                {filter === 'unread' ? t(($) => $.notifications.unread) : t(($) => $.notifications.read)}
              </button>
            ))}
          </div>

          {sources.length > 1 && (
            <div className="flex shrink-0 gap-1 overflow-x-auto border-b px-3 py-2">
              {(['all', ...sources] as const).map((source) => {
                const count =
                  source === 'all'
                    ? notifications.filter((n) => !n.read).length
                    : notifications.filter((n) => n.source === source && !n.read).length;

                return (
                  <button
                    key={source}
                    type="button"
                    onClick={() => selectSource(source)}
                    aria-pressed={activeSource === source}
                    className={cn(
                      'flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition-colors',
                      activeSource === source
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                    )}
                  >
                    {source === 'all'
                      ? t(($) => $.notifications.all)
                      : t(($) => $.notifications.source[source])}
                    {count > 0 && (
                      <span
                        className={cn(
                          'rounded-full px-1 py-0.5 text-[9px] font-bold leading-none',
                          activeSource === source
                            ? 'bg-primary-foreground/20 text-primary-foreground'
                            : 'bg-primary/10 text-primary',
                        )}
                      >
                        {count}
                      </span>
                    )}
                  </button>
                );
              })}
            </div>
          )}

          <div className="flex-1 overflow-y-auto">
            {/* `data` first, on purpose: a transient background refetch failure must
                keep showing the last-good page rather than replacing it with a blocking
                error (TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004
                §6/§8) — only the true "never loaded anything, and it failed" case earns
                the full-page ErrorState below. */}
            {centerFeed.data ? (
              visible.length === 0 ? (
                <EmptyState
                  icon={Bell}
                  title={t(($) => $.notifications.empty)}
                  description={t(($) => $.notifications.emptyHint)}
                />
              ) : (
                <div className="divide-y">
                  {visible.map((notification) => (
                    <NotificationRow
                      key={notification.id}
                      notification={notification}
                      onMarkRead={(id) => markRead.mutate(id)}
                      onView={handleView}
                    />
                  ))}
                </div>
              )
            ) : centerFeed.isLoading ? (
              <LoadingState />
            ) : (
              <ErrorState
                description={t(($) => $.notifications.loadFailed)}
                onRetry={() => centerFeed.refetch()}
              />
            )}
          </div>

          {centerFeed.data && centerFeed.data.meta.lastPage > 1 && (
            <div className="shrink-0 border-t px-4 py-3">
              <Pagination meta={centerFeed.data.meta} onPageChange={setPage} />
            </div>
          )}
        </SheetContent>
      </Sheet>
    </>
  );
}
