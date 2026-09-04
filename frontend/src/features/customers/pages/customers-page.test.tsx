import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';

import type { Customer, CustomersResult } from '@/features/customers/types/customer';

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-CUSTOMER-INTELLIGENCE-008.
 *
 * The SERVICE layer is mocked, not the hooks — the real useQuery pipeline runs, so a
 * segment click/filter interaction genuinely drives a new query with the right params,
 * matching the idiom in goods-inward-mode-card.test.tsx (the repo's own precedent for
 * "mock the service, not the hook").
 *
 * Scope: the Customer Intelligence panel only (opens, segments, product filter, repeat
 * badge, empty state) — not the full page's search/keyboard/drawer surface, which is
 * pre-existing and out of this task's scope.
 */

const mockList = vi.hoisted(() => vi.fn());
const mockNavigate = vi.hoisted(() => vi.fn());
const mockBlock = vi.hoisted(() => vi.fn());
const mockBlockPhone = vi.hoisted(() => vi.fn());
const mockUnblock = vi.hoisted(() => vi.fn());

vi.mock('@/features/customers/services/customers-service', () => ({
  customersService: {
    list: mockList,
    block: mockBlock,
    blockPhone: mockBlockPhone,
    unblock: mockUnblock,
    blockHistory: vi.fn().mockResolvedValue([]),
  },
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

// TASK-...-BLOCKED-CUSTOMERS-009: full grant by default; the read-only test overrides it.
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- signature must accept a permission arg; cannot()/canAccess() forward it
const mockCan = vi.hoisted(() => vi.fn((_permission?: string) => true));
vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: mockCan, cannot: (p: string) => !mockCan(p), canAccess: mockCan, canExecute: mockCan }),
}));

// The label is an API RESPONSE FIXTURE reproducing useProductOptions' real "SKU – Name"
// shape, not UI copy — matches goods-inward-mode-card.test.tsx's own precedent.
/* eslint-disable ecos-i18n/no-hardcoded-ui-strings */
vi.mock('@/features/orders/hooks/use-product-options', () => ({
  useProductOptions: () => ({
    data: [{ value: 'product-1', label: 'SKU-1 – Widget' }],
    isLoading: false,
  }),
}));
/* eslint-enable ecos-i18n/no-hardcoded-ui-strings */

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => mockNavigate };
});

// Selector-mode t($ => $.a.b) — return the last path segment, matching the repo's own
// established mock idiom (goods-inward-mode-card.test.tsx).
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: { count?: number; defaultValue?: string }) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, {
        get: (_t, prop: string) => { path.push(prop); return proxy; },
      });
      (sel as (p: unknown) => unknown)(proxy);
      const key = path[path.length - 1] ?? '';
      if (opts?.count !== undefined) return `${key}:${opts.count}`;
      return opts?.defaultValue ?? key;
    },
  }),
}));

// The Customer profile/form drawers and quick-action card are not under test here —
// stubbed so the page renders without pulling in their own dependency trees.
vi.mock('@/features/customers/components/customer-drawer', () => ({
  CustomerDrawer: () => null,
}));
vi.mock('@/features/customers/components/customer-form-drawer', () => ({
  CustomerFormDrawer: () => null,
}));
vi.mock('@/features/customers/components/customer-quick-action-card', () => ({
  CustomerQuickActionCard: () => null,
}));

import { CustomersPage } from './customers-page';

function customer(overrides: Partial<Customer> = {}): Customer {
  return {
    id: 'c1',
    company_id: 'company-1',
    sales_owner_id: null,
    sales_owner_name: null,
    code: 'CUST-000001',
    name: 'Acme Corp',
    contact_person: null,
    email: null,
    phone: '0501112222',
    mobile: null,
    country: null,
    city: null,
    address: null,
    notes: null,
    is_active: true,
    brands: [],
    orders_count: 3,
    total_order_value: 900,
    delivered_count: 2,
    receiving_rate: 66.67,
    average_order_value: 300,
    last_order_at: '2026-01-15',
    first_order_at: '2026-01-01',
    is_repeat_customer: true,
    avg_days_between_orders: 7,
    top_products_count: 0,
    top_products: [],
    location_url: null,
    full_address: null,
    preferred_governorate: null,
    channels: [],
    created_at: null,
    updated_at: null,
    is_blocked: false,
    block_reason: null,
    blocked_at: null,
    blocked_by: null,
    customer_block_id: null,
    ...overrides,
  };
}

function result(items: Customer[]): CustomersResult {
  return {
    items,
    meta: { current_page: 1, per_page: 20, total: items.length, last_page: 1 },
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return {
    client,
    ...render(
      <MemoryRouter>
        <QueryClientProvider client={client}>
          <CustomersPage />
        </QueryClientProvider>
      </MemoryRouter>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockList.mockResolvedValue(result([customer()]));
  mockCan.mockReturnValue(true);
});

describe('CustomersPage — Customer Intelligence', () => {
  it('renders a repeat badge for a repeat customer and none for a non-repeat customer', async () => {
    mockList.mockResolvedValue(result([
      customer({ id: 'c1', is_repeat_customer: true }),
      customer({ id: 'c2', name: 'Single Order Co', is_repeat_customer: false }),
    ]));
    renderPage();

    await waitFor(() => expect(screen.getAllByText('Acme Corp').length).toBeGreaterThan(0));

    expect(screen.getAllByText('repeat').length).toBe(1);
  });

  it('opens the Intelligence panel and shows the segment/product-affinity controls', async () => {
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Acme Corp');
    await user.click(screen.getByText('trigger'));

    expect(await screen.findByText('highestSpend')).toBeInTheDocument();
    expect(screen.getByText('repeatCustomers')).toBeInTheDocument();
    expect(screen.getByText('productAffinity')).toBeInTheDocument();
  });

  it('clicking Highest Spend sorts by total_order_value descending', async () => {
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Acme Corp');
    await user.click(screen.getByText('trigger'));
    await user.click(await screen.findByText('highestSpend'));

    await waitFor(() => {
      const lastCall = mockList.mock.calls.at(-1)?.[0];
      expect(lastCall).toMatchObject({ sort_by: 'total_order_value', sort_dir: 'desc' });
    });
  });

  it('toggling Repeat Customers filters the backend query by repeat_only', async () => {
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Acme Corp');
    await user.click(screen.getByText('trigger'));
    await user.click(await screen.findByText('repeatCustomers'));

    await waitFor(() => {
      const lastCall = mockList.mock.calls.at(-1)?.[0];
      expect(lastCall).toMatchObject({ repeat_only: true });
    });

    // Toggling again clears the filter — never a client-side-only filter of the page.
    await user.click(screen.getByText('repeatCustomers'));
    await waitFor(() => {
      const lastCall = mockList.mock.calls.at(-1)?.[0];
      expect(lastCall?.repeat_only).toBeUndefined();
    });
  });

  it('selecting a product in the affinity picker filters the backend query by product_id', async () => {
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Acme Corp');
    await user.click(screen.getByText('trigger'));
    await screen.findByText('productAffinity');

    await user.click(screen.getByText('selectProduct'));
    await user.click(await screen.findByText('SKU-1 – Widget'));

    await waitFor(() => {
      const lastCall = mockList.mock.calls.at(-1)?.[0];
      expect(lastCall).toMatchObject({ product_id: 'product-1' });
    });
  });

  it('shows the empty state when the Intelligence filter matches no customers', async () => {
    mockList.mockResolvedValue(result([]));
    renderPage();

    expect(await screen.findByText('empty')).toBeInTheDocument();
  });
});

describe('CustomersPage — Blocked Customers (TASK-...-BLOCKED-CUSTOMERS-009)', () => {
  it('renders a Blocked badge for a blocked customer', async () => {
    mockList.mockResolvedValue(result([
      customer({ id: 'c1', is_blocked: true, block_reason: 'Fraud suspected' }),
    ]));
    renderPage();

    expect(await screen.findByText('badge')).toBeInTheDocument();
  });

  it('toggling the Blocked filter queries the backend by blocked_only, and clears on toggle-off', async () => {
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Acme Corp');
    await user.click(screen.getByText('filter'));

    await waitFor(() => {
      const lastCall = mockList.mock.calls.at(-1)?.[0];
      expect(lastCall).toMatchObject({ blocked_only: true });
    });

    await user.click(screen.getByText('filter'));
    await waitFor(() => {
      const lastCall = mockList.mock.calls.at(-1)?.[0];
      expect(lastCall?.blocked_only).toBeUndefined();
    });
  });

  it('Block Phone requires both a phone and a reason before submitting', async () => {
    const user = userEvent.setup();
    mockBlockPhone.mockResolvedValue({ id: 'block-1' });
    renderPage();

    await screen.findByText('Acme Corp');
    await user.click(screen.getByText('blockPhoneAction'));

    const dialog = await screen.findByRole('dialog');
    const confirmButton = within(dialog).getByText('blockAction').closest('button')!;
    expect(confirmButton).toBeDisabled();

    await user.type(within(dialog).getByPlaceholderText('phonePlaceholder'), '01012345678');
    expect(confirmButton).toBeDisabled();

    await user.type(within(dialog).getByPlaceholderText('reasonPlaceholder'), 'Chargeback fraud');
    expect(confirmButton).not.toBeDisabled();

    await user.click(confirmButton);
    await waitFor(() => {
      expect(mockBlockPhone).toHaveBeenCalledWith('01012345678', 'Chargeback fraud');
    });
  });

  it('hides the Block Phone action for a read-only (unauthorized) user', async () => {
    mockCan.mockReturnValue(false);
    renderPage();

    await screen.findByText('Acme Corp');
    expect(screen.queryByText('blockPhoneAction')).not.toBeInTheDocument();
  });
});
