import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

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

const mockSetParams = vi.fn();
let mockParams = new URLSearchParams();
vi.mock('react-router-dom', () => ({
  useLocation: () => ({ pathname: '/operations/preparation/wave-workspace/products' }),
  useSearchParams: () => [mockParams, mockSetParams],
  Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
  Outlet: () => <div data-testid="outlet">OUTLET</div>,
}));

vi.mock('../components/wave-picker', () => ({ WavePicker: () => <div data-testid="wave-picker" /> }));

import {
  useCurrentWave,
  usePreparationWave,
  useWaveKpis,
  useAdvanceWave,
} from '../hooks/use-preparation';
vi.mock('../hooks/use-preparation', () => ({
  useCurrentWave: vi.fn(),
  usePreparationWave: vi.fn(),
  useWaveKpis: vi.fn(),
  useAdvanceWave: vi.fn(),
}));

import { WaveWorkspaceLayout } from './wave-workspace-layout';

const mockCurrent = useCurrentWave as unknown as ReturnType<typeof vi.fn>;
const mockWave = usePreparationWave as unknown as ReturnType<typeof vi.fn>;
const mockKpis = useWaveKpis as unknown as ReturnType<typeof vi.fn>;
const mockAdvance = useAdvanceWave as unknown as ReturnType<typeof vi.fn>;

function setCurrent(over: Partial<{ data: unknown; isLoading: boolean; isError: boolean }>) {
  mockCurrent.mockReturnValue({ data: undefined, isLoading: false, isError: false, refetch: vi.fn(), ...over });
}

beforeEach(() => {
  vi.clearAllMocks();
  mockParams = new URLSearchParams();
  mockWave.mockReturnValue({ data: undefined, isFetching: false });
  mockKpis.mockReturnValue({ data: undefined });
  mockAdvance.mockReturnValue({ isPending: false, mutate: vi.fn() });
});

describe('WaveWorkspaceLayout — current-wave resolution', () => {
  it('auto-opens the single active wave by setting wave_id (§3)', () => {
    setCurrent({ data: { active_count: 1, wave: { id: 'w1' }, waves: [{ id: 'w1', wave_number: 'W-1' }] } });
    render(<WaveWorkspaceLayout />);
    // The one active wave is written to the URL, and the body shows the resolving state
    // until the id lands (no stale pick, no empty flash).
    expect(mockSetParams).toHaveBeenCalled();
    expect(screen.getByText('wave.workspace.resolvingWave')).toBeInTheDocument();
  });

  it('renders the tabs and the outlet when the URL wave is active', () => {
    mockParams = new URLSearchParams('wave_id=w1');
    setCurrent({ data: { active_count: 1, wave: { id: 'w1' }, waves: [{ id: 'w1', wave_number: 'W-1' }] } });
    mockWave.mockReturnValue({
      data: { wave_number: 'W-1', status: 'preparing', planning_date: '2026-08-29', orders_count: 3, products_count: 2, completion_pct: 40, total_units_required: 10, total_units_prepared: 4 },
      isFetching: false,
    });
    mockKpis.mockReturnValue({ data: { missing_materials_count: 0, products_count: 2, completion_pct: 40, total_units_required: 10, total_units_prepared: 4 } });
    render(<WaveWorkspaceLayout />);
    expect(screen.getByTestId('outlet')).toBeInTheDocument();
    expect(screen.getByText('wave.workspace.tabs.active')).toBeInTheDocument();
    expect(mockSetParams).not.toHaveBeenCalled();
    // §3 — the legacy "Select a wave…" control is never rendered in Today's Preparation.
    expect(screen.queryByTestId('wave-picker')).not.toBeInTheDocument();
  });

  it('shows an explicit No Active Preparation Wave state when none is active (§5)', () => {
    setCurrent({ data: { active_count: 0, wave: null, waves: [] } });
    render(<WaveWorkspaceLayout />);
    expect(screen.getByText('wave.workspace.noActiveWave.title')).toBeInTheDocument();
    expect(screen.queryByTestId('outlet')).not.toBeInTheDocument();
    // §3 — no legacy selector, even in the no-active-wave state.
    expect(screen.queryByTestId('wave-picker')).not.toBeInTheDocument();
  });

  it('surfaces the conflicting waves without silently picking one when several are active (§6)', () => {
    setCurrent({
      data: {
        active_count: 2,
        wave: null,
        waves: [{ id: 'w1', wave_number: 'W-1' }, { id: 'w2', wave_number: 'W-2' }],
      },
    });
    render(<WaveWorkspaceLayout />);
    expect(screen.getByText('wave.workspace.multipleActiveWaves.title')).toBeInTheDocument();
    expect(screen.getByText('W-1')).toBeInTheDocument();
    expect(screen.getByText('W-2')).toBeInTheDocument();
    expect(mockSetParams).not.toHaveBeenCalled();
  });

  it('shows an error state (not an empty state) when the read fails (§26)', () => {
    setCurrent({ data: undefined, isError: true });
    render(<WaveWorkspaceLayout />);
    expect(screen.getByText('wave.workspace.loadError')).toBeInTheDocument();
    expect(screen.queryByText('wave.workspace.noActiveWave.title')).not.toBeInTheDocument();
  });
});
