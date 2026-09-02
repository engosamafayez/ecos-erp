import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-UX-COMPLETION-003 — regression test for the proven stock-field
// bug (parent design report §7, product research trace §A): the mobile card used
// to read `product.stock_status` (a WooCommerce-only passthrough, NULL on every
// ERP-created product) instead of `product.availability_state` (the server-computed
// ERP business truth the desktop list column reads). These tests fail on the old
// `<StockStatusBadge status={product.stock_status} />` implementation.
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
      if (typeof sel !== 'function') return String(sel);
      const path = String((sel as (p: unknown) => unknown)(pathProxy('')));
      return opts ? `${path}:${JSON.stringify(opts)}` : path;
    },
  }),
}));

import { ProductMobileCard } from './product-mobile-card';
import type { Product } from '../types/product';

const BASE: Product = {
  id: 'p1',
  brand_id: null,
  brand: null,
  sku: 'SKU-1',
  barcode: null,
  name: 'Test Product',
  description: null,
  category_id: 'c1',
  category: undefined,
  unit_id: 'u1',
  unit: undefined,
  product_type: 'raw_material' as Product['product_type'],
  is_active: true,
  image_url: null,
  regular_price: 100,
  sale_price: null,
  short_description: null,
  long_description: null,
  // The bug field: a WooCommerce passthrough that is NULL on every ERP-created
  // product — set to the OPPOSITE state of availability_state below, so a test
  // reading the wrong field would visibly disagree with one reading the right field.
  stock_status: 'outofstock',
  availability_state: 'in_stock',
  created_at: null,
  updated_at: null,
};

describe('ProductMobileCard — canonical stock field (bug fix)', () => {
  it('renders availability_state (in_stock), not the stale stock_status field', () => {
    render(<ProductMobileCard product={BASE} onView={vi.fn()} />);
    expect(screen.getByText('stockStatus.instock')).toBeInTheDocument();
    expect(screen.queryByText('stockStatus.outofstock')).toBeNull();
  });

  it('renders out_of_stock when availability_state says so, regardless of stock_status', () => {
    render(
      <ProductMobileCard
        product={{ ...BASE, stock_status: 'instock', availability_state: 'out_of_stock' }}
        onView={vi.fn()}
      />,
    );
    expect(screen.getByText('stockStatus.outofstock')).toBeInTheDocument();
    expect(screen.queryByText('stockStatus.instock')).toBeNull();
  });

  it('renders the finished-good manufacturing-availability badge instead when product_type is finished_good', () => {
    render(
      <ProductMobileCard
        product={{
          ...BASE,
          product_type: 'finished_good' as Product['product_type'],
          availability_state: 'out_of_stock',
          manufacturing_availability: 'instock',
        }}
        onView={vi.fn()}
      />,
    );
    expect(screen.getByText('colDefs.mfgFulfillable')).toBeInTheDocument();
  });

  it('shows the secondary tier (category, brand, margin) when present, without recomputing margin', () => {
    render(
      <ProductMobileCard
        product={{
          ...BASE,
          category: { id: 'c1', code: 'CAT', name: 'Beverages' },
          brand: { id: 'b1', code: 'BR', name: 'Acme' },
          final_margin_pct: 42.4,
        }}
        onView={vi.fn()}
      />,
    );
    expect(screen.getByText('Beverages')).toBeInTheDocument();
    expect(screen.getByText('Acme')).toBeInTheDocument();
    expect(screen.getByText(/mobileCard.marginPercent/)).toBeInTheDocument();
  });

  it('tapping the card invokes onView with the product', () => {
    const onView = vi.fn();
    render(<ProductMobileCard product={BASE} onView={onView} />);
    fireEvent.click(screen.getByRole('button', { name: `View ${BASE.name}` }));
    expect(onView).toHaveBeenCalledWith(BASE);
  });
});
