import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';

import type { SupplierInvoice, SupplierInvoicesResult } from '@/features/supplier-invoices/types/supplier-invoice';

/**
 * TASK-ECOS-PROCUREMENT-INVOICE-FIRST-RECEIVING-FLOW-014 — frontend closure.
 *
 * Scope: the invoice-first receiving surface on the Supplier Invoice detail drawer — linked
 * receipt visibility, "Open Receipt" navigation, Post readiness gating, and that Mode-3 /
 * not-yet-linked invoices render exactly as before. The drawer is opened the same way the Goods
 * Receipt page's own "Open Invoice" link opens it — via the `?open=` deep link — so that wiring
 * is exercised too. Matches this repo's precedent of mocking the SERVICE layer (not the hooks),
 * so the real react-query pipeline runs (see customers-page.test.tsx).
 */

const mockGet = vi.hoisted(() => vi.fn());
const mockList = vi.hoisted(() => vi.fn());
const mockStats = vi.hoisted(() => vi.fn());
const mockNavigate = vi.hoisted(() => vi.fn());

vi.mock('@/features/supplier-invoices/services/supplier-invoices-service', () => ({
  supplierInvoicesService: {
    list: mockList,
    get: mockGet,
    stats: mockStats,
    validate: vi.fn(),
    post: vi.fn(),
    cancel: vi.fn(),
    delete: vi.fn(),
    listDocuments: vi.fn().mockResolvedValue([]),
  },
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => mockNavigate };
});

// Selector-mode t($ => $.a.b) — return the last path segment, matching this repo's own
// page-level mock idiom (see customers-page.test.tsx / goods-inward-mode-card.test.tsx).
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, {
        get: (_t, prop: string) => { path.push(prop); return proxy; },
      });
      (sel as (p: unknown) => unknown)(proxy);
      return path[path.length - 1] ?? '';
    },
    i18n: { language: 'en' },
  }),
}));

// The page's own Pagination (@/components/crud) reads this for RTL-aware arrows; unrelated to
// receiving, but needed for the page to render at all outside the app's real LanguageProvider.
vi.mock('@/providers/language-context', () => ({
  useLanguage: () => ({ language: 'en', dir: 'ltr', setLanguage: vi.fn() }),
}));

vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({ money: (n: number) => `EGP ${n}`, moneyCompact: (n: number) => `EGP ${n} compact` }),
}));

// Out of scope for this suite — stubbed so the drawer renders without their dependency trees.
vi.mock('@/features/supplier-invoices/components/supplier-invoice-editor', () => ({
  SupplierInvoiceEditor: () => null,
}));
vi.mock('@/features/supplier-invoices/components/invoice-attachments', () => ({
  InvoiceAttachments: () => null,
}));

import { SupplierInvoicesPage } from './supplier-invoices-page';

function invoice(overrides: Partial<SupplierInvoice> = {}): SupplierInvoice {
  return {
    id: 'inv-1',
    invoice_number: 'INV-2026-001',
    supplier_invoice_ref: null,
    status: 'validated',
    status_label: 'Validated',
    status_color: 'blue',
    display_status: 'commercially_approved',
    available_actions: ['post', 'cancel'],
    invoice_date: '2026-09-01',
    due_date: null,
    delivery_date: null,
    currency: 'EGP',
    exchange_rate: 1,
    subtotal: 1000,
    tax_total: 0,
    freight_amount: 0,
    additional_costs: 0,
    discount_amount: 0,
    grand_total: 1000,
    payment_terms: null,
    payment_terms_days: null,
    payment_method: null,
    notes: null,
    posting_log: null,
    posting_error: null,
    posted_at: null,
    auto_purchase_id: null,
    auto_receipt_id: 'receipt-1',
    supplier: { id: 'sup-1', name: 'Acme Supplies' },
    warehouse: { id: 'wh-1', name: 'Main WH', code: 'WH1' },
    lines: [],
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function listResult(items: SupplierInvoice[]): SupplierInvoicesResult {
  return { items, meta: { current_page: 1, per_page: 15, total: items.length, last_page: 1 } };
}

function renderPage(initialPath: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return {
    client,
    ...render(
      <MemoryRouter initialEntries={[initialPath]}>
        <QueryClientProvider client={client}>
          <SupplierInvoicesPage />
        </QueryClientProvider>
      </MemoryRouter>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockList.mockResolvedValue(listResult([]));
  mockStats.mockResolvedValue({
    total: 0, draft: 0, validated: 0, posted: 0, failed: 0, total_value: 0, pending_value: 0,
  });
});

describe('SupplierInvoicesPage — invoice-first receiving (TASK-...-014)', () => {
  it('shows the linked receipt summary and blocks Post until the receipt is posted', async () => {
    mockGet.mockResolvedValue(invoice({
      status: 'validated',
      display_status: 'partial_received',
      receiving: {
        status: 'partially_received',
        receipt_id: 'receipt-1',
        receipt_number: 'GR-2026-0007',
        receipt_status: 'draft',
        ready_to_post: false,
        lines: [
          {
            line_id: 'l1', product_id: 'p1', product_name: 'Widget', sku: 'W-1',
            expected_qty: 10, accepted_qty: 8, variance: -2, unit_price: 5, final_landed_unit_cost: null,
          },
        ],
      },
    }));

    renderPage('/purchasing/supplier-invoices?open=inv-1');

    expect(await screen.findByText('GR-2026-0007')).toBeInTheDocument();
    // Original (expected) vs accepted qty and variance are both visible, side by side.
    expect(screen.getByText('10')).toBeInTheDocument();
    expect(screen.getByText('8')).toBeInTheDocument();
    expect(screen.getByText('blockedTitle')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /postToInventory/i })).toBeDisabled();
  });

  it('navigates to the Goods Receipt page when "Open Receipt" is clicked', async () => {
    const user = userEvent.setup();
    mockGet.mockResolvedValue(invoice({
      receiving: {
        status: 'awaiting', receipt_id: 'receipt-1', receipt_number: 'GR-2026-0007',
        receipt_status: 'draft', ready_to_post: false, lines: [],
      },
    }));

    renderPage('/purchasing/supplier-invoices?open=inv-1');
    await screen.findByText('GR-2026-0007');

    await user.click(screen.getByRole('button', { name: /openReceipt/i }));
    expect(mockNavigate).toHaveBeenCalledWith('/goods-receipts/receipt-1');
  });

  it('enables Post once the backend reports ready_to_post', async () => {
    mockGet.mockResolvedValue(invoice({
      status: 'validated',
      display_status: 'fully_received',
      receiving: {
        status: 'reconciled', receipt_id: 'receipt-1', receipt_number: 'GR-2026-0007',
        receipt_status: 'posted', ready_to_post: true, lines: [],
      },
    }));

    renderPage('/purchasing/supplier-invoices?open=inv-1');
    await screen.findByText('GR-2026-0007');

    expect(screen.getByRole('button', { name: /postToInventory/i })).not.toBeDisabled();
    expect(screen.queryByText('blockedTitle')).not.toBeInTheDocument();
  });

  it('leaves Mode-3 / not-yet-linked invoices unaffected — no receiving card, Post ungated', async () => {
    mockGet.mockResolvedValue(invoice({
      status: 'validated',
      auto_receipt_id: null,
      receiving: {
        status: 'not_applicable', receipt_id: null, receipt_number: null,
        receipt_status: null, ready_to_post: false, lines: [],
      },
    }));

    renderPage('/purchasing/supplier-invoices?open=inv-1');
    await screen.findByText('INV-2026-001');

    expect(screen.queryByText(/^GR-/)).not.toBeInTheDocument();
    expect(screen.queryByText('blockedTitle')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /postToInventory/i })).not.toBeDisabled();
  });
});
