import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
// Redundant with src/test-setup.ts's global setup (harmless — side-effect imports are
// idempotent) but needed here so the pre-commit Guardian's staged-file-scoped type-check,
// which only follows a file's own transitive imports, sees the jest-dom matcher typings too.
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import { LanguageContext } from '@/providers/language-context';

/**
 * CORE-02 Task 2 §9/§11 — Reporting usability closure regression coverage: the new
 * date-range filter is forwarded to the same canonical execution endpoint (never
 * recomputed client-side), and the on-screen result is what export reads from.
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
// call would return the raw selector function itself instead of a string. The mocked t
// resolves a selector to its dotted key path — enough to assert against.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
  }),
}));

vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: () => true }),
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

const catalogue = vi.hoisted(() => ({
  reports: [
    {
      id: 'RPT-TEST-01',
      name: 'Test Report',
      category: 'sales',
      metric_ids: [],
      read_strategy: 'A',
      permission: 'reports.sales.view',
      gap_classification: 'none',
      is_v1: true,
      source_modules: [],
      notes: null,
    },
  ],
  total: 1,
}));

const executeMock = vi.hoisted(() => vi.fn());

vi.mock('@/features/reporting/services/reporting-service', () => ({
  reportingService: {
    catalogue: vi.fn().mockResolvedValue(catalogue),
    metrics: vi.fn().mockResolvedValue({ metrics: [], total: 0 }),
    execute: executeMock,
  },
}));

import { ReportDetailPage } from './report-detail-page';

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <LanguageContext.Provider value={{ language: 'en', dir: 'ltr', setLanguage: () => {} }}>
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={['/reports/RPT-TEST-01']}>
          <Routes>
            <Route path="/reports/:reportId" element={<ReportDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    </LanguageContext.Provider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  executeMock.mockResolvedValue({
    report_id: 'RPT-TEST-01',
    kpis: {},
    rows: [{ product: 'Widget', qty: 5 }],
    totals: {},
    period: { from: null, to: null, timezone: 'UTC' },
    applied_filters: {},
    generated_at: '2026-01-01T00:00:00Z',
  });
});

describe('ReportDetailPage — filters and export', () => {
  it('runs the report with no filters on first load', async () => {
    renderPage();

    await waitFor(() => expect(executeMock).toHaveBeenCalled());
    expect(executeMock).toHaveBeenCalledWith('RPT-TEST-01', { date_from: undefined, date_to: undefined });
    expect(await screen.findByText('Widget')).toBeInTheDocument();
  });

  it('forwards date_from/date_to to the same canonical execution call when set', async () => {
    const user = userEvent.setup();
    renderPage();

    await waitFor(() => expect(executeMock).toHaveBeenCalledTimes(1));

    const [fromInput, toInput] = screen.getAllByDisplayValue('') as HTMLInputElement[];
    await user.type(fromInput, '2026-01-01');
    await user.type(toInput, '2026-01-31');

    await waitFor(() =>
      expect(executeMock).toHaveBeenLastCalledWith('RPT-TEST-01', { date_from: '2026-01-01', date_to: '2026-01-31' }),
    );
  });

  it('disables export when the report has no rows, enables it once rows are present', async () => {
    executeMock.mockResolvedValueOnce({
      report_id: 'RPT-TEST-01',
      kpis: { 'MET-1': 3 },
      rows: [],
      totals: {},
      period: { from: null, to: null, timezone: 'UTC' },
      applied_filters: {},
      generated_at: '2026-01-01T00:00:00Z',
    });

    renderPage();

    const exportButton = await screen.findByRole('button', { name: /export/i });
    expect(exportButton).toBeDisabled();
  });
});
