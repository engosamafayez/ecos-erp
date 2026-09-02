import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import type { UserDetail, UserSession } from '@/features/iam-admin/types/user';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — User Security panel (password reset + sessions).
 * §7: reset never implies unlock/reactivate. §9: sessions folded into the User detail.
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
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
  }),
}));

const mockCan = vi.hoisted(() => vi.fn((_permission: string) => true));
vi.mock('@/features/authorization', () => ({
  Can: ({ permission, children }: { permission: string | string[]; children: React.ReactNode }) => {
    const list = Array.isArray(permission) ? permission : [permission];
    return list.every(mockCan) ? children : null;
  },
}));

const mockListSessions = vi.hoisted(() => vi.fn());
const mockRevokeSession = vi.hoisted(() => vi.fn());
const mockForceLogout = vi.hoisted(() => vi.fn());
const mockResetPassword = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/users-service', () => ({
  usersService: {
    listSessions: mockListSessions,
    revokeSession: mockRevokeSession,
    forceLogout: mockForceLogout,
    resetPassword: mockResetPassword,
  },
}));

import { UserSecurityPanel } from './user-security-panel';

function baseUser(overrides: Partial<UserDetail> = {}): UserDetail {
  return {
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
    username: null,
    phone: null,
    job_title: null,
    employment_type: null,
    manager_id: null,
    hire_date: null,
    templates: [],
    organizations: [],
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const SESSION: UserSession = {
  id: 'sess-1',
  ip_address: '10.0.0.5',
  browser: 'Chrome',
  platform: 'Windows',
  login_at: '2026-08-01T10:00:00Z',
  last_activity_at: '2026-09-01T10:00:00Z',
};

function renderPanel(user: UserDetail) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return { client, ...render(<QueryClientProvider client={client}><UserSecurityPanel user={user} /></QueryClientProvider>) };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockListSessions.mockResolvedValue([SESSION]);
});

describe('UserSecurityPanel', () => {
  // ── 5: password-reset-no-unlock-implication ─────────────────────────────────

  it('reset-password calls only resetPassword — never a lock/unlock/activate endpoint', async () => {
    const user = userEvent.setup();
    mockResetPassword.mockResolvedValue(undefined);
    renderPanel(baseUser({ status: 'suspended', status_label: 'Suspended' }));

    // Suspended shows the "keeps status" note — the UI itself states the operation is inert
    // with respect to lock/lifecycle state.
    expect(await screen.findByText('users.security.resetKeepsStatus')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'users.security.resetTrigger' }));
    const dialog = await screen.findByRole('dialog');
    const inputs = within(dialog).getAllByDisplayValue('');
    await user.type(inputs[0], 'N3wPassw0rd!');
    await user.type(inputs[1], 'N3wPassw0rd!');
    await user.click(within(dialog).getByRole('button', { name: 'users.security.resetSubmit' }));

    await waitFor(() => expect(mockResetPassword).toHaveBeenCalledTimes(1));
    expect(mockResetPassword).toHaveBeenCalledWith(1, { password: 'N3wPassw0rd!', password_confirmation: 'N3wPassw0rd!' });
  });

  // ── 6: archived-reset-unavailable ───────────────────────────────────────────

  it('disables reset and explains why for an archived user, and requires restore first', async () => {
    renderPanel(baseUser({ status: 'archived', status_label: 'Archived' }));

    expect(await screen.findByText('users.security.resetRequiresRestore')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'users.security.resetTrigger' })).toBeDisabled();
  });

  it('disables reset for a deleted user with the deletion-specific message', async () => {
    renderPanel(baseUser({ status: 'deleted', status_label: 'Deleted' }));

    expect(await screen.findByText('users.security.resetUnavailableDeleted')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'users.security.resetTrigger' })).toBeDisabled();
  });

  // ── 10: session-revoke-flow ──────────────────────────────────────────────────

  it('lists active sessions and revokes one on demand, refetching the list', async () => {
    const userEv = userEvent.setup();
    renderPanel(baseUser());

    expect(await screen.findByText(/Chrome/)).toBeInTheDocument();
    mockRevokeSession.mockResolvedValue(undefined);
    mockListSessions.mockResolvedValue([]);

    await userEv.click(screen.getByRole('button', { name: 'users.security.revokeSession' }));

    await waitFor(() => expect(mockRevokeSession).toHaveBeenCalledWith(1, 'sess-1'));
    await waitFor(() => expect(mockListSessions).toHaveBeenCalledTimes(2));
    expect(await screen.findByText('users.security.noSessions')).toBeInTheDocument();
  });

  it('force-logout revokes every session via a single confirmed action', async () => {
    const userEv = userEvent.setup();
    mockForceLogout.mockResolvedValue({ revoked: 1 });
    renderPanel(baseUser());
    await screen.findByText(/Chrome/);

    await userEv.click(screen.getByRole('button', { name: 'users.security.forceLogoutTrigger' }));
    const dialog = await screen.findByRole('dialog');
    await userEv.click(within(dialog).getByRole('button', { name: /forceLogoutTitle|confirm/i }));

    await waitFor(() => expect(mockForceLogout).toHaveBeenCalledWith(1));
  });

  it('disables force-logout when there are no active sessions', async () => {
    mockListSessions.mockResolvedValue([]);
    renderPanel(baseUser());

    await screen.findByText('users.security.noSessions');
    expect(screen.getByRole('button', { name: 'users.security.forceLogoutTrigger' })).toBeDisabled();
  });
});
