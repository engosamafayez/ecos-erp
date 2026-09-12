import '@testing-library/jest-dom';

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';

import { LanguageContext } from '@/providers/language-context';
import type { PurchaseOrder, PurchaseOrdersResult } from '@/features/purchase-orders/types/purchase-order';

// jsdom implements neither scrollIntoView nor pointer capture, both of which
// Radix Select's open/highlight logic calls — without these it throws before
// the dropdown ever opens (the same class of jsdom gap ecos-combobox.test.tsx
// already works around for scrollIntoView).
Element.prototype.scrollIntoView = Element.prototype.scrollIntoView ?? (() => {});
Element.prototype.hasPointerCapture = Element.prototype.hasPointerCapture ?? (() => false);
Element.prototype.releasePointerCapture = Element.prototype.releasePointerCapture ?? (() => {});

/**
 * TASK-ECOS-V1.1-CORE-01-UI-03-LIST-TABLE-FILTER-WORK-QUEUE-047 — page-level
 * proof that the canonical UniversalDataGrid composition, migrated onto this
 * page from EntityTable, drives the REAL query pipeline correctly (not just
 * UniversalDataGrid's own component-level unit tests). Follows the repo's
 * established idiom (customers-page.test.tsx): mock the SERVICE layer, not
 * the hooks, so the real useQuery pipeline runs and a sort/filter/page click
 * genuinely produces the right request params.
 *
 * jsdom has no real CSS, so — exactly as universal-data-grid.test.tsx's own
 * comments note — this page's desktop <table> row AND its custom mobile
 * MobileDataCard both render at once for every row. Every row-content
 * assertion below therefore uses findAllByText(...)[0]/getAllByText(...)[0]
 * rather than the singular findByText/getByText.
 *
 * Selection and column-visibility are not covered here: this migration
 * deliberately did not add either (neither existed on this page before, and
 * ticket §6/§8 scope them "where applicable" — this page has no bulk-action
 * or column-manager feature to preserve, so adding either would be inventing
 * a new capability, not migrating an existing one).
 */

const mockList = vi.hoisted(() => vi.fn());
const mockNavigate = vi.hoisted(() => vi.fn());

vi.mock('@/features/purchase-orders/services/purchase-orders-service', () => ({
  purchaseOrdersService: {
    list: mockList,
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    approve: vi.fn(),
    cancel: vi.fn(),
    submit: vi.fn(),
  },
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => mockNavigate };
});

// Selector-mode t($ => $.a.b) — return the last path segment, matching the
// repo's own established mock idiom (customers-page.test.tsx).
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: { defaultValue?: string }) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, {
        get: (_t, prop: string) => { path.push(prop); return proxy; },
      });
      (sel as (p: unknown) => unknown)(proxy);
      const key = path[path.length - 1] ?? '';
      if (opts?.defaultValue) return opts.defaultValue;
      return key;
    },
  }),
}));

import { PurchaseOrdersPage } from './purchase-orders-page';

function purchaseOrder(overrides: Partial<PurchaseOrder> = {}): PurchaseOrder {
  return {
    id: 'po-1',
    po_number: 'PO-000001',
    supplier_id: 's1',
    supplier: { id: 's1', code: 'SUP-1', name: 'Acme Supplies' },
    warehouse_id: 'w1',
    warehouse: { id: 'w1', code: 'WH-1', name: 'Main Warehouse' },
    company_id: 'company-1',
    supplier_reference: null,
    order_date: '2026-01-10',
    expected_date: '2026-01-20',
    status: 'draft',
    status_label: 'Draft',
    notes: null,
    subtotal: 1000,
    discount_amount: 0,
    shipping_amount: 0,
    additional_costs: 0,
    grand_total: 1000,
    total: 1000,
    received_percentage: 0,
    created_by: null,
    lines: [],
    created_at: '2026-01-10T00:00:00Z',
    updated_at: null,
    ...overrides,
  };
}

function result(items: PurchaseOrder[]): PurchaseOrdersResult {
  return {
    items,
    meta: { current_page: 1, per_page: 10, total: items.length, last_page: 1 },
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        {/* The canonical Pagination component reads useLanguage() (RTL-aware
            prev/next icon) — a real dependency, not a test artifact. This
            file mocks react-i18next for the t() key-collapsing idiom above,
            so the full LanguageProvider (which needs the real i18n instance +
            I18nextProvider) would conflict with that mock; this supplies just
            the context value it actually needs instead. */}
        <LanguageContext.Provider value={{ language: 'en', dir: 'ltr', setLanguage: () => {} }}>
          <PurchaseOrdersPage />
        </LanguageContext.Provider>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

function lastQuery() {
  return mockList.mock.calls.at(-1)?.[0];
}

/** First match of possibly-duplicated row content (desktop <td> + mobile card render together in jsdom). */
async function firstText(text: string) {
  return (await screen.findAllByText(text))[0];
}

beforeEach(() => {
  vi.clearAllMocks();
  mockList.mockResolvedValue(result([purchaseOrder()]));
});

describe('PurchaseOrdersPage — canonical UniversalDataGrid composition', () => {
  it('renders real purchase orders through the grid', async () => {
    renderPage();
    expect(await firstText('PO-000001')).toBeInTheDocument();
    expect(await firstText('Acme Supplies')).toBeInTheDocument();
  });

  it('clicking a sortable column header sorts ascending, then descending on a second click', async () => {
    const user = userEvent.setup();
    renderPage();
    await firstText('PO-000001');

    await user.click(screen.getByRole('button', { name: /number/i }));
    await waitFor(() => expect(lastQuery()).toMatchObject({ sort_by: 'po_number', sort_dir: 'asc' }));

    await user.click(screen.getByRole('button', { name: /number/i }));
    await waitFor(() => expect(lastQuery()).toMatchObject({ sort_by: 'po_number', sort_dir: 'desc' }));
  });

  it('sorting by a different column resets to ascending for that field', async () => {
    const user = userEvent.setup();
    renderPage();
    await firstText('PO-000001');

    await user.click(screen.getByRole('button', { name: /number/i }));
    await waitFor(() => expect(lastQuery()).toMatchObject({ sort_by: 'po_number', sort_dir: 'asc' }));

    await user.click(screen.getByRole('button', { name: /total/i }));
    await waitFor(() => expect(lastQuery()).toMatchObject({ sort_by: 'grand_total', sort_dir: 'asc' }));
  });

  it('paginates via the canonical Pagination control wired through UniversalDataGrid', async () => {
    const user = userEvent.setup();
    // Force a real multi-page meta on the first load so Next is enabled.
    mockList.mockResolvedValueOnce({
      items: [purchaseOrder()],
      meta: { current_page: 1, per_page: 10, total: 25, last_page: 3 },
    });
    mockList.mockResolvedValue(result([purchaseOrder()]));
    renderPage();
    await firstText('PO-000001');

    await user.click(screen.getByRole('button', { name: /next/i }));
    await waitFor(() => expect(lastQuery()).toMatchObject({ page: 2 }));
  });

  it('typing in search drives the backend query by the typed value', async () => {
    const user = userEvent.setup();
    renderPage();
    await firstText('PO-000001');

    const search = screen.getByRole('textbox');
    await user.type(search, 'PO-2');

    await waitFor(() => expect(lastQuery()).toMatchObject({ search: 'PO-2' }), { timeout: 2000 });
  });

  it('selecting a status filter queries the backend by that status, and clearing filters resets it', async () => {
    const user = userEvent.setup();
    renderPage();
    await firstText('PO-000001');

    // Open the filter panel, then the canonical ui/select trigger inside it.
    await user.click(screen.getByRole('button', { name: /filters/i }));
    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: /^approved$/i }));

    await waitFor(() => expect(lastQuery()).toMatchObject({ status: 'approved' }));

    await user.click(screen.getByRole('button', { name: /^clear$/i }));
    await waitFor(() => expect(lastQuery()).toMatchObject({ status: 'all' }));
  });

  it('shows the empty state when the backend returns no purchase orders', async () => {
    mockList.mockResolvedValue(result([]));
    renderPage();
    expect(await firstText('noRecords')).toBeInTheDocument();
  });

  it('shows an error state with retry when the query fails, never rendering the empty state instead', async () => {
    const user = userEvent.setup();
    mockList.mockRejectedValueOnce(new Error('network down'));
    mockList.mockResolvedValueOnce(result([purchaseOrder()]));
    renderPage();

    expect(await screen.findByRole('heading', { level: 1 })).toBeInTheDocument();
    await waitFor(() => expect(screen.getAllByRole('button', { name: /retry/i }).length).toBeGreaterThan(0));
    expect(screen.queryByText('noRecords')).not.toBeInTheDocument();

    await user.click(screen.getAllByRole('button', { name: /retry/i })[0]);
    expect(await firstText('PO-000001')).toBeInTheDocument();
  });
});
