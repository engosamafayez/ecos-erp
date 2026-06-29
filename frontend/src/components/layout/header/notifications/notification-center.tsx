import { useState } from 'react';
import {
  Bell,
  Boxes,
  CircleDollarSign,
  Settings,
  ShoppingBag,
  PlugZap,
} from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  Sheet,
  SheetContent,
  SheetTitle,
} from '@/components/ui/sheet';

import {
  ALL_CATEGORIES,
  CATEGORY_LABELS,
  MOCK_NOTIFICATIONS,
} from './notifications-mock-data';
import type { Notification, NotificationCategory } from './notifications-mock-data';

// ── Category icon map ─────────────────────────────────────────────────────────

const CATEGORY_ICONS: Record<NotificationCategory, typeof Bell> = {
  orders: ShoppingBag,
  inventory: Boxes,
  finance: CircleDollarSign,
  system: Settings,
  integrations: PlugZap,
};

// ── Notification item ─────────────────────────────────────────────────────────

function NotificationItem({
  notification,
  onMarkRead,
}: {
  notification: Notification;
  onMarkRead: (id: string) => void;
}) {
  const Icon = CATEGORY_ICONS[notification.category];

  return (
    <div
      className={cn(
        'group flex gap-3 px-4 py-3 transition-colors hover:bg-accent/40',
        !notification.read && 'bg-primary/3',
      )}
    >
      {/* Category icon */}
      <span
        className={cn(
          'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
          !notification.read
            ? 'bg-primary/10 text-primary'
            : 'bg-muted text-muted-foreground',
        )}
        aria-hidden
      >
        <Icon className="size-4" />
      </span>

      {/* Content */}
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <p
            className={cn(
              'text-sm leading-tight',
              !notification.read ? 'font-semibold' : 'font-medium',
            )}
          >
            {notification.title}
          </p>
          {/* Unread dot */}
          {!notification.read ? (
            <span
              className="mt-1 size-2 shrink-0 rounded-full bg-primary"
              aria-label="Unread"
            />
          ) : null}
        </div>
        <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
          {notification.body}
        </p>
        <div className="mt-1.5 flex items-center gap-3">
          <span className="text-[10px] text-muted-foreground/70">{notification.time}</span>
          {!notification.read ? (
            <button
              type="button"
              onClick={() => onMarkRead(notification.id)}
              className="text-[10px] font-medium text-primary opacity-0 transition-opacity hover:text-primary/80 group-hover:opacity-100"
            >
              Mark read
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

type Filter = 'all' | NotificationCategory;

const FILTER_TABS: { value: Filter; label: string }[] = [
  { value: 'all', label: 'All' },
  ...ALL_CATEGORIES.map((c) => ({ value: c as Filter, label: CATEGORY_LABELS[c] })),
];

export function NotificationCenter() {
  const [open, setOpen] = useState(false);
  const [notifications, setNotifications] = useState<Notification[]>(MOCK_NOTIFICATIONS);
  const [activeFilter, setActiveFilter] = useState<Filter>('all');

  const unreadCount = notifications.filter((n) => !n.read).length;

  const filtered =
    activeFilter === 'all'
      ? notifications
      : notifications.filter((n) => n.category === activeFilter);

  function markRead(id: string) {
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, read: true } : n)),
    );
  }

  function markAllRead() {
    setNotifications((prev) => prev.map((n) => ({ ...n, read: true })));
  }

  return (
    <>
      {/* ── Trigger button ── */}
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setOpen(true)}
        aria-label={
          unreadCount > 0
            ? `Notifications — ${unreadCount} unread`
            : 'Notifications'
        }
        className="relative"
      >
        <Bell className="size-5" aria-hidden />
        {unreadCount > 0 ? (
          <span
            aria-hidden
            className="absolute -right-0.5 -top-0.5 flex min-w-[1rem] items-center justify-center rounded-full bg-primary px-0.5 text-[9px] font-bold leading-4 text-primary-foreground"
          >
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        ) : null}
      </Button>

      {/* ── Notification drawer ── */}
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent
          side="right"
          className="flex w-full flex-col gap-0 p-0 sm:max-w-sm"
        >
          {/* Header */}
          <div className="flex shrink-0 items-center justify-between border-b px-4 py-3 pe-12">
            <div>
              <SheetTitle className="text-base font-semibold">Notifications</SheetTitle>
              {unreadCount > 0 ? (
                <p className="text-xs text-muted-foreground">{unreadCount} unread</p>
              ) : (
                <p className="text-xs text-muted-foreground">All caught up</p>
              )}
            </div>
            <Button
              variant="ghost"
              size="sm"
              onClick={markAllRead}
              disabled={unreadCount === 0}
              className="h-7 text-xs"
            >
              Mark all read
            </Button>
          </div>

          {/* Category filter tabs */}
          <div className="flex shrink-0 gap-1 overflow-x-auto border-b px-3 py-2">
            {FILTER_TABS.map((tab) => {
              const catCount =
                tab.value === 'all'
                  ? notifications.filter((n) => !n.read).length
                  : notifications.filter(
                      (n) => n.category === tab.value && !n.read,
                    ).length;
              return (
                <button
                  key={tab.value}
                  type="button"
                  onClick={() => setActiveFilter(tab.value)}
                  aria-pressed={activeFilter === tab.value}
                  className={cn(
                    'flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition-colors',
                    activeFilter === tab.value
                      ? 'bg-primary text-primary-foreground'
                      : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                  )}
                >
                  {tab.label}
                  {catCount > 0 ? (
                    <span
                      className={cn(
                        'rounded-full px-1 py-0.5 text-[9px] font-bold leading-none',
                        activeFilter === tab.value
                          ? 'bg-primary-foreground/20 text-primary-foreground'
                          : 'bg-primary/10 text-primary',
                      )}
                    >
                      {catCount}
                    </span>
                  ) : null}
                </button>
              );
            })}
          </div>

          {/* Notification list */}
          <div className="flex-1 overflow-y-auto">
            {filtered.length === 0 ? (
              <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <Bell className="size-10 text-muted-foreground/20" aria-hidden />
                <div>
                  <p className="text-sm font-medium text-muted-foreground">
                    No notifications
                  </p>
                  <p className="mt-0.5 text-xs text-muted-foreground/60">
                    {activeFilter === 'all'
                      ? "You're all caught up!"
                      : `No ${CATEGORY_LABELS[activeFilter as NotificationCategory]} notifications`}
                  </p>
                </div>
              </div>
            ) : (
              <div className="divide-y">
                {filtered.map((notification) => (
                  <NotificationItem
                    key={notification.id}
                    notification={notification}
                    onMarkRead={markRead}
                  />
                ))}
              </div>
            )}
          </div>

          {/* Footer */}
          <div className="shrink-0 border-t px-4 py-3">
            <button
              type="button"
              disabled
              className="flex w-full cursor-not-allowed items-center justify-center gap-1 text-sm font-medium text-primary/50"
            >
              View all notifications
              <span className="rounded-full border border-primary/30 bg-primary/5 px-1.5 py-0.5 text-[9px] font-medium text-primary/70">
                Soon
              </span>
            </button>
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}
