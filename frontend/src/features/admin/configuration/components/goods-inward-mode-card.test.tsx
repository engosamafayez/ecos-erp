import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { GoodsInwardModeSetting } from '../types/configuration';

/**
 * TASK-PROCUREMENT-GOODS-INWARD-CONFIGURATION-UI-001 — UI ↔ real API integration.
 *
 * The SERVICE layer is mocked, not the hooks: the real `useQuery`/`useMutation` pipeline from
 * `use-configuration.ts` runs, so what these tests assert is genuinely server state flowing
 * through React Query into the DOM, and a genuine mutation + cache invalidation flowing back.
 * Stubbing the hooks would prove only that the component renders its own props.
 *
 * This is the first test under features/admin/configuration; the idiom follows
 * features/inventory-count/components/new-count-dialog.test.tsx, the repo's only existing
 * React-Query + mocked-service test.
 */

const mockGet = vi.hoisted(() => vi.fn());
const mockUpdate = vi.hoisted(() => vi.fn());
const mockCan = vi.hoisted(() => vi.fn());
const mockToast = vi.hoisted(() => vi.fn());

vi.mock('../services/configuration-service', () => ({
  configurationService: {
    getGoodsInwardMode: mockGet,
    updateGoodsInwardMode: mockUpdate,
  },
}));

vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: mockCan }),
}));

vi.mock('@/components/ds/use-toast', () => ({
  useToast: () => ({ toast: mockToast }),
}));

vi.mock('react-i18next', () => ({
  // Selector-mode t($ => $.a.b) — return the leaf key so assertions stay readable.
  useTranslation: () => ({
    t: (sel: unknown) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, {
        get: (_t, prop: string) => { path.push(prop); return proxy; },
      });
      (sel as (p: unknown) => unknown)(proxy);
      return path[path.length - 1] ?? '';
    },
  }),
}));

import { GoodsInwardModeCard } from './goods-inward-mode-card';

/*
 * These label strings are API RESPONSE FIXTURES, not UI copy — they reproduce the `label`
 * field the backend returns so the fixture matches the real contract. The component never
 * renders them (all visible text comes from the i18n catalogue), so they are not translatable
 * strings and the i18n rule does not apply.
 */
/* eslint-disable ecos-i18n/no-hardcoded-ui-strings */
const RECEIPT_DEFAULT: GoodsInwardModeSetting = {
  mode: 'goods_receipt',
  label: 'Goods Receipt',
  is_default: true,
  default_mode: 'goods_receipt',
  options: [
    { value: 'goods_receipt', label: 'Goods Receipt' },
    { value: 'supplier_invoice', label: 'Supplier Invoice (Mode 3)' },
  ],
};

const INVOICE_EXPLICIT: GoodsInwardModeSetting = {
  ...RECEIPT_DEFAULT,
  mode: 'supplier_invoice',
  label: 'Supplier Invoice (Mode 3)',
  is_default: false,
};

/* eslint-enable ecos-i18n/no-hardcoded-ui-strings */

function renderCard() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return {
    client,
    ...render(
      <QueryClientProvider client={client}>
        <GoodsInwardModeCard />
      </QueryClientProvider>,
    ),
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockGet.mockResolvedValue(RECEIPT_DEFAULT);
  mockUpdate.mockResolvedValue(INVOICE_EXPLICIT);
});

describe('GoodsInwardModeCard', () => {
  // ── 1 / 2 — real server value renders ──────────────────────────────────────

  it('renders the mode returned by the server, with the backend-owned default marker', async () => {
    renderCard();

    expect(await screen.findByTestId('goods-inward-mode-card')).toBeInTheDocument();
    expect(mockGet).toHaveBeenCalledTimes(1);

    expect(screen.getByTestId('goods-inward-option-goods_receipt')).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByTestId('goods-inward-option-supplier_invoice')).toHaveAttribute('aria-checked', 'false');

    // The Default badge is driven by the server's is_default, never computed here.
    expect(screen.getByTestId('goods-inward-default-badge')).toBeInTheDocument();
  });

  // ── 3 — the other mode renders selected when the server says so ────────────

  it('renders supplier_invoice as selected when that is the server value', async () => {
    mockGet.mockResolvedValue(INVOICE_EXPLICIT);
    renderCard();

    await waitFor(() => {
      expect(screen.getByTestId('goods-inward-option-supplier_invoice')).toHaveAttribute('aria-checked', 'true');
    });
    expect(screen.getByTestId('goods-inward-option-goods_receipt')).toHaveAttribute('aria-checked', 'false');
    expect(screen.queryByTestId('goods-inward-default-badge')).not.toBeInTheDocument();
  });

  // ── 7 — loading ────────────────────────────────────────────────────────────

  it('shows a loading state before the server responds', () => {
    let resolve!: (v: GoodsInwardModeSetting) => void;
    mockGet.mockReturnValue(new Promise<GoodsInwardModeSetting>(r => { resolve = r; }));

    renderCard();

    expect(screen.queryByTestId('goods-inward-mode-card')).not.toBeInTheDocument();
    resolve(RECEIPT_DEFAULT);
  });

  // ── 9 — error ──────────────────────────────────────────────────────────────

  it('shows an error state and does not render options when the fetch fails', async () => {
    mockGet.mockRejectedValue(new Error('boom'));
    renderCard();

    expect(await screen.findByText('loadError')).toBeInTheDocument();
    expect(screen.queryByTestId('goods-inward-option-goods_receipt')).not.toBeInTheDocument();
  });

  // ── 4 / 5 — confirmation is required, and Cancel does not mutate ───────────

  it('asks for confirmation before saving, and cancelling mutates nothing', async () => {
    const user = userEvent.setup();
    renderCard();

    await screen.findByTestId('goods-inward-mode-card');
    await user.click(screen.getByTestId('goods-inward-option-supplier_invoice'));

    expect(await screen.findByText('confirmTitle')).toBeInTheDocument();
    expect(mockUpdate).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: /cancel/i }));

    await waitFor(() => expect(screen.queryByText('confirmTitle')).not.toBeInTheDocument());
    expect(mockUpdate).not.toHaveBeenCalled();
    // Still showing the server's value — nothing was optimistically changed.
    expect(screen.getByTestId('goods-inward-option-goods_receipt')).toHaveAttribute('aria-checked', 'true');
  });

  // ── 6 / 11 — confirm calls the real mutation and refetches ─────────────────

  it('confirming calls the real API and refetches the server value', async () => {
    const user = userEvent.setup();
    renderCard();

    await screen.findByTestId('goods-inward-mode-card');
    await user.click(screen.getByTestId('goods-inward-option-supplier_invoice'));
    await screen.findByText('confirmTitle');

    // The server now reports the new mode — the refetch must pick this up.
    mockGet.mockResolvedValue(INVOICE_EXPLICIT);

    await user.click(screen.getByText('confirmAction'));

    await waitFor(() => {
      expect(mockUpdate).toHaveBeenCalledWith({ mode: 'supplier_invoice', reason: undefined });
    });

    // Query invalidation → refetch → the render reflects the SERVER, not a local guess.
    await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2));
    await waitFor(() => {
      expect(screen.getByTestId('goods-inward-option-supplier_invoice')).toHaveAttribute('aria-checked', 'true');
    });

    expect(mockToast).toHaveBeenCalledWith(expect.objectContaining({ type: 'success' }));
  });

  it('sends the reason when one is given', async () => {
    const user = userEvent.setup();
    renderCard();

    await screen.findByTestId('goods-inward-mode-card');
    await user.type(screen.getByLabelText('reasonLabel'), 'Pilot rollout');
    await user.click(screen.getByTestId('goods-inward-option-supplier_invoice'));
    await screen.findByText('confirmTitle');
    await user.click(screen.getByText('confirmAction'));

    await waitFor(() => {
      expect(mockUpdate).toHaveBeenCalledWith({ mode: 'supplier_invoice', reason: 'Pilot rollout' });
    });
  });

  // ── 8 — saving state disables the controls ────────────────────────────────

  it('disables the options while the save is in flight, preventing duplicate submissions', async () => {
    const user = userEvent.setup();
    let resolve!: (v: GoodsInwardModeSetting) => void;
    mockUpdate.mockReturnValue(new Promise<GoodsInwardModeSetting>(r => { resolve = r; }));

    renderCard();
    await screen.findByTestId('goods-inward-mode-card');
    await user.click(screen.getByTestId('goods-inward-option-supplier_invoice'));
    await screen.findByText('confirmTitle');
    await user.click(screen.getByText('confirmAction'));

    await waitFor(() => {
      expect(screen.getByTestId('goods-inward-option-goods_receipt')).toBeDisabled();
      expect(screen.getByTestId('goods-inward-option-supplier_invoice')).toBeDisabled();
    });

    expect(mockUpdate).toHaveBeenCalledTimes(1);
    resolve(INVOICE_EXPLICIT);
  });

  // ── 9 — a failed save is surfaced, not swallowed ──────────────────────────

  it('reports a failed save and keeps showing the server value', async () => {
    const user = userEvent.setup();
    mockUpdate.mockRejectedValue(new Error('rejected'));

    renderCard();
    await screen.findByTestId('goods-inward-mode-card');
    await user.click(screen.getByTestId('goods-inward-option-supplier_invoice'));
    await screen.findByText('confirmTitle');
    await user.click(screen.getByText('confirmAction'));

    await waitFor(() => {
      expect(mockToast).toHaveBeenCalledWith(expect.objectContaining({ type: 'error' }));
    });
    expect(screen.getByTestId('goods-inward-option-goods_receipt')).toHaveAttribute('aria-checked', 'true');
  });

  // ── 10 — permission denied ────────────────────────────────────────────────

  it('renders read-only and cannot mutate without the configuration permission', async () => {
    const user = userEvent.setup();
    mockCan.mockReturnValue(false);

    renderCard();
    await screen.findByTestId('goods-inward-mode-card');

    expect(screen.getByTestId('goods-inward-readonly')).toBeInTheDocument();
    expect(screen.getByTestId('goods-inward-option-supplier_invoice')).toBeDisabled();

    await user.click(screen.getByTestId('goods-inward-option-supplier_invoice')).catch(() => {});

    expect(screen.queryByText('confirmTitle')).not.toBeInTheDocument();
    expect(mockUpdate).not.toHaveBeenCalled();
  });

  // ── 13 — mobile layout safety ─────────────────────────────────────────────

  /**
   * `test-setup.ts` stubs matchMedia to always report `matches: false`, so a viewport-driven
   * assertion would silently take the desktop branch and prove nothing. The layout is instead
   * responsive by CSS alone — a column that becomes a row at `sm` — so what is asserted here is
   * that the stacking classes are present and that no fixed width can force overflow.
   */
  it('stacks the options on small screens and sets no fixed widths', async () => {
    renderCard();
    await screen.findByTestId('goods-inward-mode-card');

    const container = screen.getByTestId('goods-inward-option-goods_receipt').parentElement;
    expect(container?.className).toContain('flex-col');
    expect(container?.className).toContain('sm:flex-row');
    expect(container?.className ?? '').not.toMatch(/\bw-\[\d/);
  });
});
