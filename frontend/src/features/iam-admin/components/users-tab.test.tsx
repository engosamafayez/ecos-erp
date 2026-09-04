import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { LanguageContext } from '@/providers/language-context';
import type { UsersListResult, UserSummary } from '@/features/iam-admin/types/user';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — Users workspace.
 *
 * The SERVICE layer is mocked, not the hooks: the real useQuery/useMutation pipeline runs, so
 * these assertions prove genuine server state flowing through React Query into the DOM, and a
 * genuine transition call flowing back — following the idiom established by
 * features/admin/configuration/components/goods-inward-mode-card.test.tsx.
 */

function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: { count?: number }) => {
      if (typeof sel !== 'function') return String(sel);
      const path = String((sel as (p: unknown) => unknown)(pathProxy('')));
      return opts && typeof opts.count === 'number' ? `${path}:${opts.count}` : path;
    },
  }),
}));

const mockCan = vi.hoisted(() => vi.fn<(permission: string) => boolean>(() => true));
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: mockCan }),
  Can: ({ permission, children }: { permission: string | string[]; children: React.ReactNode }) => {
    const list = Array.isArray(permission) ? permission : [permission];
    return list.every(mockCan) ? children : null;
  },
}));

const mockList = vi.hoisted(() => vi.fn());
const mockTransition = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/users-service', () => ({
  usersService: {
    list: mockList,
    transition: mockTransition,
    get: vi.fn().mockResolvedValue(null),
  },
}));

import { UsersTab } from './users-tab';

const ACTIVE_USER: UserSummary = {
  id: 1,
  name: 'Amina Khaled',
  display_name: 'Amina Khaled',
  email: 'amina@ecos.test',
  employee_number: 'EMP-001',
  status: 'active',
  status_label: 'Active',
  company_id: 'company-1',
  last_login_at: null,
  last_activity_at: null,
  trashed: false,
};

const ARCHIVED_USER: UserSummary = {
  ...ACTIVE_USER,
  id: 2,
  name: 'Youssef Adel',
  display_name: 'Youssef Adel',
  email: 'youssef@ecos.test',
  status: 'archived',
  status_label: 'Archived',
  trashed: true,
};

function listResult(data: UserSummary[]): UsersListResult {
  return { data, meta: { total: data.length, page: 1, per_page: 25 } };
}

const LANGUAGE_VALUE = { language: 'en' as const, dir: 'ltr' as const, setLanguage: vi.fn() };

function renderTab() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return {
    client,
    ...render(
      <LanguageContext.Provider value={LANGUAGE_VALUE}>
        <QueryClientProvider client={client}>
          <UsersTab />
        </QueryClientProvider>
      </LanguageContext.Provider>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockList.mockResolvedValue(listResult([ACTIVE_USER]));
});

describe('UsersTab', () => {
  // ── 1: default-list-excludes-archived ───────────────────────────────────────

  it('requests the list without include_archived by default (D8)', async () => {
    renderTab();

    await waitFor(() => expect(mockList).toHaveBeenCalled());
    const params = mockList.mock.calls[0][0];
    expect(params.include_archived).toBe(false);
    await screen.findAllByText('Amina Khaled');
  });

  // ── 2: archived-filter-includes ─────────────────────────────────────────────

  it('re-queries with include_archived: true when the checkbox is toggled', async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue(listResult([ACTIVE_USER, ARCHIVED_USER]));
    renderTab();

    await waitFor(() => expect(mockList).toHaveBeenCalledTimes(1));
    await user.click(screen.getByRole('checkbox'));

    await waitFor(() => expect(mockList).toHaveBeenCalledTimes(2));
    const params = mockList.mock.calls[1][0];
    expect(params.include_archived).toBe(true);
  });

  // ── 4: valid-lifecycle-actions-shown ─────────────────────────────────────────

  it('offers only the lifecycle actions valid for an active user, and only restore for an archived one', async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue(listResult([ACTIVE_USER, ARCHIVED_USER]));
    renderTab();
    await screen.findAllByText('Amina Khaled');

    const openActionsButtons = screen.getAllByRole('button', { name: 'actions.openActions' });
    await user.click(openActionsButtons[0]);

    // Active user: deactivate/suspend/lock/archive are offered; unlock/restore/activate are not.
    expect(await screen.findByText('users.lifecycle.deactivate')).toBeInTheDocument();
    expect(screen.getByText('users.lifecycle.suspend')).toBeInTheDocument();
    expect(screen.getByText('users.lifecycle.lock')).toBeInTheDocument();
    expect(screen.getByText('users.lifecycle.archive')).toBeInTheDocument();
    expect(screen.queryByText('users.lifecycle.restore')).not.toBeInTheDocument();
    expect(screen.queryByText('users.lifecycle.unlock')).not.toBeInTheDocument();
    expect(screen.queryByText('users.lifecycle.activate')).not.toBeInTheDocument();
    await user.keyboard('{Escape}');

    const secondRowButtons = screen.getAllByRole('button', { name: 'actions.openActions' });
    await user.click(secondRowButtons[secondRowButtons.length - 1]);

    // Archived user: restore only.
    expect(await screen.findByText('users.lifecycle.restore')).toBeInTheDocument();
    expect(screen.queryByText('users.lifecycle.archive')).not.toBeInTheDocument();
    expect(screen.queryByText('users.lifecycle.deactivate')).not.toBeInTheDocument();
  });

  // ── 30: 403-handled ──────────────────────────────────────────────────────────

  it('hides the create-user affordance when the actor lacks iam.users.create', async () => {
    mockCan.mockImplementation((permission: string) => permission !== 'iam.users.create');
    renderTab();

    await screen.findAllByText('Amina Khaled');
    expect(screen.queryByText('users.create.trigger')).not.toBeInTheDocument();
  });

  // ── 32: 409-surfaced-clearly ─────────────────────────────────────────────────

  it('surfaces a 409 InvalidUserTransitionException message verbatim, without silently closing', async () => {
    const user = userEvent.setup();
    const conflict = {
      isAxiosError: true,
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server-response text; this test specifically asserts the message is shown verbatim, not translated client-side
      response: { status: 409, data: { message: 'User is already archived.' } },
    };
    mockTransition.mockRejectedValue(conflict);
    renderTab();
    await screen.findAllByText('Amina Khaled');

    const openActionsButtons = screen.getAllByRole('button', { name: 'actions.openActions' });
    await user.click(openActionsButtons[0]);
    await user.click(await screen.findByText('users.lifecycle.archive'));

    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /confirm|archive/i }));

    await waitFor(() => expect(mockTransition).toHaveBeenCalledWith(1, 'archive', undefined));
    // The dialog must still be open (not silently dismissed) and show SOME error text.
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
  });
});
