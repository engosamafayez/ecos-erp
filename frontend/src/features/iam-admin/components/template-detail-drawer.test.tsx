import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import type { RoleTemplateDetail, TemplateVersionEntry } from '@/features/iam-admin/types/role-template';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — Role Template detail: system-immutability,
 * custom editability/versioning, archive-not-delete gating, version history (§17-19, §26).
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

const mockCan = vi.hoisted(() => vi.fn(() => true));
vi.mock('@/features/authorization', () => ({
  Can: ({ permission, children }: { permission: string | string[]; children: React.ReactNode }) => {
    const list = Array.isArray(permission) ? permission : [permission];
    return list.every(mockCan) ? children : null;
  },
}));

const mockGet = vi.hoisted(() => vi.fn());
const mockUpdate = vi.hoisted(() => vi.fn());
const mockArchive = vi.hoisted(() => vi.fn());
const mockDestroy = vi.hoisted(() => vi.fn());
const mockVersions = vi.hoisted(() => vi.fn());
const mockImpactPreview = vi.hoisted(() => vi.fn());
const mockApply = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/role-templates-service', () => ({
  roleTemplatesService: {
    get: mockGet,
    update: mockUpdate,
    archive: mockArchive,
    destroy: mockDestroy,
    versions: mockVersions,
    impactPreview: mockImpactPreview,
    apply: mockApply,
  },
}));

// TASK-ECOS-IAM-FINAL-SOURCE-CAPTURE-INTEGRATION-DEV-CLOSURE-002 (§16 of the prior IAM
// remediation): the Definition tab now embeds the shared, grouped, editable
// PermissionMatrix (§16 "use the SAME component as Roles") in place of the old flat
// combobox editor — a genuinely new dependency on `GET /iam/permissions`, mocked here so
// the drawer never makes a real network call.
const mockPermissionCatalog = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/roles-service', () => ({
  rolesService: { list: vi.fn(), get: vi.fn() },
  permissionsService: { catalog: mockPermissionCatalog },
}));

import { TemplateDetailDrawer } from './template-detail-drawer';

function templateDetail(overrides: Partial<RoleTemplateDetail> = {}): RoleTemplateDetail {
  return {
    key: 'cashier',
    name: 'Cashier',
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock API fixture value (arbitrary role-template description content), never rendered through i18n
    description: 'POS operator',
    category: 'sales',
    status: 'published',
    version: 2,
    is_system: true,
    is_composable: false,
    company_id: null,
    compiled: true,
    definition: { permissions: ['pos.terminal.view', 'pos.terminal.operate'] },
    assignment_count: 3,
    published_at: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function renderDrawer() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const onOpenChange = vi.fn();
  return {
    onOpenChange,
    ...render(
      <QueryClientProvider client={client}>
        <TemplateDetailDrawer templateKey="cashier" open onOpenChange={onOpenChange} />
      </QueryClientProvider>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockVersions.mockResolvedValue([]);
  mockImpactPreview.mockResolvedValue({
    template_version: 1,
    compiled: true,
    affected_holders: 0,
    permission_additions: [],
    permission_removals: [],
  });
  mockPermissionCatalog.mockResolvedValue({
    groups: [
      {
        module: 'pos',
        label_ar: 'نقاط البيع',
        label_en: 'Point of Sale',
        sort: 1,
        count: 2,
        sensitive_count: 0,
        permissions: [
          {
            name: 'pos.terminal.view',
            module: 'pos',
            module_label_ar: 'نقاط البيع',
            module_label_en: 'Point of Sale',
            resource: 'pos.terminal',
            action: 'view',
            label_ar: 'عرض نقطة البيع',
            label_en: 'View POS terminal',
            description_ar: '',
            sensitivity: 'normal',
          },
          {
            name: 'pos.terminal.operate',
            module: 'pos',
            module_label_ar: 'نقاط البيع',
            module_label_en: 'Point of Sale',
            resource: 'pos.terminal',
            action: 'operate',
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
  });
});

describe('TemplateDetailDrawer', () => {
  // ── 17: system-template-immutable ───────────────────────────────────────────

  it('renders a system template as immutable: fields disabled, no save/archive/delete controls', async () => {
    mockGet.mockResolvedValue(templateDetail({ is_system: true }));
    renderDrawer();

    expect(await screen.findByText('roleTemplates.detail.systemImmutable')).toBeInTheDocument();
    // The fieldset around name/description/permissions carries disabled={!editable}: jsdom
    // does not implement the browser's own disabled-cascades-to-descendants behavior (verified
    // directly — a bare <fieldset disabled><input /></fieldset> still reports input.disabled
    // === false under jsdom), so this checks the fieldset's own disabled state, which IS
    // observable, rather than each descendant control's (which isn't, in this environment).
    // EntityDrawer's Sheet content is portaled to document.body, outside RTL's container div.
    const fieldset = document.querySelector('fieldset');
    expect(fieldset).not.toBeNull();
    expect(fieldset?.disabled).toBe(true);
    // Save never renders at all for a system template (the whole edit-action block is gated
    // on `editable`) — this is the jsdom-independent, authoritative signal that nothing here
    // can be persisted.
    expect(screen.queryByRole('button', { name: 'common.save' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'roleTemplates.detail.archiveTrigger' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'roleTemplates.detail.deleteTrigger' })).not.toBeInTheDocument();

    // §16 — the shared, grouped, editable Permission Matrix cascades its own read-only
    // state from the same `editable` flag: a system template's grants render as real
    // checkboxes (so an admin can still SEE the compiled definition clearly), but every
    // one of them is disabled, exactly like the fieldset above.
    const checkboxes = await screen.findAllByRole('checkbox');
    expect(checkboxes.length).toBeGreaterThan(0);
    for (const box of checkboxes) {
      expect(box).toBeDisabled();
    }
  });

  // ── 18: custom-template-editable-versioned ──────────────────────────────────

  it('renders a custom template as editable, and Save calls the real update endpoint', async () => {
    const user = userEvent.setup();
    mockGet.mockResolvedValue(templateDetail({ is_system: false, company_id: 'company-1' }));
    mockUpdate.mockResolvedValue(templateDetail({ is_system: false }));
    renderDrawer();

    await screen.findByText('roleTemplates.detail.tabs.definition');
    expect(screen.queryByText('roleTemplates.detail.systemImmutable')).not.toBeInTheDocument();

    const nameInput = screen.getAllByRole('textbox')[0];
    expect(nameInput).not.toBeDisabled();
    await user.clear(nameInput);
    await user.type(nameInput, 'Cashier v2');
    await user.click(screen.getByRole('button', { name: 'common.save' }));

    await waitFor(() => expect(mockUpdate).toHaveBeenCalled());
    expect(mockUpdate.mock.calls[0][0]).toBe('cashier');
    expect(mockUpdate.mock.calls[0][1].name).toBe('Cashier v2');

    // §16 — the same shared Permission Matrix is fully interactive for a custom template:
    // unlike the system-template case, none of its checkboxes are disabled.
    const checkboxes = await screen.findAllByRole('checkbox');
    expect(checkboxes.length).toBeGreaterThan(0);
    for (const box of checkboxes) {
      expect(box).not.toBeDisabled();
    }
  });

  it('never mutates the definition on save alone — applying it is a separate, explicit action', async () => {
    const user = userEvent.setup();
    mockGet.mockResolvedValue(templateDetail({ is_system: false }));
    mockUpdate.mockResolvedValue(templateDetail({ is_system: false }));
    renderDrawer();

    await screen.findByText('roleTemplates.detail.tabs.definition');
    await user.click(screen.getByRole('button', { name: 'common.save' }));

    await waitFor(() => expect(mockUpdate).toHaveBeenCalled());
    expect(mockApply).not.toHaveBeenCalled();
  });

  // ── 19: used-template-archive-not-delete ────────────────────────────────────

  it('offers only Archive (never Delete) for a custom template with existing assignments', async () => {
    mockGet.mockResolvedValue(templateDetail({ is_system: false, assignment_count: 5 }));
    renderDrawer();

    expect(await screen.findByRole('button', { name: 'roleTemplates.detail.archiveTrigger' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'roleTemplates.detail.deleteTrigger' })).not.toBeInTheDocument();
  });

  it('offers Delete only when the backend reports zero current assignments', async () => {
    mockGet.mockResolvedValue(templateDetail({ is_system: false, assignment_count: 0 }));
    renderDrawer();

    expect(await screen.findByRole('button', { name: 'roleTemplates.detail.deleteTrigger' })).toBeInTheDocument();
  });

  it('archiving a used template calls the real archive endpoint, not destroy', async () => {
    const user = userEvent.setup();
    mockGet.mockResolvedValue(templateDetail({ is_system: false, assignment_count: 5 }));
    mockArchive.mockResolvedValue(templateDetail({ is_system: false, status: 'archived' }));
    renderDrawer();

    await user.click(await screen.findByRole('button', { name: 'roleTemplates.detail.archiveTrigger' }));
    const dialog = await screen.findByRole('dialog');
    // ConfirmDialog falls back to common.confirm when no explicit confirmLabel is passed —
    // the archive call site passes none.
    await user.click(within(dialog).getByRole('button', { name: 'common.confirm' }));

    await waitFor(() => expect(mockArchive).toHaveBeenCalledWith('cashier'));
    expect(mockDestroy).not.toHaveBeenCalled();
  });

  // ── 26: previous-version-inspectable ────────────────────────────────────────

  it('lists prior versions from the real versions endpoint under the Version history tab', async () => {
    const user = userEvent.setup();
    const versions: TemplateVersionEntry[] = [
      { version: 2, status: 'published', change_note: 'Reconciled Group C tokens', created_by: 7, created_at: '2026-09-01T00:00:00Z' },
      { version: 1, status: 'superseded', change_note: null, created_by: 7, created_at: '2026-08-01T00:00:00Z' },
    ];
    mockGet.mockResolvedValue(templateDetail({ is_system: false, version: 2 }));
    mockVersions.mockResolvedValue(versions);
    renderDrawer();

    await user.click(await screen.findByText('roleTemplates.detail.tabs.versions'));

    const versionsPanel = await screen.findByRole('tabpanel', { name: 'roleTemplates.detail.tabs.versions' });
    expect(within(versionsPanel).getByText('v2')).toBeInTheDocument();
    expect(within(versionsPanel).getByText('v1')).toBeInTheDocument();
    expect(within(versionsPanel).getByText('Reconciled Group C tokens')).toBeInTheDocument();
  });
});
