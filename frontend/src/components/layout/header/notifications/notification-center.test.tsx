import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { NotificationPage } from '@/features/notifications/types/notification';

/**
 * TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004.
 *
 * Service layer mocked, real useQuery/useMutation pipeline runs — matching this repo's
 * established idiom (see customer-drawer.test.tsx). Covers §3 (list/empty/loading/error/
 * pagination/mark-read/mark-all-read), §6/§8 (transient-failure resilience), and §14 (one
 * unread-count authority independent of the page the Center happens to be browsing).
 *
 * The shared `t(($) => $.a.b.c)` mock (copied from the established pattern) resolves to
 * only the *last* path segment (e.g. `c`, not `a.b.c`) — every assertion below matches on
 * that last segment, not the dotted key.
 */

const mockList = vi.hoisted(() => vi.fn());
const mockMarkRead = vi.hoisted(() => vi.fn());
const mockMarkAllRead = vi.hoisted(() => vi.fn());
const mockMarkReadSet = vi.hoisted(() => vi.fn());
const mockAttentionPolicy = vi.hoisted(() => vi.fn());
const mockGetPreferences = vi.hoisted(() => vi.fn());
const mockUpdatePreferences = vi.hoisted(() => vi.fn());

vi.mock('@/features/notifications/services/notifications-service', () => ({
  notificationsService: {
    list: mockList,
    markRead: mockMarkRead,
    markAllRead: mockMarkAllRead,
    markReadSet: mockMarkReadSet,
    attentionPolicy: mockAttentionPolicy,
    getPreferences: mockGetPreferences,
    updatePreferences: mockUpdatePreferences,
  },
}));

const mockNavigate = vi.hoisted(() => vi.fn());
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}));

// useFormatter needs LanguageContext + CompanyContext, neither relevant to this
// component's own behavior — stubbed exactly like the established
// useOrganizationContext/useOrdersQuery mocks in customer-drawer.test.tsx.
vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({ dateTime: (iso: string | null) => iso ?? '' }),
}));

// The shared Pagination component also reads LanguageContext directly (RTL icon swap).
vi.mock('@/providers/language-context', () => ({
  useLanguage: () => ({ language: 'en', dir: 'ltr', setLanguage: vi.fn() }),
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: { count?: number; defaultValue?: string }) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy(
        {},
        { get: (_t, prop: string) => { path.push(prop); return proxy; } },
      );
      (sel as (p: unknown) => unknown)(proxy);
      const key = path[path.length - 1] ?? '';
      if (opts?.count !== undefined) return `${key}:${opts.count}`;
      return opts?.defaultValue ?? key;
    },
  }),
}));

import { NotificationCenter } from './notification-center';

function page(overrides: Partial<NotificationPage> = {}): NotificationPage {
  return {
    data: [],
    unread_count: 0,
    meta: { page: 1, perPage: 25, total: 0, lastPage: 1 },
    ...overrides,
  };
}

function row(id: string, overrides: Partial<NotificationPage['data'][number]> = {}) {
  return {
    id,
    type: 'Modules\\Operations\\Preparation\\Application\\Notifications\\WaveStartedNotification',
    data: { message: `Message ${id}` },
    read_at: null,
    created_at: '2026-09-06T00:00:00Z',
    priority: 'normal' as const,
    category: 'alert' as const,
    ...overrides,
  };
}

function renderCenter() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <NotificationCenter />
    </QueryClientProvider>,
  );
}

async function openDrawer() {
  await userEvent.click(screen.getByRole('button', { name: /bell/ }));
}

beforeEach(() => {
  vi.clearAllMocks();
  mockAttentionPolicy.mockResolvedValue({
    low: { popup: false, sound: false, sound_profile: null, locked: false },
    normal: { popup: true, sound: false, sound_profile: 'normal', locked: false },
    high: { popup: true, sound: true, sound_profile: 'important', locked: false },
    critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
  });
  mockGetPreferences.mockResolvedValue(null);
});

describe('NotificationCenter', () => {
  it('renders unread and read items, with the badge sourced from the shared feed', async () => {
    mockList.mockResolvedValue(
      page({
        unread_count: 1,
        data: [row('n1', { read_at: null }), row('n2', { read_at: '2026-09-05T00:00:00Z' })],
        meta: { page: 1, perPage: 25, total: 2, lastPage: 1 },
      }),
    );

    renderCenter();
    await waitFor(() => expect(screen.getByText('1')).toBeInTheDocument()); // badge count
    await openDrawer();

    expect(await screen.findByText('Message n1')).toBeInTheDocument();
    expect(screen.getByText('Message n2')).toBeInTheDocument();
  });

  it('shows the empty state with zero notifications', async () => {
    mockList.mockResolvedValue(page());
    renderCenter();
    await openDrawer();
    expect(await screen.findByText('empty')).toBeInTheDocument();
    expect(screen.getByText('emptyHint')).toBeInTheDocument();
  });

  it('shows the loading state before the first response arrives', async () => {
    let resolveList!: (v: NotificationPage) => void;
    mockList.mockReturnValue(new Promise((resolve) => { resolveList = resolve; }));

    renderCenter();
    await openDrawer();
    expect(screen.getByText('loading')).toBeInTheDocument();

    resolveList(page());
    await waitFor(() => expect(screen.queryByText('loading')).not.toBeInTheDocument());
  });

  it('shows a truthful error state when the very first load fails (no data at all)', async () => {
    mockList.mockRejectedValue(new Error('network down'));
    renderCenter();
    await openDrawer();
    expect(await screen.findByText('loadFailed')).toBeInTheDocument();
  });

  it('does NOT blank the list when a later background refetch fails — keeps showing the last-good page', async () => {
    mockList.mockResolvedValueOnce(
      page({ unread_count: 1, data: [row('n1')], meta: { page: 1, perPage: 25, total: 1, lastPage: 1 } }),
    );
    renderCenter();
    await openDrawer();
    expect(await screen.findByText('Message n1')).toBeInTheDocument();

    // The next call (triggered by the mark-read mutation's invalidation below) fails —
    // a real "transient read failure", not a contrived state mutation.
    mockList.mockRejectedValue(new Error('transient'));
    mockMarkAllRead.mockResolvedValue(0);

    await userEvent.click(screen.getByText('markAllRead'));

    // Give the failed background refetch a tick to (not) take effect.
    await waitFor(() => expect(mockList).toHaveBeenCalledTimes(2));
    expect(screen.getByText('Message n1')).toBeInTheDocument();
    expect(screen.queryByText('loadFailed')).not.toBeInTheDocument();
  });

  it('marks one notification read', async () => {
    mockList.mockResolvedValue(
      page({ unread_count: 1, data: [row('n1', { read_at: null })], meta: { page: 1, perPage: 25, total: 1, lastPage: 1 } }),
    );
    mockMarkRead.mockResolvedValue(undefined);

    renderCenter();
    await openDrawer();
    await screen.findByText('Message n1');

    await userEvent.click(screen.getByText('markRead'));
    expect(mockMarkRead).toHaveBeenCalledWith('n1');
  });

  it('disables mark-all-read when nothing is unread', async () => {
    mockList.mockResolvedValue(
      page({ unread_count: 0, data: [row('n1', { read_at: '2026-09-05T00:00:00Z' })], meta: { page: 1, perPage: 25, total: 1, lastPage: 1 } }),
    );
    renderCenter();
    await openDrawer();
    await screen.findByText('Message n1');

    expect(screen.getByText('markAllRead').closest('button')).toBeDisabled();
  });

  it('paginates via the shared Pagination component, requesting the new page from the server', async () => {
    mockList.mockImplementation(({ page: p }: { page?: number } = {}) =>
      Promise.resolve(
        p === 2
          ? page({ data: [row('n-page2')], meta: { page: 2, perPage: 25, total: 30, lastPage: 2 } })
          : page({ data: [row('n-page1')], meta: { page: 1, perPage: 25, total: 30, lastPage: 2 } }),
      ),
    );

    renderCenter();
    await openDrawer();
    await screen.findByText('Message n-page1');

    await userEvent.click(screen.getByText('next'));

    await waitFor(() => expect(screen.getByText('Message n-page2')).toBeInTheDocument());
    expect(mockList).toHaveBeenCalledWith(expect.objectContaining({ page: 2 }));
  });

  it('keeps the unread badge sourced from page 1 regardless of which page the Center is browsing (§14)', async () => {
    mockList.mockImplementation(({ page: p }: { page?: number } = {}) =>
      Promise.resolve(
        p === 2
          ? page({ unread_count: 999, data: [row('n-page2')], meta: { page: 2, perPage: 25, total: 60, lastPage: 3 } })
          : page({ unread_count: 5, data: [row('n-page1')], meta: { page: 1, perPage: 25, total: 60, lastPage: 3 } }),
      ),
    );

    renderCenter();
    await waitFor(() => expect(screen.getByText('5')).toBeInTheDocument());
    await openDrawer();
    await screen.findByText('Message n-page1');

    // The drawer's own "N unread" subtitle is the same useUnreadNotificationCount()
    // authority as the bell badge (the badge itself becomes aria-hidden — correctly —
    // while the modal Sheet is open, so it isn't queryable by role here).
    expect(screen.getByText('unreadCount:5')).toBeInTheDocument();

    await userEvent.click(screen.getByText('next'));
    await waitFor(() => expect(screen.getByText('Message n-page2')).toBeInTheDocument());

    // Still 5 (page 1's unread_count), never 999 (page 2's) — the Center's own
    // paginated query never feeds the unread-count authority.
    expect(screen.getByText('unreadCount:5')).toBeInTheDocument();
  });

  // ── TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §5 ───────────────────

  it('§5: defaults to the Unread tab, and switching to Read re-queries with unread=false', async () => {
    mockList.mockImplementation(({ unread }: { unread?: boolean } = {}) =>
      Promise.resolve(
        unread === false
          ? page({ data: [row('n-read', { read_at: '2026-09-05T00:00:00Z' })], meta: { page: 1, perPage: 25, total: 1, lastPage: 1 } })
          : page({ data: [row('n-unread', { read_at: null })], meta: { page: 1, perPage: 25, total: 1, lastPage: 1 } }),
      ),
    );

    renderCenter();
    await openDrawer();
    await screen.findByText('Message n-unread');
    expect(mockList).toHaveBeenCalledWith(expect.objectContaining({ unread: true }));

    await userEvent.click(screen.getByText('read'));

    await waitFor(() => expect(screen.getByText('Message n-read')).toBeInTheDocument());
    expect(mockList).toHaveBeenCalledWith(expect.objectContaining({ unread: false }));
  });

  // ── TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §4 ───────────────────

  it('§4: clicking View marks the notification read AND navigates, in one action', async () => {
    mockList.mockResolvedValue(
      page({
        unread_count: 1,
        data: [row('n1', { read_at: null, deep_link: { entity_type: 'customer', entity_id: 'c1', action_key: null, route: null } })],
        meta: { page: 1, perPage: 25, total: 1, lastPage: 1 },
      }),
    );
    mockMarkRead.mockResolvedValue(undefined);

    renderCenter();
    await openDrawer();
    await screen.findByText('Message n1');

    await userEvent.click(screen.getByText('viewDetails'));

    expect(mockMarkRead).toHaveBeenCalledWith('n1');
    expect(mockNavigate).toHaveBeenCalledWith(expect.stringContaining('c1'));
  });
});
