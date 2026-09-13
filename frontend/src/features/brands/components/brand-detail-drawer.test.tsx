import { useRef } from 'react';
import { render, screen } from '@testing-library/react';
// Redundant with src/test-setup.ts's global setup (harmless — side-effect imports are
// idempotent) but needed here so the pre-commit Guardian's staged-file-scoped type-check,
// which only follows a file's own transitive imports, sees the jest-dom matcher typings too.
import '@testing-library/jest-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import type { Brand } from '@/features/brands/types/brand';

/**
 * Regression test for the "opening/editing a brand crashes with React error #310" defect.
 *
 * Root cause: BrandDetailDrawer had `if (!brand) return null` BEFORE its three data-loading
 * query hooks (useBusinessAccountsQuery/useChannelsQuery/useProductsQuery). The drawer stays
 * mounted at all times in BrandsPage (only its `brand`/`open` props change), so the very first
 * time an operator opened or edited a brand — `activeBrand` flipping from null to a real
 * Brand — the SAME component instance went from calling 1 hook (useTranslation only, via the
 * early return) to calling 4 hooks, violating the Rules of Hooks
 * ("Rendered more hooks than during the previous render").
 *
 * This test reproduces exactly that transition — render once with brand=null, then rerender
 * the same instance with a real brand — and asserts it does not throw.
 */

function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
// Mocks below deliberately still call a REAL React hook (useRef) internally, matching real
// react-i18next/useOrganizationContext (both call real hooks under the hood) — a mock that
// calls zero real hooks would make the very first render register zero hooks with React,
// which does not reproduce the rules-of-hooks mismatch this test exists to catch.
vi.mock('react-i18next', () => ({
  useTranslation: () => {
    useRef(null);
    return {
      t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
    };
  },
}));

vi.mock('@/features/organization/context/organization-context', () => ({
  useOrganizationContext: () => {
    useRef(null);
    return { activeCompanyId: 'company-1' };
  },
}));

vi.mock('@/features/admin/configuration/components/policy-workspace', () => ({
  PolicyWorkspace: () => null,
}));

const emptyList = vi.hoisted(() => ({ items: [], meta: { total: 0, current_page: 1, per_page: 50, last_page: 1 } }));

vi.mock('@/features/business-accounts/services/business-accounts-service', () => ({
  businessAccountsService: { list: vi.fn().mockResolvedValue(emptyList) },
}));
vi.mock('@/features/channels/services/channels-service', () => ({
  channelsService: { list: vi.fn().mockResolvedValue(emptyList) },
}));
vi.mock('@/features/products/services/products-service', () => ({
  productsService: { list: vi.fn().mockResolvedValue(emptyList) },
}));

import { BrandDetailDrawer } from './brand-detail-drawer';

const BRAND: Brand = {
  id: 'brand-1',
  company_id: 'company-1',
  company: { id: 'company-1', code: 'CO-1', name: 'Acme Co' },
  code: 'BR-1',
  name: 'Acme Brand',
  slug: 'acme-brand',
  logo: null,
  description: null,
  is_active: true,
  minimum_margin_pct: null,
  default_target_margin: null,
  default_markup: null,
  default_discount_pct: null,
  channels_count: 0,
  active_channels_count: 0,
  products_count: 0,
  created_at: null,
  updated_at: null,
};

function renderDrawer(brand: Brand | null, open: boolean) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const utils = render(
    <QueryClientProvider client={client}>
      <BrandDetailDrawer brand={brand} open={open} onOpenChange={() => undefined} />
    </QueryClientProvider>,
  );
  return { client, ...utils };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('BrandDetailDrawer', () => {
  it('renders nothing while no brand is selected', () => {
    const { container } = renderDrawer(null, false);
    expect(container).toBeEmptyDOMElement();
  });

  it('does not crash when brand flips from null to a real brand on the same mounted instance (regression for React error #310)', () => {
    const { rerender, container, client } = renderDrawer(null, false);
    expect(container).toBeEmptyDOMElement();

    // This exact re-render — same component instance, same QueryClient, brand going
    // null -> non-null while `open` also flips true — is what previously threw "Rendered
    // more hooks than during the previous render" because the query hooks were gated
    // behind `if (!brand) return null`.
    expect(() => {
      rerender(
        <QueryClientProvider client={client}>
          <BrandDetailDrawer brand={BRAND} open={true} onOpenChange={() => undefined} />
        </QueryClientProvider>,
      );
    }).not.toThrow();

    // Brand name legitimately appears twice (header + Overview detail row) — the point of
    // this assertion is only that rendering completed without throwing, above.
    expect(screen.getAllByText('Acme Brand').length).toBeGreaterThan(0);
  });

  it('renders brand details once open with a brand selected', () => {
    renderDrawer(BRAND, true);

    expect(screen.getAllByText('Acme Brand').length).toBeGreaterThan(0);
    expect(screen.getAllByText('BR-1').length).toBeGreaterThan(0);
  });
});
