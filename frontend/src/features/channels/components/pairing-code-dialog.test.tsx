/// <reference types="@testing-library/jest-dom/vitest" />
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * TASK-ECOS-V1.1-CRM-02-PAIRING-UI-FINAL-CLOSURE-011 — focused tests for the Generate Pairing
 * Code dialog. Covers ticket §14 items 2-7, 9-13 (item 1 "eligible unpaired Connector shows
 * Generate Pairing Code action" and items 8, 14-16 are page-level — see channels-page.test.tsx).
 *
 * `react-i18next` is mocked (idiom shared with user-create-drawer.test.tsx and the CRM feature
 * tests): by default `t()` stubs to the dotted selector path so most assertions target stable
 * keys, not prose. For the two i18n-specific tests below, `state.mode` switches it to resolve
 * against the REAL en/ar channels.json + common.json content instead, so those two tests prove
 * the actual mandated EN/AR strings render — not just that some string rendered.
 */

import enChannels from '@/i18n/locales/en/channels.json';
import arChannels from '@/i18n/locales/ar/channels.json';
import enCommon from '@/i18n/locales/en/common.json';
import arCommon from '@/i18n/locales/ar/common.json';

const state = vi.hoisted(() => ({ mode: 'stub' as 'stub' | 'en' | 'ar' }));

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
  useTranslation: (ns: string) => ({
    t: (sel: unknown) => {
      if (typeof sel !== 'function') return String(sel);
      const fn = sel as (p: unknown) => unknown;
      if (state.mode === 'stub') return String(fn(pathProxy('')));
      const dict =
        ns === 'common'
          ? state.mode === 'ar'
            ? arCommon
            : enCommon
          : state.mode === 'ar'
            ? arChannels
            : enChannels;
      return String(fn(dict));
    },
  }),
}));

const mockGeneratePairingCode = vi.hoisted(() => vi.fn());
vi.mock('@/features/channels/services/channels-service', () => ({
  channelsService: { generatePairingCode: mockGeneratePairingCode },
}));

import { PairingCodeDialog } from './pairing-code-dialog';
import type { Channel } from '@/features/channels/types/channel';

function makeChannel(overrides: Partial<Channel> = {}): Channel {
  return {
    id: 'chan-1',
    brand_id: 'brand-1',
    brand: null,
    business_account_id: null,
    business_account: null,
    code: null,
    channel_type: null,
    channel_role: null,
    name: 'ECOS Main Store',
    platform: 'woocommerce',
    platform_label: 'WooCommerce',
    store_url: 'https://store.example.com',
    is_active: true,
    sync_products: true,
    sync_prices: true,
    sync_stock: true,
    sync_customers: true,
    sync_orders: true,
    orders_sync_watermark_at: null,
    orders_initial_import_policy: null,
    orders_initial_import_cutoff_at: null,
    orders_sync_activated_at: null,
    connection_status: 'disconnected',
    connection_status_label: 'Disconnected',
    transport_mode: 'direct_rest',
    connector_health: 'never_connected',
    health_status: 'healthy',
    last_sync_at: null,
    last_webhook_received_at: null,
    last_successful_sync_at: null,
    last_error_at: null,
    last_error_message: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function renderDialog(channel: Channel | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const onOpenChange = vi.fn();
  return {
    onOpenChange,
    ...render(
      <QueryClientProvider client={client}>
        <PairingCodeDialog open={channel !== null} onOpenChange={onOpenChange} channel={channel} />
      </QueryClientProvider>,
    ),
  };
}

const originalIsSecureContext = window.isSecureContext;

beforeEach(() => {
  vi.clearAllMocks();
  state.mode = 'stub';
  // jsdom defaults isSecureContext to false, which makes copyToClipboard() skip
  // navigator.clipboard entirely for the legacy execCommand path (see clipboard.test.ts).
  // navigator.clipboard itself is NOT stubbed here: userEvent.setup() unconditionally
  // installs its own working Clipboard stub on every call (attachClipboardStubToView) and
  // would silently overwrite anything set here — tests that need to assert on writeText spy
  // on the instance userEvent installs, AFTER calling userEvent.setup().
  Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true, writable: true });
});

afterEach(() => {
  state.mode = 'stub';
  Object.defineProperty(window, 'isSecureContext', { value: originalIsSecureContext, configurable: true, writable: true });
});

describe('PairingCodeDialog', () => {
  // ── 2: click calls the existing canonical pairing endpoint ──────────────────────
  it('calls channelsService.generatePairingCode for the current channel on explicit click', async () => {
    const user = userEvent.setup();
    mockGeneratePairingCode.mockResolvedValue({ pairing_code: 'ABCD12WXYZ', expires_at: null });
    renderDialog(makeChannel({ id: 'chan-42' }));

    await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));

    await waitFor(() => expect(mockGeneratePairingCode).toHaveBeenCalledWith('chan-42'));
    // Never auto-generated: the endpoint is not called until this explicit click.
  });

  it('does not call the endpoint merely by opening the dialog', () => {
    mockGeneratePairingCode.mockResolvedValue({ pairing_code: 'ABCD12WXYZ', expires_at: null });
    renderDialog(makeChannel());
    expect(mockGeneratePairingCode).not.toHaveBeenCalled();
  });

  // ── 3: returned code is displayed ────────────────────────────────────────────────
  it('displays the returned pairing code', async () => {
    const user = userEvent.setup();
    mockGeneratePairingCode.mockResolvedValue({ pairing_code: 'QWERTY99ZZ', expires_at: null });
    renderDialog(makeChannel());

    await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));

    expect(await screen.findByText('QWERTY99ZZ')).toBeInTheDocument();
  });

  // ── 4: Copy action copies the exact returned code ────────────────────────────────
  it('copies the exact returned code and shows a Copied confirmation', async () => {
    const user = userEvent.setup();
    const writeTextSpy = vi.spyOn(navigator.clipboard, 'writeText');
    mockGeneratePairingCode.mockResolvedValue({ pairing_code: 'COPYME1234', expires_at: null });
    renderDialog(makeChannel());

    await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));
    await screen.findByText('COPYME1234');

    await user.click(screen.getByRole('button', { name: 'connector.copy' }));

    expect(writeTextSpy).toHaveBeenCalledWith('COPYME1234');
    expect(await screen.findByText('connector.copied')).toBeInTheDocument();
  });

  // ── 5: expiry information renders when returned ──────────────────────────────────
  it('renders expiry information when the backend returns it', async () => {
    const user = userEvent.setup();
    const future = new Date(Date.now() + 15 * 60 * 1000).toISOString();
    mockGeneratePairingCode.mockResolvedValue({ pairing_code: 'EXPIRE1234', expires_at: future });
    renderDialog(makeChannel());

    await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));
    await screen.findByText('EXPIRE1234');

    expect(screen.getByText(/connector\.expires/)).toBeInTheDocument();
  });

  it('does not render expiry information when the backend omits it', async () => {
    const user = userEvent.setup();
    mockGeneratePairingCode.mockResolvedValue({ pairing_code: 'NOEXPIRY01', expires_at: null });
    renderDialog(makeChannel());

    await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));
    await screen.findByText('NOEXPIRY01');

    expect(screen.queryByText(/connector\.expires/)).not.toBeInTheDocument();
  });

  // ── 6: API error does not show a fake code ───────────────────────────────────────
  it('shows an honest error and no code at all when generation fails', async () => {
    const user = userEvent.setup();
    mockGeneratePairingCode.mockRejectedValue({
      isAxiosError: true,
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server-response text; the app displays server messages verbatim (see extractMessage), it does not translate them client-side
      response: { status: 422, data: { message: 'Channel is not eligible for pairing.' } },
    });
    renderDialog(makeChannel());

    await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));

    expect(await screen.findByText('Channel is not eligible for pairing.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'connector.copy' })).not.toBeInTheDocument();
    // No <code> element at all — not just an absent value, no code-display container either.
    expect(document.querySelector('code')).not.toBeInTheDocument();
  });

  // ── 7: already-paired Connector does not present generation as primary ──────────
  it('shows Connected (not a primary Generate action) once the channel is already paired', () => {
    renderDialog(makeChannel({ transport_mode: 'connector', connector_health: 'healthy' }));

    expect(screen.getByText('connector.connected')).toBeInTheDocument();
    // The action is offered as recovery/rotation ("Generate New Code"), never framed as the
    // primary "Generate Pairing Code" CTA used for a never-paired channel.
    expect(screen.getByRole('button', { name: 'connector.generateNewCode' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'connector.generateButton' })).not.toBeInTheDocument();
  });

  // ── 9: server rejection is handled honestly even if frontend state is stale ─────
  it('drops the previously-displayed code and shows the real error when a regenerate call fails', async () => {
    const user = userEvent.setup();
    mockGeneratePairingCode.mockResolvedValueOnce({ pairing_code: 'FIRSTCODE1', expires_at: null });
    renderDialog(makeChannel());

    await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));
    await screen.findByText('FIRSTCODE1');

    mockGeneratePairingCode.mockRejectedValueOnce({
      isAxiosError: true,
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server-response text; the app displays server messages verbatim (see extractMessage), it does not translate them client-side
      response: { status: 500, data: { message: 'Server error, please retry.' } },
    });
    await user.click(screen.getByRole('button', { name: 'connector.generateNewCode' }));

    expect(await screen.findByText('Server error, please retry.')).toBeInTheDocument();
    expect(screen.queryByText('FIRSTCODE1')).not.toBeInTheDocument();
  });

  // ── 10: generating another code replaces the displayed prior code ───────────────
  it('replaces the prior code with the new one on a successful regenerate', async () => {
    const user = userEvent.setup();
    mockGeneratePairingCode.mockResolvedValueOnce({ pairing_code: 'OLDCODE001', expires_at: null });
    renderDialog(makeChannel());

    await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));
    await screen.findByText('OLDCODE001');

    mockGeneratePairingCode.mockResolvedValueOnce({ pairing_code: 'NEWCODE002', expires_at: null });
    await user.click(screen.getByRole('button', { name: 'connector.generateNewCode' }));

    expect(await screen.findByText('NEWCODE002')).toBeInTheDocument();
    expect(screen.queryByText('OLDCODE001')).not.toBeInTheDocument();
  });

  // ── 11: Arabic strings render / 12: RTL layout remains usable ────────────────────
  // These three tests assert against the REAL mandated Arabic UI strings (not the stub
  // path-proxy used everywhere else in this file) — proving §12's exact required copy
  // actually renders, not just that some string rendered. That legitimately requires
  // Arabic literals inside the test file itself.
  /* eslint-disable ecos-i18n/no-arabic-literals -- asserting the real, mandated Arabic i18n strings render; see comment above */
  it('renders the real mandated Arabic strings', async () => {
    const user = userEvent.setup();
    state.mode = 'ar';
    mockGeneratePairingCode.mockResolvedValue({
      pairing_code: 'AR12345678',
      expires_at: new Date(Date.now() + 60_000).toISOString(),
    });
    renderDialog(makeChannel());

    expect(screen.getByRole('button', { name: 'إنشاء كود الربط' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'إنشاء كود الربط' }));

    await screen.findByText('AR12345678');
    expect(screen.getByText('كود الربط')).toBeInTheDocument();
    expect(screen.getByText(/ينتهي في/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'نسخ' }));
    expect(await screen.findByText('تم النسخ')).toBeInTheDocument();
  });

  it('renders "متصل" for an already-paired channel in Arabic', () => {
    state.mode = 'ar';
    renderDialog(makeChannel({ transport_mode: 'connector', connector_health: 'healthy' }));

    expect(screen.getByText('متصل')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'إنشاء كود جديد' })).toBeInTheDocument();
  });

  // ── 12: RTL layout remains usable ─────────────────────────────────────────────────
  it('remains fully usable (generate, copy, close all reachable) under RTL', async () => {
    const user = userEvent.setup();
    const writeTextSpy = vi.spyOn(navigator.clipboard, 'writeText');
    state.mode = 'ar';
    document.documentElement.dir = 'rtl';
    mockGeneratePairingCode.mockResolvedValue({ pairing_code: 'RTLCODE123', expires_at: null });

    try {
      renderDialog(makeChannel());

      await user.click(screen.getByRole('button', { name: 'إنشاء كود الربط' }));
      await screen.findByText('RTLCODE123');

      await user.click(screen.getByRole('button', { name: 'نسخ' }));
      expect(writeTextSpy).toHaveBeenCalledWith('RTLCODE123');

      expect(screen.getByRole('button', { name: 'إغلاق' })).toBeInTheDocument();
    } finally {
      document.documentElement.dir = 'ltr';
    }
  });
  /* eslint-enable ecos-i18n/no-arabic-literals */

  // ── 13: mobile structure does not require desktop width ──────────────────────────
  it('keeps the code display and every control reachable at a narrow (375px) viewport', async () => {
    const user = userEvent.setup();
    const originalWidth = window.innerWidth;
    Object.defineProperty(window, 'innerWidth', { value: 375, configurable: true });
    window.dispatchEvent(new Event('resize'));
    mockGeneratePairingCode.mockResolvedValue({ pairing_code: 'MOBILECODE', expires_at: null });

    try {
      renderDialog(makeChannel());

      await user.click(screen.getByRole('button', { name: 'connector.generateButton' }));
      const code = await screen.findByText('MOBILECODE');

      // The code box uses `truncate`/`min-w-0` precisely so a long code cannot force the
      // dialog wider than the viewport at 375px — assert the overflow-safe classes survive.
      expect(code.className).toContain('truncate');
      expect(screen.getByRole('button', { name: 'connector.copy' })).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'common.close' })).toBeInTheDocument();
    } finally {
      Object.defineProperty(window, 'innerWidth', { value: originalWidth, configurable: true });
    }
  });
});
