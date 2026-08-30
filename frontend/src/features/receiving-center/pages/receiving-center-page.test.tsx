import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string.
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
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
    i18n: { language: 'en', exists: () => true },
  }),
}));

// Grid renders BOTH the desktop cells and the mobile card so we can assert both presentations.
vi.mock('@/components/data-grid/universal-data-grid', () => ({
  UniversalDataGrid: ({
    data,
    columns,
    renderMobileCard,
    emptyState,
    rowId,
  }: {
    data: unknown[];
    columns: { key: string; cell: (r: unknown) => ReactNode }[];
    renderMobileCard?: (r: unknown) => ReactNode;
    emptyState: ReactNode;
    rowId: (r: unknown) => string;
  }) =>
    data.length === 0 ? (
      <>{emptyState}</>
    ) : (
      <div>
        <table data-testid="desktop">
          <tbody>
            {data.map((r) => (
              <tr key={rowId(r)} data-testid="po-row">
                {columns.map((c) => <td key={c.key}>{c.cell(r)}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
        <div data-testid="mobile">
          {data.map((r) => <div key={`${rowId(r)}-m`}>{renderMobileCard?.(r)}</div>)}
        </div>
      </div>
    ),
}));

// Keep PageHeader/Pagination as light stubs, but use the REAL EntityToolbar (imported from its own
// submodule, not the heavy barrel) so the test exercises the actual Search + Filter responsive pattern
// — the collapsible Filters panel and the supplier control it contains.
vi.mock('@/components/crud', async () => {
  const toolbar = await vi.importActual<typeof import('@/components/crud/entity-toolbar')>(
    '@/components/crud/entity-toolbar',
  );
  return {
    PageHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
    Pagination: () => <div data-testid="pagination" />,
    EntityToolbar: toolbar.EntityToolbar,
  };
});
vi.mock('../components/receive-drawer', () => ({ ReceiveDrawer: () => null }));
vi.mock('@/features/goods-receipts/hooks/use-warehouse-options', () => ({ useWarehouseOptions: () => ({ data: [] }) }));
vi.mock('@/features/purchase-orders/hooks/use-supplier-options', () => ({
  useSupplierOptions: () => ({
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture supplier option label
    data: [{ value: 's1', label: 'ACME – Acme Foods' }],
  }),
}));

import { useReceivingQueue } from '../hooks/use-receiving';
vi.mock('../hooks/use-receiving', () => ({ useReceivingQueue: vi.fn() }));

import { ReceivingCenterPage } from './receiving-center-page';
import type { ReceivingQueueParams, ReceivingQueueRow } from '../types/receiving';

const mockQueue = useReceivingQueue as unknown as ReturnType<typeof vi.fn>;

/** The params object the page passed on its most recent call to useReceivingQueue. */
function lastParams(): ReceivingQueueParams {
  return mockQueue.mock.calls.at(-1)?.[0] as ReceivingQueueParams;
}

const ROW: ReceivingQueueRow = {
  id: 'po-1',
  po_number: 'PO-001',
  supplier: { id: 's1', code: 'ACME', name: 'Acme Foods' },
  warehouse: { id: 'w1', code: 'WH', name: 'Main' },
  order_date: '2026-08-01',
  expected_date: null,
  product_count: 3,
  expected_qty: 100,
  received_qty: 40,
  remaining_qty: 60,
  received_pct: 40,
  status: 'partially_received',
  status_label: 'Partially Received',
};

function withData(over: Partial<{ items: ReceivingQueueRow[]; loading: boolean; error: boolean }> = {}) {
  const pending = Boolean(over.error) || Boolean(over.loading);
  mockQueue.mockReturnValue({
    data: pending ? undefined : {
      scope: 'active',
      kpis: { awaiting: 1, partial: 1, received: 0 },
      items: over.items ?? [ROW],
      meta: { current_page: 1, per_page: 15, total: 1, last_page: 1 },
    },
    isLoading: over.loading ?? false,
    isFetching: false,
    isError: over.error ?? false,
    refetch: vi.fn(),
  });
}

describe('ReceivingCenterPage', () => {
  it('lists receivable purchase orders with canonical aggregates', () => {
    withData();
    render(<ReceivingCenterPage />);
    // PO renders in BOTH desktop row and mobile card.
    expect(screen.getAllByText('PO-001').length).toBe(2);
    expect(screen.getAllByText('Acme Foods').length).toBeGreaterThan(0);
    expect(screen.getByTestId('po-row')).toBeInTheDocument();
    expect(screen.getByTestId('mobile')).toBeInTheDocument();
  });

  it('offers a Receive action and NEVER a manual New Receipt (§3)', () => {
    withData();
    render(<ReceivingCenterPage />);
    // Receive action present (desktop + mobile).
    expect(screen.getAllByText('page.actions.receive').length).toBeGreaterThan(0);
    // The legacy manual-creation entry points are gone.
    expect(screen.queryByText('page.actions.newReceipt')).not.toBeInTheDocument();
    expect(screen.queryByText('page.actions.receiveGoods')).not.toBeInTheDocument();
    expect(screen.queryByText(/new receipt/i)).not.toBeInTheDocument();
  });

  it('shows the empty state when there is nothing to receive', () => {
    withData({ items: [] });
    render(<ReceivingCenterPage />);
    expect(screen.getByText('page.empty.active')).toBeInTheDocument();
    expect(screen.queryByTestId('po-row')).not.toBeInTheDocument();
  });

  it('shows an error state that can be retried', () => {
    withData({ error: true });
    render(<ReceivingCenterPage />);
    expect(screen.getByText('page.loadError')).toBeInTheDocument();
  });

  // ── Supplier filter (TASK-PROCUREMENT-PO-RECEIVING-CENTER-CLOSURE-001) ──────────

  it('keeps advanced filters behind the Filters toggle and reveals the Supplier control (mobile Search+Filter pattern)', () => {
    withData();
    render(<ReceivingCenterPage />);
    // Collapsed by default — no wide filter toolbar; the supplier control is not yet mounted.
    expect(screen.queryByLabelText('page.filters.supplier')).not.toBeInTheDocument();
    // Opening the Filters panel reveals the Supplier and Warehouse controls.
    fireEvent.click(screen.getByText('toolbar.filters'));
    expect(screen.getByLabelText('page.filters.supplier')).toBeInTheDocument();
    expect(screen.getByLabelText('page.filters.warehouse')).toBeInTheDocument();
  });

  it('sends the canonical supplier_id to the server-side queue when a supplier is selected', () => {
    withData();
    render(<ReceivingCenterPage />);
    expect(lastParams().supplier_id).toBeUndefined();
    fireEvent.click(screen.getByText('toolbar.filters'));
    fireEvent.change(screen.getByLabelText('page.filters.supplier'), { target: { value: 's1' } });
    // The queue is re-read with the canonical server-side supplier_id (no client-side filtering).
    expect(lastParams()).toMatchObject({ supplier_id: 's1' });
  });

  it('clears the supplier filter back to the unfiltered queue', () => {
    withData();
    render(<ReceivingCenterPage />);
    fireEvent.click(screen.getByText('toolbar.filters'));
    fireEvent.change(screen.getByLabelText('page.filters.supplier'), { target: { value: 's1' } });
    expect(lastParams().supplier_id).toBe('s1');
    // FilterPanel "Clear" resets the advanced filters → the queue reverts to unfiltered.
    fireEvent.click(screen.getByText('actions.clear'));
    expect(lastParams().supplier_id).toBeUndefined();
  });
});
