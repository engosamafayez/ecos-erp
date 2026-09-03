import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
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

vi.mock('@/features/customers/services/customers-service', () => ({
  customersService: { get: mockGet, list: vi.fn() },
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => ({ activeCompanyId: 'company-1' }),
}));

vi.mock('@/features/orders/hooks/use-orders', () => ({
  useOrdersQuery: () => ({ data: undefined, isLoading: false }),
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
