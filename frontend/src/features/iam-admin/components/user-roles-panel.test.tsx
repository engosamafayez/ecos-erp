import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach, beforeAll } from 'vitest';

import type { UserDetail } from '@/features/iam-admin/types/user';
import type { RoleTemplateSummary } from '@/features/iam-admin/types/role-template';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — User → Role Template assignment/revocation.
 * §8: role assignment from the User detail. §23: no client-side re-filtering of the template
 * list beyond what the tenant-scoped server response already returned (§9/foreign-resource
 * not-visible). §16/§12: mutations refresh through the real React Query cache, not an
 * optimistic local splice — so what's shown after a mutation is what the server returns.
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

const mockCan = vi.hoisted(() => vi.fn<(permission: string) => boolean>(() => true));
vi.mock('@/features/authorization', () => ({
  Can: ({ permission, children }: { permission: string | string[]; children: React.ReactNode }) => {
    const list = Array.isArray(permission) ? permission : [permission];
    return list.every(mockCan) ? children : null;
  },
}));

const mockTemplatesList = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/role-templates-service', () => ({
  roleTemplatesService: { list: mockTemplatesList },
}));

const mockUserGet = vi.hoisted(() => vi.fn());
const mockAssignTemplate = vi.hoisted(() => vi.fn());
const mockRevokeTemplate = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/users-service', () => ({
  usersService: { get: mockUserGet, assignTemplate: mockAssignTemplate, revokeTemplate: mockRevokeTemplate },
}));

import { useUserQuery } from '@/features/iam-admin/hooks/use-users';
import { UserRolesPanel } from './user-roles-panel';

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

function template(overrides: Partial<RoleTemplateSummary> = {}): RoleTemplateSummary {
  return {
    key: 'cashier',
    name: 'Cashier',
    description: null,
    category: 'sales',
    status: 'published',
    version: 1,
    is_system: true,
    is_composable: false,
    company_id: null,
    compiled: true,
    ...overrides,
  };
}

function renderPanel(user: UserDetail) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return { client, ...render(<QueryClientProvider client={client}><UserRolesPanel user={user} /></QueryClientProvider>) };
}

// jsdom implements neither API; Radix Select's pointer-based item selection needs them.
// Environment shims only — the component and its logic run exactly as in the browser.
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn(() => false);
  Element.prototype.setPointerCapture = vi.fn();
  Element.prototype.releasePointerCapture = vi.fn();
  Element.prototype.scrollIntoView = vi.fn();
});

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
});

describe('UserRolesPanel', () => {
  // ── 7: role-assignment-invokes-API ──────────────────────────────────────────

  it('assigning a selected template calls the real assignTemplate endpoint with the chosen key', async () => {
    const user = userEvent.setup();
    mockTemplatesList.mockResolvedValue([template({ key: 'cashier', name: 'Cashier' })]);
    mockAssignTemplate.mockResolvedValue(baseUser());
    renderPanel(baseUser());

    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByText(/^Cashier/));
    await user.click(screen.getByRole('button', { name: 'users.roles.assign' }));

    await waitFor(() => expect(mockAssignTemplate).toHaveBeenCalledWith(1, 'cashier', undefined));
  });

  it('only offers published templates the user does not already hold', async () => {
    const user = userEvent.setup();
    mockTemplatesList.mockResolvedValue([
      template({ key: 'cashier', name: 'Cashier', status: 'published' }),
      template({ key: 'draft-role', name: 'Draft Role', status: 'draft' }),
      template({ key: 'sales-representative', name: 'Sales Rep', status: 'published' }),
    ]);
    renderPanel(baseUser({ templates: [{ key: 'sales-representative', name: 'Sales Rep', is_primary: true }] }));

    await user.click(screen.getByRole('combobox'));
    expect(await screen.findByText(/^Cashier/)).toBeInTheDocument();
    expect(screen.queryByText(/^Draft Role/)).not.toBeInTheDocument();
    expect(screen.queryByText(/^Sales Rep/, { selector: '[role="option"]' })).not.toBeInTheDocument();
  });

  // ── 8: revoke-role-permission-respected ─────────────────────────────────────

  it('shows no revoke control when the actor lacks iam.users.revoke-role', async () => {
    mockCan.mockImplementation((permission: string) => permission !== 'iam.users.revoke-role');
    mockTemplatesList.mockResolvedValue([]);
    renderPanel(baseUser({ templates: [{ key: 'cashier', name: 'Cashier', is_primary: true }] }));

    await screen.findByText('Cashier');
    expect(screen.queryByRole('button', { name: 'users.roles.revoke' })).not.toBeInTheDocument();
  });

  it('revoking a template calls the real endpoint with the held template key', async () => {
    const user = userEvent.setup();
    mockTemplatesList.mockResolvedValue([]);
    mockRevokeTemplate.mockResolvedValue(baseUser());
    renderPanel(baseUser({ templates: [{ key: 'cashier', name: 'Cashier', is_primary: true }] }));

    await screen.findByText('Cashier');
    await user.click(screen.getByRole('button', { name: 'users.roles.revoke' }));

    await waitFor(() => expect(mockRevokeTemplate).toHaveBeenCalledWith(1, 'cashier'));
  });

  // ── 9: foreign-resource-not-visible ─────────────────────────────────────────

  it('renders exactly the templates the (already tenant-scoped) service returns, adding none of its own', async () => {
    const user = userEvent.setup();
    mockTemplatesList.mockResolvedValue([template({ key: 'cashier', name: 'Cashier' })]);
    renderPanel(baseUser());

    await user.click(screen.getByRole('combobox'));
    const options = await screen.findAllByRole('option');
    expect(options).toHaveLength(1);
    expect(options[0]).toHaveTextContent('Cashier');
  });

  // ── 16: authorization-refresh-after-mutation ────────────────────────────────

  it('reflects the refetched server state after assignment, not a locally-spliced guess', async () => {
    // UserRolesPanel takes `user` as a prop (no query of its own) — in the real app it always
    // renders alongside a mounted useUserQuery(id) in UserDetailDrawer, which is what actually
    // refetches when useAssignTemplate's onSuccess invalidates the detail key. This harness
    // reproduces that pairing so the invalidation has a live subscriber to refetch, exactly
    // like production, instead of asserting against an isolated component that could never
    // observe it.
    const user = userEvent.setup();
    mockTemplatesList.mockResolvedValue([template({ key: 'cashier', name: 'Cashier' })]);
    mockAssignTemplate.mockResolvedValue(baseUser());
    mockUserGet
      .mockResolvedValueOnce(baseUser())
      .mockResolvedValueOnce(baseUser({ templates: [{ key: 'cashier', name: 'Cashier', is_primary: true }] }));

    function Harness() {
      const query = useUserQuery(1);
      return query.data ? <UserRolesPanel user={query.data} /> : null;
    }

    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <Harness />
      </QueryClientProvider>,
    );

    await waitFor(() => expect(screen.getByText('users.roles.none')).toBeInTheDocument());

    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByText(/^Cashier/));
    await user.click(screen.getByRole('button', { name: 'users.roles.assign' }));

    await waitFor(() => expect(mockAssignTemplate).toHaveBeenCalled());
    // Invalidation caused a real refetch (usersService.get called a 2nd time) whose result is
    // what the panel now shows — not a client-side splice of the mutation's own response.
    await waitFor(() => expect(mockUserGet).toHaveBeenCalledTimes(2));
    expect(await screen.findByText(/^Cashier/)).toBeInTheDocument();
  });
});
