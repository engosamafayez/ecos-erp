import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-UX-COMPLETION-003 — on mobile, the 8-tab desktop switcher
// (which hides 7/8 of the canonical Product detail behind a tap) is replaced by
// all 8 sections stacked and simultaneously present (design report §8: "no
// canonical section may silently disappear"). This test fails if any section is
// missing or if the mobile branch falls back to the tab switcher.
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
vi.mock('@/hooks/use-is-mobile', () => ({ useIsMobile: () => true }));
vi.mock('@/features/products/hooks/use-products', () => ({
  useCreateProduct: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateProduct: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { ProductDetailDrawer } from './product-detail-drawer';
import type { Product } from '../types/product';

const PRODUCT: Product = {
  id: 'p1',
  brand_id: 'b1',
  brand: { id: 'b1', code: 'BR', name: 'Acme' },
  sku: 'SKU-1',
  barcode: null,
  name: 'Test Product',
  description: null,
  category_id: 'c1',
  category: { id: 'c1', code: 'CAT', name: 'Beverages' },
  unit_id: 'u1',
  unit: { id: 'u1', code: 'EA', name: 'Each' },
  product_type: 'raw_material' as Product['product_type'],
  is_active: true,
  image_url: null,
  regular_price: 100,
  sale_price: null,
  short_description: null,
  long_description: null,
  stock_status: null,
  availability_state: 'in_stock',
  effective_cost: 40,
  markup_pct: 150,
  gross_profit_pct: 60,
  final_margin_pct: 60,
  on_hand_qty: 10,
  reserved_qty: 2,
  available_qty: 8,
  allow_negative_stock: false,
  has_recipe: false,
  channels: [],
  sync_status: 'not_synced',
  is_published: false,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
};

function makeQC() {
  return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function renderDrawer() {
  return render(
    <QueryClientProvider client={makeQC()}>
      <MemoryRouter>
        <ProductDetailDrawer product={PRODUCT} open onOpenChange={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ProductDetailDrawer — mobile: all canonical sections present (no tab-hiding)', () => {
  it('renders all 8 canonical section headings simultaneously, not behind a tab switcher', () => {
    renderDrawer();
    const expectedTabLabels = [
      'detailDrawer.tabGeneral',
      'detailDrawer.tabPricing',
      'detailDrawer.tabMargin',
      'detailDrawer.tabInventory',
      'detailDrawer.tabRecipe',
      'detailDrawer.tabChannels',
      'detailDrawer.tabOperations',
      'detailDrawer.tabHistory',
    ];
    for (const label of expectedTabLabels) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
  });

  it('does not render the desktop tab-switcher chrome on mobile', () => {
    renderDrawer();
    // The desktop <Tabs> component renders tablist/tab roles; the mobile branch
    // renders plain section headings instead.
    expect(screen.queryByRole('tablist')).toBeNull();
  });

  it('renders canonical field content inside the stacked sections (e.g. SKU, category)', () => {
    renderDrawer();
    expect(screen.getAllByText('SKU-1').length).toBeGreaterThan(0);
    expect(screen.getByText('Beverages')).toBeInTheDocument();
  });
});
