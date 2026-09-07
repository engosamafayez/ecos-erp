/// <reference types="@testing-library/jest-dom/vitest" />
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { LanguageContext } from '@/providers/language-context';
import type { PermissionCatalog, RoleDetail, RoleListResult, RoleSummary } from '@/features/iam-admin/types/role';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 → superseded by
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001 §11/§14 (the approved writable contract)
 * → test alignment done under
 * TASK-ECOS-IAM-FINAL-SOURCE-CAPTURE-INTEGRATION-DEV-CLOSURE-002 §7.
 *
 * Roles are no longer read-only. ADR-039/040 Decision 2 ("roles are authored ONLY through
 * Role Templates") is STILL fully honoured — every write below goes through
 * `RoleAuthoringService`, which authors the role's backing Role Template and calls the ONE
 * canonical `RoleTemplateCompiler`; nothing here writes `role_permissions` directly (see
 * roles-service.ts / role-detail-drawer.tsx's own docblocks). What changed is that Roles
 * now exposes Create / Edit / Clone / Archive / Restore / Delete and an EDITABLE grouped
 * permission matrix, with a system role (or one backed by an immutable system template)
 * staying View + Clone only, per §15.
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

// Matches the established convention (template-detail-drawer.test.tsx,
// user-roles-panel.test.tsx, users-tab.test.tsx): `Can` defaults to granting everything,
// so a test narrows it only when it specifically needs to prove a permission boundary.
const mockCan = vi.hoisted(() => vi.fn<(permission: string) => boolean>(() => true));
vi.mock('@/features/authorization', () => ({
  Can: ({ permission, children }: { permission: string | string[]; children: React.ReactNode }) => {
    const list = Array.isArray(permission) ? permission : [permission];
    return list.every(mockCan) ? children : null;
  },
}));

const mockRolesList = vi.hoisted(() => vi.fn());
const mockRoleGet = vi.hoisted(() => vi.fn());
const mockRoleCreate = vi.hoisted(() => vi.fn());
const mockRoleUpdate = vi.hoisted(() => vi.fn());
const mockRoleUpdatePermissions = vi.hoisted(() => vi.fn());
const mockRoleClone = vi.hoisted(() => vi.fn());
const mockRoleArchive = vi.hoisted(() => vi.fn());
const mockRoleRestore = vi.hoisted(() => vi.fn());
const mockRoleRemove = vi.hoisted(() => vi.fn());
const mockPermissionCatalog = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/roles-service', () => ({
  rolesService: {
    list: mockRolesList,
    get: mockRoleGet,
    create: mockRoleCreate,
    update: mockRoleUpdate,
    updatePermissions: mockRoleUpdatePermissions,
    clone: mockRoleClone,
    archive: mockRoleArchive,
    restore: mockRoleRestore,
    remove: mockRoleRemove,
    // Set up by RoleNavigationSettings (role-detail-drawer.tsx's Navigation tab) whenever a
    // non-system role's detail is rendered — not exercised by name below, but a real
    // function here rather than undefined keeps the mutation hook safe to instantiate.
    updateNavigation: vi.fn(),
  },
  permissionsService: { catalog: mockPermissionCatalog },
}));

import { RolesPermissionsTab } from './roles-permissions-tab';

function role(overrides: Partial<RoleSummary> = {}): RoleSummary {
  return {
    id: 'role-1',
    slug: 'cashier',
    name: 'Cashier',
    name_ar: 'Cashier',
    description: null,
    description_ar: null,
    is_system: true,
    is_business_catalog: false,
    archived: false,
    archived_at: null,
    archived_reason: null,
    user_count: 3,
    editable: false,
    scope_expectation: [],
    template: { key: 'cashier', name: 'Cashier', is_system: true, status: 'published' },
    // User-review remediation (Batch 02, item I): `navigation_overrides` is a new required
    // field the role-detail Navigation tab reads — an empty map reproduces "inherit
    // everything", the correct default for a role this fixture never configured otherwise.
    navigation_overrides: {},
    ...overrides,
  };
}

function roleDetail(overrides: Partial<RoleDetail> = {}): RoleDetail {
  return {
    ...role(),
    permissions: ['pos.terminal.view', 'pos.terminal.operate'],
    permission_details: [],
    definition: null,
    assigned_users: [],
    ...overrides,
  };
}

function listResult(data: RoleSummary[]): RoleListResult {
  return {
    data,
    meta: { total: data.length, business_catalog: [], categories: [] },
  };
}

const CATALOG: PermissionCatalog = {
  groups: [
    {
      module: 'pos',
      // eslint-disable-next-line ecos-i18n/no-arabic-literals -- mock API fixture value (pre-existing, predates this task), never rendered through i18n
      label_ar: 'نقاط البيع',
      label_en: 'Point of Sale',
      sort: 1,
      count: 2,
      sensitive_count: 0,
      permissions: [
        {
          name: 'pos.terminal.view',
          module: 'pos',
          // eslint-disable-next-line ecos-i18n/no-arabic-literals -- mock API fixture value (pre-existing, predates this task), never rendered through i18n
          module_label_ar: 'نقاط البيع',
          module_label_en: 'Point of Sale',
          resource: 'pos.terminal',
          // User-review remediation (Batch 02, item H): new required fields — see
          // template-detail-drawer.test.tsx's identical fixture for the full rationale.
          // eslint-disable-next-line ecos-i18n/no-arabic-literals -- mock API fixture value (resource business name), never rendered through i18n
          resource_label_ar: 'الطرفية',
          resource_label_en: 'Terminal',
          action: 'view',
          // eslint-disable-next-line ecos-i18n/no-arabic-literals -- mock API fixture value (pre-existing, predates this task), never rendered through i18n
          label_ar: 'عرض نقطة البيع',
          label_en: 'View POS terminal',
          description_ar: '',
          sensitivity: 'normal',
        },
        {
          name: 'pos.terminal.operate',
          module: 'pos',
          // eslint-disable-next-line ecos-i18n/no-arabic-literals -- mock API fixture value (pre-existing, predates this task), never rendered through i18n
          module_label_ar: 'نقاط البيع',
          module_label_en: 'Point of Sale',
          resource: 'pos.terminal',
          // eslint-disable-next-line ecos-i18n/no-arabic-literals -- mock API fixture value (resource business name), never rendered through i18n
          resource_label_ar: 'الطرفية',
          resource_label_en: 'Terminal',
          action: 'operate',
          // eslint-disable-next-line ecos-i18n/no-arabic-literals -- mock API fixture value (pre-existing, predates this task), never rendered through i18n
          label_ar: 'تشغيل نقطة البيع',
          label_en: 'Operate POS terminal',
          description_ar: '',
          sensitivity: 'normal',
        },
      ],
    },
  ],
  total: 2,
  sensitivity_levels: ['normal', 'elevated', 'critical'],
};

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

async function openRoleDetail(user: ReturnType<typeof userEvent.setup>, name = 'Cashier') {
  const nameButtons = await screen.findAllByText(name);
  await user.click(nameButtons[0]);
  return screen.findByRole('dialog');
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockPermissionCatalog.mockResolvedValue(CATALOG);
});

describe('RolesPermissionsTab', () => {
  // ── role-list-detail ─────────────────────────────────────────────────────────

  it('lists roles and opens a detail drawer with the compiled Permission Matrix on click', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role()]));
    mockRoleGet.mockResolvedValue(roleDetail());
    renderTab();

    await openRoleDetail(user);

    // Permissions is the drawer's default tab — the matrix's own two checkboxes are the
    // proof the compiled grant set actually reached the UI (labels come from the mocked
    // catalog above, not the raw canonical keys, matching §13's redesign).
    // eslint-disable-next-line ecos-i18n/no-arabic-literals -- asserting on the mock fixture value above (pre-existing, predates this task), not a hardcoded UI string
    expect(await screen.findByText('عرض نقطة البيع')).toBeInTheDocument();
    // eslint-disable-next-line ecos-i18n/no-arabic-literals -- asserting on the mock fixture value above (pre-existing, predates this task), not a hardcoded UI string
    expect(screen.getByText('تشغيل نقطة البيع')).toBeInTheDocument();
  });

  // ── create-role (§11: was "no create/edit affordance anywhere" — now writable) ─

  it('creates a role through the real create endpoint, with the chosen permissions', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([]));
    mockRoleCreate.mockResolvedValue(role({ id: 'role-new', name: 'Night Shift Lead' }));
    renderTab();

    await user.click(await screen.findByRole('button', { name: 'roles.create.trigger' }));
    const drawer = await screen.findByRole('dialog');
    const nameInput = within(drawer).getAllByRole('textbox')[0];
    await user.type(nameInput, 'Night Shift Lead');

    const checkbox = await within(drawer).findByRole('checkbox', { name: /عرض نقطة البيع/ });
    await user.click(checkbox);
    await user.click(within(drawer).getByRole('button', { name: 'roles.create.submit' }));

    await waitFor(() => expect(mockRoleCreate).toHaveBeenCalled());
    const payload = mockRoleCreate.mock.calls[0][0];
    expect(payload.name).toBe('Night Shift Lead');
    expect(payload.permissions).toContain('pos.terminal.view');
  });

  // ── system-role-view-and-clone-only (§15) ───────────────────────────────────

  it('a system role stays View + Clone only: matrix disabled, no Save, Clone still offered', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role()]));
    mockRoleGet.mockResolvedValue(roleDetail({ is_system: true, editable: false }));
    renderTab();
    const dialog = await openRoleDetail(user);

    // Permissions tab (default): every checkbox is disabled, and there is no Save action.
    const checkboxes = await within(dialog).findAllByRole('checkbox');
    expect(checkboxes.length).toBeGreaterThan(0);
    for (const box of checkboxes) expect(box).toBeDisabled();
    expect(within(dialog).queryByRole('button', { name: 'roles.detail.savePermissions' })).not.toBeInTheDocument();

    // Overview tab: metadata is disabled too, but Clone is still offered — the sanctioned
    // path to an editable copy.
    await user.click(within(dialog).getByText('roles.detail.tabs.overview'));
    expect(within(dialog).getByText('roles.detail.systemImmutable')).toBeInTheDocument();
    const fieldset = document.querySelector('fieldset');
    expect(fieldset).not.toBeNull();
    expect(fieldset?.disabled).toBe(true);
    expect(within(dialog).queryByRole('button', { name: 'common.save' })).not.toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: 'roles.detail.cloneTrigger' })).toBeInTheDocument();
  });

  // ── editable-role-permission-matrix (§14) ───────────────────────────────────

  it('an editable role can have its permission matrix toggled and saved via the real endpoint', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role({ is_system: false, editable: true, is_business_catalog: true })]));
    mockRoleGet.mockResolvedValue(
      roleDetail({ is_system: false, editable: true, is_business_catalog: true, permissions: ['pos.terminal.view'] }),
    );
    mockRoleUpdatePermissions.mockResolvedValue(roleDetail({ is_system: false, editable: true }));
    renderTab();
    const dialog = await openRoleDetail(user);

    const operateCheckbox = await within(dialog).findByRole('checkbox', { name: /تشغيل نقطة البيع/ });
    expect(operateCheckbox).not.toBeDisabled();
    await user.click(operateCheckbox);

    await user.click(within(dialog).getByRole('button', { name: 'roles.detail.savePermissions' }));

    await waitFor(() => expect(mockRoleUpdatePermissions).toHaveBeenCalled());
    expect(mockRoleUpdatePermissions.mock.calls[0][0]).toBe('role-1');
    expect(mockRoleUpdatePermissions.mock.calls[0][1]).toEqual(
      expect.arrayContaining(['pos.terminal.view', 'pos.terminal.operate']),
    );
  });

  it('editing an editable role\'s metadata calls the real update endpoint', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role({ is_system: false, editable: true })]));
    mockRoleGet.mockResolvedValue(roleDetail({ is_system: false, editable: true }));
    mockRoleUpdate.mockResolvedValue(role({ is_system: false, editable: true, name: 'Cashier v2' }));
    renderTab();
    const dialog = await openRoleDetail(user);

    await user.click(within(dialog).getByText('roles.detail.tabs.overview'));
    const nameInput = within(dialog).getAllByRole('textbox')[0];
    await user.clear(nameInput);
    await user.type(nameInput, 'Cashier v2');
    await user.click(within(dialog).getByRole('button', { name: 'common.save' }));

    await waitFor(() => expect(mockRoleUpdate).toHaveBeenCalled());
    expect(mockRoleUpdate.mock.calls[0][0]).toBe('role-1');
    expect(mockRoleUpdate.mock.calls[0][1].name).toBe('Cashier v2');
  });

  // ── clone-is-the-sanctioned-path-for-a-protected-role (§11/§15) ─────────────

  it('cloning a role (system or otherwise) calls the real clone endpoint and never mutates the source', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role()]));
    mockRoleGet.mockResolvedValue(roleDetail());
    mockRoleClone.mockResolvedValue(role({ id: 'role-clone', name: 'Cashier (Copy)', is_system: false, editable: true }));
    renderTab();

    const dialog = await openRoleDetail(user);
    await user.click(within(dialog).getByText('roles.detail.tabs.overview'));
    await user.click(within(dialog).getByRole('button', { name: 'roles.detail.cloneTrigger' }));

    const cloneDialog = await screen.findByRole('dialog', { name: /roles\.clone\.title/ });
    const cloneNameInput = within(cloneDialog).getByRole('textbox');
    await user.clear(cloneNameInput);
    await user.type(cloneNameInput, 'Cashier (Copy)');
    await user.click(within(cloneDialog).getByRole('button', { name: 'roles.clone.submit' }));

    await waitFor(() => expect(mockRoleClone).toHaveBeenCalledWith('role-1', 'Cashier (Copy)'));
    expect(mockRoleUpdate).not.toHaveBeenCalled();
    expect(mockRoleUpdatePermissions).not.toHaveBeenCalled();
  });

  // ── archive-not-delete-when-held (§11/§12/§19) ──────────────────────────────

  it('offers Archive (never Delete) for an editable role still held by users', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role({ is_system: false, editable: true, user_count: 2 })]));
    mockRoleGet.mockResolvedValue(roleDetail({ is_system: false, editable: true, user_count: 2 }));
    renderTab();
    const dialog = await openRoleDetail(user);

    await user.click(within(dialog).getByText('roles.detail.tabs.overview'));
    expect(within(dialog).getByRole('button', { name: 'roles.detail.archiveTrigger' })).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: 'roles.detail.deleteTrigger' })).not.toBeInTheDocument();
  });

  it('offers Delete only when the backend reports zero holders, and calls the real endpoint', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role({ is_system: false, editable: true, user_count: 0 })]));
    mockRoleGet.mockResolvedValue(roleDetail({ is_system: false, editable: true, user_count: 0 }));
    mockRoleRemove.mockResolvedValue(undefined);
    renderTab();
    const dialog = await openRoleDetail(user);

    await user.click(within(dialog).getByText('roles.detail.tabs.overview'));
    await user.click(within(dialog).getByRole('button', { name: 'roles.detail.deleteTrigger' }));
    const confirmDialog = await screen.findByRole('dialog', { name: /roles\.detail\.deleteConfirmTitle/ });
    await user.click(within(confirmDialog).getByRole('button', { name: 'common.confirm' }));

    await waitFor(() => expect(mockRoleRemove).toHaveBeenCalledWith('role-1'));
  });

  it('archiving calls the real archive endpoint with the entered reason, never destroy', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role({ is_system: false, editable: true, user_count: 2 })]));
    mockRoleGet.mockResolvedValue(roleDetail({ is_system: false, editable: true, user_count: 2 }));
    mockRoleArchive.mockResolvedValue(role({ is_system: false, editable: true, archived: true }));
    renderTab();
    const dialog = await openRoleDetail(user);

    await user.click(within(dialog).getByText('roles.detail.tabs.overview'));
    await user.click(within(dialog).getByRole('button', { name: 'roles.detail.archiveTrigger' }));
    const confirmDialog = await screen.findByRole('dialog', { name: /roles\.detail\.archiveConfirmTitle/ });
    await user.type(within(confirmDialog).getByPlaceholderText('roles.detail.archiveReasonPlaceholder'), 'Superseded');
    await user.click(within(confirmDialog).getByRole('button', { name: 'common.confirm' }));

    await waitFor(() => expect(mockRoleArchive).toHaveBeenCalledWith('role-1', 'Superseded'));
    expect(mockRoleRemove).not.toHaveBeenCalled();
  });

  // ── show-archived-toggle ─────────────────────────────────────────────────────

  it('re-queries with include_archived when "Show archived roles" is toggled', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role()]));
    renderTab();

    await waitFor(() => expect(mockRolesList).toHaveBeenCalledWith(false));
    await user.click(screen.getByRole('checkbox', { name: 'roles.showArchived' }));

    await waitFor(() => expect(mockRolesList).toHaveBeenCalledWith(true));
  });

  // ── permission-catalog-still-read-only-reference (§13) ──────────────────────

  it('the Permission Directory tab shows business-labeled permissions with no assignment control', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([]));
    renderTab();

    await user.click(await screen.findByText('roles.tabs.permissions'));

    // eslint-disable-next-line ecos-i18n/no-arabic-literals -- asserting on the mock fixture value above (pre-existing, predates this task), not a hardcoded UI string
    expect(await screen.findByText('عرض نقطة البيع')).toBeInTheDocument();
    // Raw canonical key still visible as SECONDARY technical detail (§13), not the leading label.
    expect(screen.getByText('pos.terminal.view')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });

  // ── 404 / 500 — unchanged error-handling class, adapted to the new response shape ──

  it('shows an error state (not a blank/leaked detail) when the role detail request fails', async () => {
    const user = userEvent.setup();
    mockRolesList.mockResolvedValue(listResult([role()]));
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
