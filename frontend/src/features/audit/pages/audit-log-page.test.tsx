import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
// Redundant with src/test-setup.ts's global setup (harmless — side-effect imports are
// idempotent) but needed here so the pre-commit Guardian's staged-file-scoped type-check,
// which only follows a file's own transitive imports, sees the jest-dom matcher typings too.
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { LanguageContext } from '@/providers/language-context';

/**
 * CORE-02 Task 2 §5/§17 — Audit Activity workspace: renders the central Audit read
 * surface, forwards operator filters verbatim, and expands a row's already-fetched
 * before/after payload honestly (no client-side redaction or reconstruction).
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
// This app's t() runs in i18next SELECTOR mode (t($ => $.some.key)); the real i18next
// instance isn't configured in this isolated render, so without this mock every selector
// call would return the raw selector function itself (React then refuses to render it:
// "Functions are not valid as a React child"). The mocked t resolves a selector to its
// dotted key path instead — good enough to assert against and to keep every label a real
// string.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
  }),
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

const emptyUsers = vi.hoisted(() => ({ data: [], meta: { total: 0, page: 1, per_page: 20 } }));

vi.mock('@/features/iam-admin/services/users-service', () => ({
  usersService: { list: vi.fn().mockResolvedValue(emptyUsers) },
}));

const listMock = vi.hoisted(() => vi.fn());

vi.mock('@/features/audit/services/audit-service', () => ({
  auditService: { list: listMock },
}));

import { AuditLogPage } from './audit-log-page';

const ENTRY = {
  id: 'log-1',
  company_id: 'company-1',
  action: 'user.activated',
  entity_type: 'user',
  entity_id: 'user-42',
  old_values: { status: 'draft' },
  new_values: { status: 'active' },
  metadata: null,
  occurred_at: '2026-06-01T10:00:00Z',
  actor: { id: 1, name: 'Admin User', email: 'admin@ecos.test' },
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <LanguageContext.Provider value={{ language: 'en', dir: 'ltr', setLanguage: () => {} }}>
      <QueryClientProvider client={client}>
        <AuditLogPage />
      </QueryClientProvider>
    </LanguageContext.Provider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  listMock.mockResolvedValue({
    items: [ENTRY],
    meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
  });
});

describe('AuditLogPage', () => {
  it('renders the fetched activity list', async () => {
    renderPage();

    // UniversalDataGrid renders both the table row and its mobile-card fallback (toggled
    // by CSS, not JS), so each cell's content legitimately appears twice in the DOM here.
    expect((await screen.findAllByText('Admin User')).length).toBeGreaterThan(0);
    expect(screen.getAllByText('user.activated').length).toBeGreaterThan(0);
  });

  it('forwards the action filter to the central Audit read endpoint', async () => {
    const user = userEvent.setup();
    renderPage();

    await waitFor(() => expect(listMock).toHaveBeenCalledTimes(1));

    await user.click(screen.getByRole('button', { name: /toolbar\.filters/i }));
    await user.type(screen.getByPlaceholderText('filters.actionPlaceholder'), 'role.updated');

    await waitFor(() =>
      expect(listMock).toHaveBeenLastCalledWith(expect.objectContaining({ action: 'role.updated' })),
    );
  });

  it('opens the detail drawer with the row\'s already-fetched before/after values on click', async () => {
    const user = userEvent.setup();
    renderPage();

    const [row] = await screen.findAllByText('user.activated');
    await user.click(row);

    expect(await screen.findByText('"status": "draft"', { exact: false })).toBeInTheDocument();
    expect(screen.getByText('"status": "active"', { exact: false })).toBeInTheDocument();
  });

  it('renders "System" for an event with no actor', async () => {
    listMock.mockResolvedValue({
      items: [{ ...ENTRY, actor: null }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });

    renderPage();

    // Mocked t() resolves a selector to its dotted key path, so `t($ => $.systemActor)`
    // renders as the literal string "systemActor" here — not the real English label.
    expect((await screen.findAllByText('systemActor')).length).toBeGreaterThan(0);
  });
});
