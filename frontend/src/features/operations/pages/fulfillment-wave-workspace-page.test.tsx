import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
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
    t: (sel: unknown, opts?: { defaultValue?: string }) => {
      if (typeof sel === 'function') {
        const path = String((sel as (p: unknown) => unknown)(pathProxy('')));
        return path;
      }
      return opts?.defaultValue ?? String(sel);
    },
  }),
}));

vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
}));

const { mockUseIsMobile } = vi.hoisted(() => ({ mockUseIsMobile: vi.fn() }));
vi.mock('@/hooks/use-is-mobile', () => ({ useIsMobile: mockUseIsMobile }));

const { mockUseSelectedWaveId } = vi.hoisted(() => ({ mockUseSelectedWaveId: vi.fn(() => 'wave-1' as string | null) }));
vi.mock('../components/wave-picker', () => ({ useSelectedWaveId: mockUseSelectedWaveId }));

const {
  mockPreparationWave,
  mockWaveKpis,
  mockProductDemand,
  mockMaterialDemand,
  mockMissingMaterials,
  mockManufacturingDemand,
  mockWaveOrders,
} = vi.hoisted(() => ({
  mockPreparationWave: vi.fn(),
  mockWaveKpis: vi.fn(),
  mockProductDemand: vi.fn(),
  mockMaterialDemand: vi.fn(),
  mockMissingMaterials: vi.fn(),
  mockManufacturingDemand: vi.fn(),
  mockWaveOrders: vi.fn(),
}));
vi.mock('../hooks/use-preparation', () => ({
  usePreparationWave: mockPreparationWave,
  useWaveKpis: mockWaveKpis,
  useWaveProductDemand: mockProductDemand,
  useWaveMaterialDemand: mockMaterialDemand,
  useWaveMissingMaterials: mockMissingMaterials,
  useWaveManufacturingDemand: mockManufacturingDemand,
  useWaveOrders: mockWaveOrders,
}));

import { FulfillmentWaveWorkspacePage } from './fulfillment-wave-workspace-page';

const BASE_WAVE = {
  id: 'wave-1',
  wave_number: 'PREP-202609-000001',
  status: 'preparing',
  shortage_detected: false,
  updated_at: '2026-09-01T10:00:00Z',
};

const BASE_KPIS = {
  products_count: 2,
  materials_count: 3,
  missing_materials_count: 0,
  completion_pct: 50,
  prepared_count: 1,
  remaining_count: 1,
};

function setHappyPath(overrides: { wave?: object; kpis?: object } = {}) {
  mockPreparationWave.mockReturnValue({ data: { ...BASE_WAVE, ...overrides.wave }, isLoading: false });
  mockWaveKpis.mockReturnValue({ data: { ...BASE_KPIS, ...overrides.kpis }, isFetching: false });
  mockProductDemand.mockReturnValue({
    data: [
      { id: 'p1', product_name: 'Widget', product_sku: 'SKU-1', required_qty: 10, prepared_qty: 5, remaining_qty: 5, completion_pct: 50 },
    ],
  });
  mockMaterialDemand.mockReturnValue({ data: [] });
  mockMissingMaterials.mockReturnValue({ data: [] });
  mockManufacturingDemand.mockReturnValue({ data: [] });
  mockWaveOrders.mockReturnValue({ data: [] });
}

beforeEach(() => {
  vi.clearAllMocks();
  mockUseIsMobile.mockReturnValue(false);
  mockUseSelectedWaveId.mockReturnValue('wave-1');
});

describe('FulfillmentWaveWorkspacePage — resolution states', () => {
  it('shows the no-active-wave state when nothing is selected', () => {
    mockUseSelectedWaveId.mockReturnValue(null);
    mockPreparationWave.mockReturnValue({ data: undefined, isLoading: false });
    mockWaveKpis.mockReturnValue({ data: undefined, isFetching: false });
    mockProductDemand.mockReturnValue({ data: undefined });
    mockMaterialDemand.mockReturnValue({ data: undefined });
    mockMissingMaterials.mockReturnValue({ data: undefined });
    mockManufacturingDemand.mockReturnValue({ data: undefined });
    mockWaveOrders.mockReturnValue({ data: undefined });

    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.getByText('wave.noWaveSelected.title')).toBeInTheDocument();
  });

  it('shows the wave-not-found state when the wave failed to resolve', () => {
    mockPreparationWave.mockReturnValue({ data: undefined, isLoading: false });
    mockWaveKpis.mockReturnValue({ data: undefined, isFetching: false });
    mockProductDemand.mockReturnValue({ data: undefined });
    mockMaterialDemand.mockReturnValue({ data: undefined });
    mockMissingMaterials.mockReturnValue({ data: undefined });
    mockManufacturingDemand.mockReturnValue({ data: undefined });
    mockWaveOrders.mockReturnValue({ data: undefined });

    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.getByText('wave.waveNotFound')).toBeInTheDocument();
  });
});

describe('FulfillmentWaveWorkspacePage — active wave (desktop)', () => {
  it('renders the live, quantity-weighted completion percentage and KPI counts', () => {
    setHappyPath();
    render(<FulfillmentWaveWorkspacePage />);

    // Two independent renders of the same completion_pct (progress bar + mfg progress).
    expect(screen.getAllByText('50.0%').length).toBeGreaterThan(0);
    expect(screen.getByText('3')).toBeInTheDocument(); // materialsCount KPI card
  });

  it('renders the shortage badge only when the wave actually flags one', () => {
    setHappyPath({ wave: { shortage_detected: true } });
    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.getByText('wave.dashboard.statusBar.shortageDetected')).toBeInTheDocument();
  });

  it('does not render a shortage badge for a wave with no shortage', () => {
    setHappyPath({ wave: { shortage_detected: false } });
    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.queryByText('wave.dashboard.statusBar.shortageDetected')).not.toBeInTheDocument();
  });

  it('renders Product Demand as a table with required/prepared/remaining quantities', () => {
    setHappyPath();
    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.getByRole('table')).toBeInTheDocument();
    expect(screen.getByText('Widget')).toBeInTheDocument();
    expect(screen.getByText('10')).toBeInTheDocument();
    expect(screen.getAllByText('5').length).toBeGreaterThan(0); // prepared and remaining both = 5
  });

  it('never renders a Missing Materials section when nothing is missing', () => {
    setHappyPath();
    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.queryByText('wave.dashboard.sections.missingMaterials')).not.toBeInTheDocument();
  });

  it('renders Missing Materials only when the canonical data actually reports some', () => {
    setHappyPath();
    mockMissingMaterials.mockReturnValue({
      data: [{ id: 'm1', material_name: 'Flour', missing_qty: 4, affected_orders_count: 2 }],
    });
    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.getByText('wave.dashboard.sections.missingMaterials')).toBeInTheDocument();
    expect(screen.getByText('Flour')).toBeInTheDocument();
  });
});

describe('FulfillmentWaveWorkspacePage — active wave (mobile)', () => {
  beforeEach(() => mockUseIsMobile.mockReturnValue(true));

  it('renders Product Demand as a card list instead of a table, with no data loss', () => {
    setHappyPath();
    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.getByText('Widget')).toBeInTheDocument();
    expect(screen.getByText('SKU-1')).toBeInTheDocument();
    expect(screen.getByText('10')).toBeInTheDocument();
  });

  it('renders Missing Materials as cards on mobile when present', () => {
    setHappyPath();
    mockMissingMaterials.mockReturnValue({
      data: [{ id: 'm1', material_name: 'Flour', missing_qty: 4, affected_orders_count: 2 }],
    });
    render(<FulfillmentWaveWorkspacePage />);
    expect(screen.getByText('Flour')).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument();
  });
});
