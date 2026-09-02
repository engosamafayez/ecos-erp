import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string, matching the
// convention already established in wave-workspace-layout.test.tsx for this feature.
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
    t: (sel: unknown, opts?: Record<string, unknown>) => {
      const path = typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel);
      return opts?.number != null ? `${path}:${opts.number}` : path;
    },
  }),
}));

const { mockUseIsMobile } = vi.hoisted(() => ({ mockUseIsMobile: vi.fn() }));
vi.mock('@/hooks/use-is-mobile', () => ({ useIsMobile: mockUseIsMobile }));

const mockPostpone = { mutate: vi.fn(), isPending: false };
vi.mock('../hooks/use-preparation', () => ({
  usePostponeWaveOrder: () => mockPostpone,
}));

vi.mock('@/features/orders/components/order-status-badge', () => ({
  OrderStatusBadge: ({ status }: { status: string }) => <span data-testid="status-badge">{status}</span>,
}));

const { mockOrderDetailDrawer } = vi.hoisted(() => ({ mockOrderDetailDrawer: vi.fn() }));
vi.mock('@/features/orders/components/order-detail-drawer', () => ({
  OrderDetailDrawer: (props: { order: { id: string } | null; open: boolean }) => {
    mockOrderDetailDrawer(props);
    return props.open ? <div data-testid="order-detail-drawer">{props.order?.id}</div> : null;
  },
}));

import { RelatedOrdersPanel } from './related-orders-panel';
import type { ProductRelatedOrder } from '../types/preparation';

function makeOrder(overrides: Partial<ProductRelatedOrder> = {}): ProductRelatedOrder {
  return {
    order_id: 'ord-1',
    order_number: 'ORD-1001',
    customer_name: 'Jane Doe',
    status: 'confirmed' as ProductRelatedOrder['status'],
    payment_status: 'paid',
    total: 250.5,
    shipping_address: '123 Main St',
    governorate: 'Cairo',
    city: 'Nasr City',
    delivery_zone: 'Zone A',
    brand_name: 'Acme',
    requested_delivery_date: '2026-09-10',
    required_qty: 5,
    ...overrides,
  };
}

const extraColumns = [
  { key: 'required_qty', header: 'Required', align: 'end' as const, cell: (o: ProductRelatedOrder) => o.required_qty },
];

beforeEach(() => {
  vi.clearAllMocks();
  mockPostpone.isPending = false;
});

describe('RelatedOrdersPanel — desktop', () => {
  beforeEach(() => mockUseIsMobile.mockReturnValue(false));

  it('renders canonical order fields including Brand and Requested Delivery', () => {
    render(
      <RelatedOrdersPanel
        open
        onOpenChange={vi.fn()}
        title="wave.productDemand.relatedOrders"
        waveId="wave-1"
        orders={[makeOrder()]}
        isLoading={false}
        emptyLabel="No orders"
        extraColumns={extraColumns}
      />,
    );

    expect(screen.getByText('ORD-1001')).toBeInTheDocument();
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    expect(screen.getByText('Acme')).toBeInTheDocument();
    expect(screen.getByTestId('status-badge')).toHaveTextContent('confirmed');
    expect(screen.getByText('Zone A')).toBeInTheDocument();
  });

  it('opens the canonical OrderDetailDrawer when Order Number is tapped', async () => {
    const user = userEvent.setup();
    render(
      <RelatedOrdersPanel
        open
        onOpenChange={vi.fn()}
        title="wave.productDemand.relatedOrders"
        waveId="wave-1"
        orders={[makeOrder()]}
        isLoading={false}
        emptyLabel="No orders"
        extraColumns={extraColumns}
      />,
    );

    expect(screen.queryByTestId('order-detail-drawer')).not.toBeInTheDocument();
    await user.click(screen.getByText('ORD-1001'));
    expect(screen.getByTestId('order-detail-drawer')).toHaveTextContent('ord-1');
  });

  it('shows the empty state when there are no related orders', () => {
    render(
      <RelatedOrdersPanel
        open
        onOpenChange={vi.fn()}
        title="wave.productDemand.relatedOrders"
        waveId="wave-1"
        orders={[]}
        isLoading={false}
        emptyLabel="No related orders."
        extraColumns={extraColumns}
      />,
    );
    expect(screen.getByText('No related orders.')).toBeInTheDocument();
  });
});

describe('RelatedOrdersPanel — mobile', () => {
  beforeEach(() => mockUseIsMobile.mockReturnValue(true));

  it('renders a card list with the same canonical fields, no data loss', () => {
    render(
      <RelatedOrdersPanel
        open
        onOpenChange={vi.fn()}
        title="wave.productDemand.relatedOrders"
        waveId="wave-1"
        orders={[makeOrder()]}
        isLoading={false}
        emptyLabel="No orders"
        extraColumns={extraColumns}
      />,
    );

    expect(screen.getByText('ORD-1001')).toBeInTheDocument();
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    expect(screen.getByText('Acme')).toBeInTheDocument();
    expect(screen.getByTestId('status-badge')).toHaveTextContent('confirmed');
    // The extraColumns context value (Required qty) still renders on mobile.
    expect(screen.getByText('5')).toBeInTheDocument();
  });

  it('tapping the card opens the order detail drawer; Postpone stays a distinct action', async () => {
    const user = userEvent.setup();
    render(
      <RelatedOrdersPanel
        open
        onOpenChange={vi.fn()}
        title="wave.productDemand.relatedOrders"
        waveId="wave-1"
        orders={[makeOrder()]}
        isLoading={false}
        emptyLabel="No orders"
        extraColumns={extraColumns}
      />,
    );

    await user.click(screen.getByRole('button', { name: /wave\.relatedOrders\.viewOrder/ }));
    expect(screen.getByTestId('order-detail-drawer')).toHaveTextContent('ord-1');
  });

  it('never fabricates a Brand/Requested Delivery field when the data is absent', () => {
    render(
      <RelatedOrdersPanel
        open
        onOpenChange={vi.fn()}
        title="wave.productDemand.relatedOrders"
        waveId="wave-1"
        orders={[makeOrder({ brand_name: null, requested_delivery_date: null })]}
        isLoading={false}
        emptyLabel="No orders"
        extraColumns={extraColumns}
      />,
    );

    expect(screen.queryByText('Acme')).not.toBeInTheDocument();
  });
});
