import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';

import type {
  PurchaseMaterial,
  PurchaseMaterialLineSummary,
  PurchaseMaterialsResult,
  PurchaseMaterialStats,
} from '../types/purchase-material';

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-FINAL-CLOSURE-013.
 *
 * Scope: the row-level "Ordered Items" / "Not Yet Ordered" indicators on the Purchases table —
 * both fields (`ordered_items_count`/`not_yet_ordered_items_count` + their line lists) were
 * already computed by the backend and typed on the frontend (TASK-...-011 §9), but the table
 * only ever wired up the ordered half; this closes that gap. Matches this repo's precedent of
 * mocking the SERVICE layer (not the hooks) so the real react-query pipeline runs, and stubbing
 * the heavy sibling drawer/wizard components that are out of scope for this suite.
 */

const mockList = vi.hoisted(() => vi.fn());
const mockStats = vi.hoisted(() => vi.fn());

vi.mock('../services/purchase-materials-service', () => ({
  purchaseMaterialsService: {
    list: mockList,
    get: vi.fn(),
    getStats: mockStats,
    delete: vi.fn(),
    submit: vi.fn(),
    approve: vi.fn(),
    reject: vi.fn(),
    hold: vi.fn(),
    cancel: vi.fn(),
    resume: vi.fn(),
    assignBuyer: vi.fn(),
    selectLineSupplier: vi.fn(),
  },
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

vi.mock('@/features/products/hooks/use-warehouse-options', () => ({
  useWarehouseOptions: () => ({ data: [] }),
}));

vi.mock('@/features/branches/components/company-select', () => ({ CompanySelect: () => null }));
vi.mock('../components/create-purchase-material-wizard', () => ({ CreatePurchaseMaterialWizard: () => null }));
vi.mock('../components/purchase-material-drawer', () => ({ PurchaseMaterialDrawer: () => null }));

// eslint-disable-next-line @typescript-eslint/no-unused-vars -- signature must accept a permission arg; cannot()/canAccess() forward it
const mockCan = vi.hoisted(() => vi.fn((_permission?: string) => true));
vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: mockCan, cannot: (p: string) => !mockCan(p), canAccess: mockCan, canExecute: mockCan }),
}));

// Selector-mode t($ => $.a.b) — return the last path segment (this repo's page-level idiom).
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
  }),
}));

import { PurchasesPage } from './purchases-page';

function line(overrides: Partial<PurchaseMaterialLineSummary> = {}): PurchaseMaterialLineSummary {
  return {
    id: 'line-1',
    product_id: 'p1',
    product_name: 'Widget',
    sku: 'W-1',
    requested_qty: 10,
    ordered_qty: 6,
    remaining_to_order: 4,
    ...overrides,
  };
}

function purchase(overrides: Partial<PurchaseMaterial> = {}): PurchaseMaterial {
  return {
    id: 'pm-1',
    request_number: 'PM-00001',
    record_type: 'purchase',
    source_type: 'direct',
    company_id: 'company-1',
    company: { id: 'company-1', name: 'Acme Co' },
    channel_id: null,
    channel: null,
    warehouse_id: 'wh-1',
    warehouse: { id: 'wh-1', name: 'Main WH' },
    status: 'purchasing',
    status_label: 'Purchasing',
    held_from_status: null,
    display_status: 'purchasing',
    is_on_hold: false,
    available_actions: ['select_supplier'],
    priority: 'normal',
    priority_label: 'Normal',
    requested_by: null,
    assigned_buyer: null,
    assigned_buyer_id: 1,
    buyer: { id: 1, name: 'Sam Buyer', job_title: null },
    is_unowned: false,
    required_date: null,
    submitted_at: null,
    approved_at: null,
    completed_at: null,
    estimated_value: 500,
    approved_value: 0,
    purchased_value: 0,
    approved_by: null,
    rejected_by: null,
    rejection_reason: null,
    notes: null,
    review_notes: null,
    merged_into: null,
    clarification_requested_at: null,
    clarification_requested_by: null,
    items_count: 10,
    total_requested_qty: 100,
    execution_percent: 60,
    ordered_items_count: 6,
    not_yet_ordered_items_count: 4,
    ordered_items: [line({ id: 'ordered-1', product_name: 'Ordered Widget' })],
    not_yet_ordered_items: [line({ id: 'remaining-1', product_name: 'Remaining Widget', ordered_qty: 0, remaining_to_order: 10 })],
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function listResult(items: PurchaseMaterial[]): PurchaseMaterialsResult {
  return { items, meta: { current_page: 1, per_page: 15, total: items.length, last_page: 1 } };
}

function stats(): PurchaseMaterialStats {
  return {
    operational: {
      draft: 0, under_review: 0, waiting_supplier_selection: 0, approved: 0, purchasing: 0,
      receiving: 0, completed: 0, on_hold: 0, rejected: 0, cancelled: 0, open_total: 0,
    },
    workload: { unowned_count: 0, overdue_count: 0, required_soon_count: 0, ordered_lines: 0, not_yet_ordered_lines: 0 },
    financial: { estimated_value_open: 0 },
    by_priority: { urgent: 0, high: 0, normal: 0, low: 0 },
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return {
    client,
    ...render(
      <MemoryRouter>
        <QueryClientProvider client={client}>
          <PurchasesPage />
        </QueryClientProvider>
      </MemoryRouter>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockStats.mockResolvedValue(stats());
});

describe('PurchasesPage — Ordered Items / Not Yet Ordered (TASK-...-013)', () => {
  it('shows both the ordered and not-yet-ordered counts for a partially-ordered request', async () => {
    mockList.mockResolvedValue(listResult([purchase()]));

    renderPage();

    expect(await screen.findByText('PM-00001')).toBeInTheDocument();
    // Two distinct popover triggers: 6 ordered, 4 not yet ordered — never the same count twice.
    expect(screen.getByText('6')).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument();
  });

  it('reveals the ordered line names on click, and the not-yet-ordered ones separately', async () => {
    const user = userEvent.setup();
    mockList.mockResolvedValue(listResult([purchase()]));

    renderPage();
    await screen.findByText('PM-00001');

    await user.click(screen.getByText('6'));
    expect(await screen.findByText('Ordered Widget')).toBeInTheDocument();
    expect(screen.queryByText('Remaining Widget')).not.toBeInTheDocument();

    await user.click(screen.getByText('4'));
    expect(await screen.findByText('Remaining Widget')).toBeInTheDocument();
  });

  it('renders a plain, non-clickable 0 when a request has nothing in that bucket', async () => {
    mockList.mockResolvedValue(listResult([
      purchase({ ordered_items_count: 0, ordered_items: [], not_yet_ordered_items_count: 10, not_yet_ordered_items: [line()] }),
    ]));

    renderPage();
    const row = (await screen.findByText('PM-00001')).closest('tr');
    if (row === null) throw new Error('Could not find the table row for PM-00001.');

    const zero = within(row).getByText('0');
    expect(zero.tagName).not.toBe('BUTTON');
  });
});
