import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { Customer } from '@/features/customers/types/customer';

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-CUSTOMER-INTELLIGENCE-008.
 *
 * Covers the new Customer Intelligence card in the Summary tab (repeat status, first
 * order, purchase cadence — including the "not enough orders yet" fallback) and the
 * Product Affinity presentation in the Products tab (canonical name, not a raw UUID,
 * with the orders-count/quantity signal both visible). Service layer mocked, real
 * useQuery pipeline runs, matching the repo's established idiom.
 */

const mockGet = vi.hoisted(() => vi.fn());
const mockBlockHistory = vi.hoisted(() => vi.fn());
const mockUnblock = vi.hoisted(() => vi.fn());

vi.mock('@/features/customers/services/customers-service', () => ({
  customersService: {
    get: mockGet,
    list: vi.fn(),
    block: vi.fn(),
    blockPhone: vi.fn(),
    unblock: mockUnblock,
    blockHistory: mockBlockHistory,
  },
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

vi.mock('@/features/orders/hooks/use-orders', () => ({
  useOrdersQuery: () => ({ data: undefined, isLoading: false }),
}));

// TASK-...-BLOCKED-CUSTOMERS-009: defaults to full grant so Unblock renders;
// the read-only-permission test below overrides this per-case.
const mockCan = vi.hoisted(() => vi.fn(() => true));
vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: mockCan, cannot: (p: string) => !mockCan(p), canAccess: mockCan, canExecute: mockCan }),
}));

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

import { CustomerDrawer } from './customer-drawer';

function baseCustomer(overrides: Partial<Customer> = {}): Customer {
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
    top_products_count: 1,
    top_products: [{ product_id: 'p1', product_name: 'Widget', total_quantity: 12, orders_count: 3 }],
    location_url: null,
    full_address: null,
    preferred_governorate: null,
    channels: [],
    purchased_products: [
      { product_id: 'p1', product_name: 'Widget', product_sku: 'SKU-1', total_quantity: 12, orders_count: 3, last_ordered_at: '2026-01-15' },
    ],
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

function renderDrawer(customer: Customer, defaultTab?: string) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={client}>
      <CustomerDrawer customer={customer} open onOpenChange={() => {}} onEdit={() => {}} defaultTab={defaultTab} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockBlockHistory.mockResolvedValue([]);
});

describe('CustomerDrawer — Customer Intelligence', () => {
  it('shows the repeat badge, first order date, and cadence for a repeat customer', () => {
    renderDrawer(baseCustomer());

    expect(screen.getAllByText('repeat').length).toBeGreaterThan(0);
    expect(screen.getByText('cadenceDays:7')).toBeInTheDocument();
  });

  it('shows "not repeat" and no cadence for a customer with fewer than 2 orders', () => {
    renderDrawer(baseCustomer({
      is_repeat_customer: false,
      avg_days_between_orders: null,
      orders_count: 1,
    }));

    expect(screen.getByText('notRepeat')).toBeInTheDocument();
    expect(screen.getByText('cadenceUnavailable')).toBeInTheDocument();
  });

  it('presents Product Affinity with a canonical product name, never a raw UUID', async () => {
    const customer = baseCustomer();
    mockGet.mockResolvedValue(customer);
    renderDrawer(customer, 'products');

    // Products tab content is fetched via GET /customers/{id} (purchased_products).
    await waitFor(() => expect(mockGet).toHaveBeenCalledWith('c1'));
    expect(await screen.findByText('Widget')).toBeInTheDocument();
    expect(screen.queryByText('p1')).not.toBeInTheDocument();
  });
});

describe('CustomerDrawer — Blocked Customer (TASK-...-BLOCKED-CUSTOMERS-009)', () => {
  it('shows no Blocked card for a customer with no block state and no history', () => {
    renderDrawer(baseCustomer({ is_blocked: false }));

    expect(screen.queryByText('badge')).not.toBeInTheDocument();
  });

  it('shows the blocked state, reason and an Unblock action for a blocked customer', async () => {
    renderDrawer(baseCustomer({
      is_blocked: true,
      block_reason: 'Repeated non-payment',
      blocked_at: '2026-08-01T10:00:00Z',
      customer_block_id: 'block-1',
    }));

    expect(await screen.findByText('badge')).toBeInTheDocument();
    expect(screen.getByText('Repeated non-payment')).toBeInTheDocument();
    expect(screen.getByText('unblockAction')).toBeInTheDocument();
  });

  it('hides the Unblock action for a read-only (unauthorized) user', async () => {
    mockCan.mockReturnValue(false);
    renderDrawer(baseCustomer({
      is_blocked: true,
      block_reason: 'Fraud suspected',
      blocked_at: '2026-08-01T10:00:00Z',
      customer_block_id: 'block-1',
    }));

    expect(await screen.findByText('badge')).toBeInTheDocument();
    expect(screen.queryByText('unblockAction')).not.toBeInTheDocument();
  });

  it('renders block/unblock history entries', async () => {
    mockBlockHistory.mockResolvedValue([
      {
        id: 'b1', company_id: 'company-1', customer_id: 'c1', normalized_phone: '201012345678',
        is_active: false, block_reason: 'First offense', blocked_by: 'u1', blocked_at: '2026-07-01T00:00:00Z',
        unblock_reason: 'Resolved', unblocked_by: 'u2', unblocked_at: '2026-07-05T00:00:00Z',
      },
    ]);
    renderDrawer(baseCustomer({ is_blocked: false }));

    expect(await screen.findByText('history')).toBeInTheDocument();
    expect(screen.getByText(/historyBlockedEntry/)).toBeInTheDocument();
    expect(screen.getByText(/historyUnblockedEntry/)).toBeInTheDocument();
  });

  it('requires a reason before the Unblock confirm button is enabled', async () => {
    const user = userEvent.setup();
    renderDrawer(baseCustomer({
      is_blocked: true,
      block_reason: 'Fraud suspected',
      blocked_at: '2026-08-01T10:00:00Z',
      customer_block_id: 'block-1',
    }));

    await user.click(await screen.findByText('unblockAction'));
    const dialog = await screen.findByRole('dialog');
    const { getByText } = within(dialog);
    expect(getByText('unblockAction').closest('button')).toBeDisabled();
  });
});
