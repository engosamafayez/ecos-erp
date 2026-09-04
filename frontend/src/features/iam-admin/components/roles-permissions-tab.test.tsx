import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { LanguageContext } from '@/providers/language-context';
import type { RoleDetail, RoleSummary } from '@/features/iam-admin/types/role';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — Roles & Permissions workspace.
 * §14: Roles is read-only by architecture (roles compile from Role Templates only,
 * ADR-039/040 Decision 2) — "create custom role" / "add-remove permissions" from the task's
 * literal §10 wording is satisfied via the Role Templates workspace instead, never here.
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
    t: (sel: unknown, opts?: { count?: number; name?: string }) => {
      if (typeof sel !== 'function') return String(sel);
      const path = String((sel as (p: unknown) => unknown)(pathProxy('')));
      return opts?.name ? `${path}:${opts.name}` : path;
    },
  }),
}));

const mockRolesList = vi.hoisted(() => vi.fn());
const mockRoleGet = vi.hoisted(() => vi.fn());
const mockPermissionCatalog = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/roles-service', () => ({
  rolesService: { list: mockRolesList, get: mockRoleGet },
  permissionsService: { catalog: mockPermissionCatalog },
}));

import { RolesPermissionsTab } from './roles-permissions-tab';

function role(overrides: Partial<RoleSummary> = {}): RoleSummary {
  return {
    id: 'role-1',
    slug: 'cashier',
    name: 'Cashier',
    is_system: true,
    user_count: 3,
    template: { key: 'cashier', name: 'Cashier', is_system: true },
    ...overrides,
  };
}

function roleDetail(overrides: Partial<RoleDetail> = {}): RoleDetail {
  return { ...role(), permissions: ['pos.terminal.view', 'pos.terminal.operate'], ...overrides };
}

const LANGUAGE_VALUE = { language: 'en' as const, dir: 'ltr' as const, setLanguage: vi.fn() };

function renderTab(onOpenTemplate = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return {
    onOpenTemplate,
    ...render(
      <LanguageContext.Provider value={LANGUAGE_VALUE}>
        <QueryClientProvider client={client}>
          <RolesPermissionsTab onOpenTemplate={onOpenTemplate} />
        </QueryClientProvider>
      </LanguageContext.Provider>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockPermissionCatalog.mockResolvedValue({ groups: [], total: 0 });
});

describe('RolesPermissionsTab', () => {
  // ── 11: role-list-detail ─────────────────────────────────────────────────────

  it('lists roles and opens a read-only detail with the compiled permissions on click', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue([role()]);
    mockRoleGet.mockResolvedValue(roleDetail());
    renderTab();

    const nameButtons = await screen.findAllByText('Cashier');
    await user.click(nameButtons[0]);

    expect(await screen.findByText('pos.terminal.view')).toBeInTheDocument();
    expect(screen.getByText('pos.terminal.operate')).toBeInTheDocument();
  });

  // ── 12: custom-role-create-edit (via templates) ─────────────────────────────

  it('exposes no create/edit affordance for roles directly — only a pointer into Role Templates', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue([role({ template: { key: 'cashier', name: 'Cashier', is_system: true } })]);
    mockRoleGet.mockResolvedValue(roleDetail());
    const { onOpenTemplate } = renderTab();

    expect(screen.queryByRole('button', { name: /new role|create role/i })).not.toBeInTheDocument();

    const nameButtons = await screen.findAllByText('Cashier');
    await user.click(nameButtons[0]);

    const managedByLink = await screen.findByText(/roles\.detail\.managedByTemplate/);
    await user.click(managedByLink);
    expect(onOpenTemplate).toHaveBeenCalledWith('cashier');
  });

  // ── 13: permission-assignment (via template definition editing, not here) ──

  it('the permission catalog is read-only reference material — no assignment control anywhere', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue([]);
    mockPermissionCatalog.mockResolvedValue({
      groups: [{ module: 'pos', permissions: [{ name: 'pos.terminal.view', resource: 'terminal', action: 'view', description: null }] }],
      total: 1,
    });
    renderTab();

    await user.click(await screen.findByText('roles.tabs.permissions'));

    expect(await screen.findByText('pos.terminal.view')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /assign|add/i })).not.toBeInTheDocument();
  });

  // ── 14: protected-system-role-restrictions ──────────────────────────────────

  it('a system role is exactly as read-only as a custom one — no differential edit affordance either way', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue([
      role({ id: 'sys', slug: 'cashier', name: 'System Role', is_system: true, template: null }),
    ]);
    mockRoleGet.mockResolvedValue(roleDetail({ is_system: true, template: null }));
    renderTab();

    const nameButtons = await screen.findAllByText('System Role');
    await user.click(nameButtons[0]);

    await screen.findAllByText('roles.systemBadge');
    // No input/button that would mutate the role's own permission set exists in the drawer.
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /save|edit|remove/i })).not.toBeInTheDocument();
  });

  // ── 15: no-generic-permission-token-creation-UI ─────────────────────────────

  it('never offers a way to type/create an arbitrary permission token', async () => {
    mockRolesList.mockResolvedValue([]);
    mockPermissionCatalog.mockResolvedValue({
      groups: [{ module: 'pos', permissions: [{ name: 'pos.terminal.view', resource: 'terminal', action: 'view', description: null }] }],
      total: 1,
    });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText('roles.tabs.permissions'));
    await screen.findByText('pos.terminal.view');

    // The only text input on this tab is the read-only search/filter box — never a
    // free-text "new permission name" field.
    const textboxes = screen.queryAllByRole('textbox');
    for (const box of textboxes) {
      expect(box).toHaveAttribute('placeholder', expect.stringMatching(/search/i));
    }
  });

  // ── 31: 404-no-foreign-leak ──────────────────────────────────────────────────

  it('shows an error state (not a blank/leaked detail) when the role detail request fails', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue([role()]);
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server-response text, not translated client-side
    mockRoleGet.mockRejectedValue({ isAxiosError: true, response: { status: 404, data: { message: 'Not found.' } } });
    renderTab();

    const nameButtons = await screen.findAllByText('Cashier');
    await user.click(nameButtons[0]);

    expect(await screen.findByText('error.title')).toBeInTheDocument();
    expect(screen.queryByText('pos.terminal.view')).not.toBeInTheDocument();
  });

  it('shows an error state on the roles list itself when the list request fails, not an empty table', async () => {
    mockRolesList.mockRejectedValue({ isAxiosError: true, response: { status: 500, data: {} } });
    renderTab();

    expect((await screen.findAllByText('error.title')).length).toBeGreaterThan(0);
    expect(screen.queryByText('roles.empty')).not.toBeInTheDocument();
  });
});
