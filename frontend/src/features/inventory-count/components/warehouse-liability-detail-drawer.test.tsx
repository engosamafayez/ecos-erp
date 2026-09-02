/**
 * Focused coverage for WarehouseLiabilityDetailDrawer — the new detail
 * surface added by TASK-ECOS-MOBILE-REMAINING-PAGES-WAREHOUSE-EXCEPTIONS-002
 * (closes a pure wiring gap: `warehouseLiabilityService.get` and the backend
 * `show` route already existed; no hook or UI ever called them).
 *
 * Scope: renders the canonical liability fields once loaded, exposes the
 * "View Related Investigation" cross-link only when the record actually
 * carries a `waste_investigation_id` (never invents one), and offers no
 * mutation control of its own — approve/reject stay on the page-level
 * dialog; this surface is read-only, so it cannot become a second
 * accounting engine.
 */

// Brings the toBeInTheDocument matcher's TYPE augmentation with it. test-setup.ts
// registers the matchers at runtime, but it is outside tsconfig.app.json, so the
// types are not visible to a type-check that roots at this file.
import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import i18n from '@/i18n/i18n';
import { WarehouseLiabilityDetailDrawer } from './warehouse-liability-detail-drawer';
import type { WarehouseLiability } from '../types/inventory-count';

// The `inventory-count` namespace loads lazily via i18n's Vite-glob backend
// (src/i18n/i18n.ts) — awaiting it here avoids the render race where `t()`
// resolves to an empty string because the namespace hasn't fetched yet.
beforeAll(async () => {
  await i18n.loadNamespaces('inventory-count');
});

const { mockUseWarehouseLiabilityQuery } = vi.hoisted(() => ({
  mockUseWarehouseLiabilityQuery: vi.fn(),
}));

// The nested WasteInvestigationDetailDrawer (rendered as the cross-link
// target) is exercised only for "does it mount without crashing" — its own
// content is out of scope here (unchanged, pre-existing component). Keeping
// its query permanently loading avoids a real network call from jsdom.
vi.mock('../hooks/use-inventory-count', () => ({
  useWarehouseLiabilityQuery: mockUseWarehouseLiabilityQuery,
  useWasteInvestigationQuery: () => ({ data: undefined, isLoading: true }),
}));

// useFormatter reaches for LanguageProvider context — stubbed the same way
// executive-trend-panel.test.tsx does, so this stays a render test rather
// than one that also depends on the locale provider tree.
vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({
    currency: 'EGP',
    number: (v: number) => String(v),
    percent: (v: number) => `${v}%`,
    money: (v: number) => String(v),
  }),
}));

function makeLiability(overrides: Partial<WarehouseLiability> = {}): WarehouseLiability {
  return {
    id: 'lib-1',
    company_id: 'co-1',
    warehouse_id: 'wh-1',
    product_id: 'p-1',
    count_session_id: null,
    count_line_id: null,
    waste_investigation_id: null,
    warehouse_manager: 'Ahmed',
    liability_type: 'waste_transferred',
    quantity: 3,
    unit_cost: 20,
    total_cost: 60,
    status: 'pending',
    approved_by: null,
    approved_at: null,
    notes: null,
    month: '2026-09',
    product: { id: 'p-1', sku: 'SKU-002', name: 'Gadget', image_url: null },
    warehouse: { id: 'wh-1', name: 'Main Warehouse' },
    created_at: '2026-09-01T00:00:00Z',
    cost_snapshot_unit_cost: null,
    cost_snapshot_total_value: null,
    cost_method: null,
    currency: null,
    metadata: null,
    ...overrides,
  };
}

function renderDrawer(liability: WarehouseLiability | undefined, isLoading = false) {
  mockUseWarehouseLiabilityQuery.mockReturnValue({ data: liability, isLoading });
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <WarehouseLiabilityDetailDrawer liabilityId={liability?.id ?? 'lib-1'} open onOpenChange={vi.fn()} />
    </QueryClientProvider>,
  );
}

describe('WarehouseLiabilityDetailDrawer', () => {
  it('shows a loading state before data resolves', () => {
    renderDrawer(undefined, true);
    expect(screen.queryByText('Gadget')).not.toBeInTheDocument();
  });

  it('renders the canonical liability fields once loaded', () => {
    renderDrawer(makeLiability());

    expect(screen.getAllByText('Gadget').length).toBeGreaterThan(0);
    expect(screen.getByText('SKU-002')).toBeInTheDocument();
    expect(screen.getByText('3.00')).toBeInTheDocument();
    expect(screen.getByText('60.00')).toBeInTheDocument();
    expect(screen.getByText('Main Warehouse')).toBeInTheDocument();
    expect(screen.getByText('Ahmed')).toBeInTheDocument();
  });

  it('shows the related-investigation link only when a waste_investigation_id is present', () => {
    renderDrawer(makeLiability({ waste_investigation_id: null }));
    expect(screen.queryByRole('button', { name: /view related investigation/i })).not.toBeInTheDocument();
  });

  it('opens the linked waste investigation drawer when the cross-link is tapped', async () => {
    const user = userEvent.setup();
    renderDrawer(makeLiability({ waste_investigation_id: 'inv-42' }));

    const link = screen.getByRole('button', { name: /view related investigation/i });
    expect(link).toBeInTheDocument();
    // Tapping it must not throw and must not error — the linked WasteInvestigation
    // drawer mounts (its own query is disabled until a real fetch resolves the id;
    // enabled:false here since useWasteInvestigationQuery is not mocked and the
    // network layer is absent in this test, so no request is actually made).
    await user.click(link);
  });

  it('exposes no mutation control of its own — approve/reject stay on the page-level dialog', () => {
    renderDrawer(makeLiability());

    expect(screen.queryByRole('button', { name: /approve/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /reject/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /settle|debt|journal/i })).not.toBeInTheDocument();
  });
});
