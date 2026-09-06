import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { Supplier } from '@/features/suppliers/types/supplier';

/**
 * TASK-ECOS-CUSTOMER-SUPPLIER-LEDGER-LINKS-CLOSURE-001.
 *
 * Covers only the new "Account Statement" footer action — the smallest useful
 * link from Supplier 360 to the existing, canonical Finance AP statement/ledger
 * (SupplierLedgerController via the Accounts Payable page). Not a re-test of the
 * drawer's many existing tabs/analytics, which already have their own coverage
 * elsewhere; only the Overview tab (the default) is exercised here, so only its
 * own two hooks need stubbing.
 */

const mockNavigate = vi.hoisted(() => vi.fn());

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => mockNavigate };
});

// eslint-disable-next-line @typescript-eslint/no-unused-vars -- signature must accept a permission arg
const mockCan = vi.hoisted(() => vi.fn((_permission?: string) => true));
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: mockCan, cannot: (p: string) => !mockCan(p), canAccess: mockCan, canExecute: mockCan }),
}));

vi.mock('@/features/suppliers/hooks/use-supplier-analytics', () => ({
  useSupplierAnalytics: () => ({ data: undefined, isLoading: false }),
  useSupplierHealth: () => ({ data: undefined, isLoading: false }),
  useSupplierDocuments: () => ({ data: [], isLoading: false }),
  useUploadSupplierDocument: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteSupplierDocument: () => ({ mutate: vi.fn(), isPending: false }),
  useSupplierInventoryBreakdown: () => ({ data: undefined, isLoading: false }),
  useSupplierPriceHistory: () => ({ data: undefined, isLoading: false }),
  useSupplierProductDemand: () => ({ data: undefined, isLoading: false }),
  useSupplierTimeline: () => ({ data: undefined, isLoading: false }),
}));

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
      return opts?.defaultValue ?? key;
    },
  }),
}));

import { Supplier360Drawer } from './supplier-360-drawer';

function baseSupplier(overrides: Partial<Supplier> = {}): Supplier {
  return {
    id: 's1',
    code: 'SUP-000001',
    name: 'Acme Supplies',
    contact_person: null,
    email: null,
    phone: null,
    mobile: null,
    country: null,
    state: null,
    city: null,
    district: null,
    address: null,
    google_maps_url: null,
    notes: null,
    is_active: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function renderDrawer(supplier: Supplier) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <Supplier360Drawer supplier={supplier} open onOpenChange={() => {}} onEdit={() => {}} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
});

describe('Supplier360Drawer — Account Statement link (TASK-ECOS-CUSTOMER-SUPPLIER-LEDGER-LINKS-CLOSURE-001)', () => {
  it('shows the Account Statement action when the viewer holds finance.ap.view', () => {
    renderDrawer(baseSupplier());

    expect(screen.getByText('accountStatement')).toBeInTheDocument();
  });

  it('hides the Account Statement action when the viewer lacks finance.ap.view', () => {
    mockCan.mockImplementation((permission?: string) => permission !== 'finance.ap.view');
    renderDrawer(baseSupplier());

    expect(screen.queryByText('accountStatement')).not.toBeInTheDocument();
  });

  it('navigates to the existing canonical AP statement route, scoped to this supplier', async () => {
    const user = userEvent.setup();
    renderDrawer(baseSupplier({ id: 's-99' }));

    await user.click(screen.getByText('accountStatement'));

    // No second statement implementation is rendered here — this only navigates to
    // the existing Accounts Payable page/drawer with the supplier id preserved.
    expect(mockNavigate).toHaveBeenCalledWith('/accounting/payables?supplier_id=s-99');
  });

  it('still shows the existing Edit Supplier action unchanged', () => {
    renderDrawer(baseSupplier());

    expect(screen.getByText('editSupplier')).toBeInTheDocument();
  });
});
