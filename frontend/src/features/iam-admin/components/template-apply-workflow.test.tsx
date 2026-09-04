import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import type { RoleTemplateDetail } from '@/features/iam-admin/types/role-template';

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — D12 template-wide apply workflow (§15/§20-26,
 * §34). CTO ruling: every holder of a template shares ONE compiled Role — apply is necessarily
 * template-wide. This dialog must show the affected-holder count prominently, require explicit
 * confirmation, and must never expose a per-user "selected users" apply affordance, because no
 * such backend operation exists.
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

const mockImpactPreview = vi.hoisted(() => vi.fn());
const mockApply = vi.hoisted(() => vi.fn());
vi.mock('@/features/iam-admin/services/role-templates-service', () => ({
  roleTemplatesService: { impactPreview: mockImpactPreview, apply: mockApply },
}));

import { TemplateApplyWorkflow } from './template-apply-workflow';

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
    assignment_count: 7,
    published_at: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function renderWorkflow(template = templateDetail()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const onOpenChange = vi.fn();
  return {
    client,
    onOpenChange,
    ...render(
      <QueryClientProvider client={client}>
        <TemplateApplyWorkflow template={template} open onOpenChange={onOpenChange} />
      </QueryClientProvider>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('TemplateApplyWorkflow', () => {
  // ── 20: impact-preview-before-apply ─────────────────────────────────────────

  it('fetches the impact preview as soon as the dialog opens, before any apply call', async () => {
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: [],
      permission_removals: [],
    });
    renderWorkflow();

    await waitFor(() => expect(mockImpactPreview).toHaveBeenCalledWith('cashier'));
    expect(mockApply).not.toHaveBeenCalled();
    expect(await screen.findByText('roleTemplates.apply.currentVersion')).toBeInTheDocument();
  });

  // ── 21: additions-removals-rendered ─────────────────────────────────────────

  it('renders the permission additions and removals from the real preview response', async () => {
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: ['pos.terminal.operate'],
      permission_removals: ['pos.sessions.operate'],
    });
    renderWorkflow();

    expect(await screen.findByText('+pos.terminal.operate')).toBeInTheDocument();
    expect(screen.getByText('−pos.sessions.operate')).toBeInTheDocument();
  });

  it('renders "none" for an empty additions or removals list rather than an empty area', async () => {
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: [],
      permission_removals: [],
    });
    renderWorkflow();

    await screen.findByText('roleTemplates.apply.currentVersion');
    expect(screen.getAllByText('roleTemplates.apply.none')).toHaveLength(2);
  });

  // ── 22: affected-holder-count-rendered ──────────────────────────────────────

  it('renders the affected-holder count prominently, both in the scope warning and the stat grid', async () => {
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 12,
      permission_additions: [],
      permission_removals: [],
    });
    renderWorkflow();

    expect(await screen.findByText('roleTemplates.apply.scopeWarningDescription:12')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument();
  });

  // ── 23: apply-requires-explicit-confirmation ────────────────────────────────

  it('never calls apply merely from opening the dialog or viewing the preview', async () => {
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: [],
      permission_removals: [],
    });
    renderWorkflow();

    await screen.findByText('roleTemplates.apply.currentVersion');
    await waitFor(() => {});
    expect(mockApply).not.toHaveBeenCalled();
  });

  it('calls apply only after the explicit confirm click', async () => {
    const user = userEvent.setup();
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: [],
      permission_removals: [],
    });
    mockApply.mockResolvedValue({ role_id: 'role-1', template_version: 2, affected_holders: 7, permission_count: 2 });
    renderWorkflow();

    await user.click(await screen.findByRole('button', { name: 'roleTemplates.apply.confirm' }));

    await waitFor(() => expect(mockApply).toHaveBeenCalledWith('cashier'));
  });

  // ── 24: UI-does-not-expose-unsupported-selected-user-apply ─────────────────

  it('offers no per-user selection control anywhere in the workflow — apply is template-wide only', async () => {
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: [],
      permission_removals: [],
    });
    renderWorkflow();

    await screen.findByText('roleTemplates.apply.currentVersion');

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    expect(screen.queryByText(/select.*user/i)).not.toBeInTheDocument();
    // The only action is the single template-wide confirm — its own label says so explicitly.
    expect(screen.getByRole('button', { name: 'roleTemplates.apply.confirm' })).toBeInTheDocument();
  });

  // ── 25: apply-result-refreshes-data ─────────────────────────────────────────

  it('invalidates both the templates and users query families on a successful apply (§12)', async () => {
    const user = userEvent.setup();
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: [],
      permission_removals: [],
    });
    mockApply.mockResolvedValue({ role_id: 'role-1', template_version: 2, affected_holders: 7, permission_count: 2 });
    const { client } = renderWorkflow();
    const invalidateSpy = vi.spyOn(client, 'invalidateQueries');

    await user.click(await screen.findByRole('button', { name: 'roleTemplates.apply.confirm' }));

    await waitFor(() => expect(mockApply).toHaveBeenCalled());
    const invalidatedKeys = invalidateSpy.mock.calls.map((call) => (call[0] as { queryKey: unknown[] }).queryKey[0]);
    expect(invalidatedKeys).toContain('iam-role-templates');
    expect(invalidatedKeys).toContain('iam-users');
  });

  it('shows the affected-holder count in the success confirmation after apply', async () => {
    const user = userEvent.setup();
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: [],
      permission_removals: [],
    });
    mockApply.mockResolvedValue({ role_id: 'role-1', template_version: 2, affected_holders: 7, permission_count: 2 });
    renderWorkflow();

    await user.click(await screen.findByRole('button', { name: 'roleTemplates.apply.confirm' }));

    expect(await screen.findByText('roleTemplates.apply.successDescription:7')).toBeInTheDocument();
  });

  // ── 34: failed-read-disables-destructive-CTA ────────────────────────────────

  it('disables the (destructive) confirm action when the impact-preview read fails', async () => {
    mockImpactPreview.mockRejectedValue({ isAxiosError: true, response: { status: 500, data: {} } });
    renderWorkflow();

    await waitFor(() => expect(mockImpactPreview).toHaveBeenCalled());
    expect(await screen.findByRole('button', { name: /confirm/i })).toBeDisabled();
  });

  it('surfaces a failed apply without silently closing the dialog', async () => {
    const user = userEvent.setup();
    mockImpactPreview.mockResolvedValue({
      template_version: 2,
      compiled: true,
      affected_holders: 7,
      permission_additions: [],
      permission_removals: [],
    });
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server-response text; this test specifically asserts the message is shown verbatim, not translated client-side
    mockApply.mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { message: 'Template already applying.' } } });
    renderWorkflow();

    await user.click(await screen.findByRole('button', { name: 'roleTemplates.apply.confirm' }));

    expect(await screen.findByText('Template already applying.')).toBeInTheDocument();
    expect(await screen.findByRole('dialog')).toBeInTheDocument();
  });
});
