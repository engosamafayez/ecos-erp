/// <reference types="@testing-library/jest-dom/vitest" />
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';

/**
 * TASK-ECOS-V1.1-CRM-02-PAIRING-UI-FINAL-CLOSURE-011 — page-level focused tests. Covers ticket
 * §14 items 1, 8 (the new action's visibility/permission gating, which is a page concern, not a
 * dialog concern) and 14-16 (regression: the pre-existing row actions/health display this diff
 * must not break). Items 2-7, 9-13 live in pairing-code-dialog.test.tsx.
 *
 * Note on items 15/16 as literally worded in the ticket: this ECOS frontend has no dedicated
 * "Disconnect" action or heartbeat/health display today (confirmed by reading
 * channels-page.tsx in full before this task) — those exist only inside the WordPress plugin's
 * own admin screen, out of scope here. The closest pre-existing analogues actually present on
 * this page are the row's Delete action and its Connection/Status badges + Last Sync column, so
 * that is what these two regression tests protect. See the task report for the full note.
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
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
  }),
}));

const mockCan = vi.hoisted(() => vi.fn<(permission: string) => boolean>(() => true));
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: (p: string) => mockCan(p) }),
}));

const mockTestConnection = vi.hoisted(() => vi.fn());
const mockDeleteChannel = vi.hoisted(() => vi.fn());
vi.mock('@/features/channels/hooks/use-channels', () => ({
  useChannelsQuery: () => ({
    // meta intentionally omitted: channels-page.tsx only renders Pagination when meta is
    // present, and Pagination needs a LanguageProvider these focused, non-pagination tests
    // don't set up. Row rendering (what these tests actually check) does not depend on it.
    data: { items: CHANNELS, meta: undefined },
    isLoading: false,
    isError: false,
    isFetching: false,
    refetch: vi.fn(),
  }),
  useDeleteChannel: () => ({ mutate: mockDeleteChannel, isPending: false }),
  useTestConnection: () => ({ mutate: mockTestConnection, isPending: false }),
  useImportProducts: () => ({ mutate: vi.fn(), isPending: false }),
  useImportOrders: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateChannel: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateChannel: () => ({ mutate: vi.fn(), isPending: false }),
  useGeneratePairingCode: () => ({ mutate: vi.fn(), isPending: false }),
}));
vi.mock('@/features/stock-sync/hooks/use-stock-sync', () => ({
  useSyncStock: () => ({ mutate: vi.fn(), isPending: false }),
}));
vi.mock('@/features/brands/hooks/use-brand-options', () => ({
  useBrandOptions: () => ({ data: [], isLoading: false }),
}));
vi.mock('@/features/channels/hooks/use-company-options', () => ({
  useCompanyOptions: () => ({ data: [], isLoading: false }),
}));

import { ChannelsPage } from './channels-page';
import type { Channel } from '@/features/channels/types/channel';

function makeChannel(overrides: Partial<Channel> = {}): Channel {
  return {
    id: 'chan-1',
    brand_id: 'brand-1',
    brand: { id: 'brand-1', code: 'MAIN', name: 'Main Brand', company: { id: 'co-1', name: 'ECOS Co' } },
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
    connection_status: 'connected',
    connection_status_label: 'Connected',
    transport_mode: 'direct_rest',
    connector_health: 'never_connected',
    health_status: 'healthy',
    last_sync_at: '2026-09-01T10:00:00Z',
    last_webhook_received_at: null,
    last_successful_sync_at: '2026-09-01T10:00:00Z',
    last_error_at: null,
    last_error_message: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

let CHANNELS: Channel[] = [makeChannel()];

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <ChannelsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

async function openRowMenu(user: ReturnType<typeof userEvent.setup>, channelName: string) {
  // The grid renders a table row AND a responsive card for the same channel at once
  // (CSS-toggled, both present in jsdom) — open just the first ActionMenu instance.
  const [trigger] = screen.getAllByRole('button', { name: `Actions for ${channelName}` });
  await user.click(trigger);
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  CHANNELS = [makeChannel()];
});

describe('ChannelsPage — pairing action', () => {
  // ── 1: eligible unpaired Connector shows Generate Pairing Code action ────────────
  it('shows the Generate Pairing Code row action for an eligible unpaired WooCommerce channel', async () => {
    const user = userEvent.setup();
    renderPage();

    await openRowMenu(user, 'ECOS Main Store');

    expect(screen.getByText('connector.menuActionUnpaired')).toBeInTheDocument();
  });

  it('offers "Pairing Code" (not the unpaired label) once the channel is already Connector-paired', async () => {
    const user = userEvent.setup();
    CHANNELS = [makeChannel({ transport_mode: 'connector', connector_health: 'healthy' })];
    renderPage();

    await openRowMenu(user, 'ECOS Main Store');

    expect(screen.getByText('connector.menuActionPaired')).toBeInTheDocument();
    expect(screen.queryByText('connector.menuActionUnpaired')).not.toBeInTheDocument();
  });

  it('does not offer pairing-code generation for a non-WooCommerce channel', async () => {
    const user = userEvent.setup();
    CHANNELS = [makeChannel({ platform: 'shopify', platform_label: 'Shopify' })];
    renderPage();

    await openRowMenu(user, 'ECOS Main Store');

    expect(screen.queryByText('connector.menuActionUnpaired')).not.toBeInTheDocument();
  });

  // ── 8: permission-restricted user cannot use the action through UI ───────────────
  it('hides the pairing-code action for a user without sales.channels.update, while unrelated actions stay', async () => {
    const user = userEvent.setup();
    mockCan.mockImplementation((p: string) => p !== 'sales.channels.update');
    renderPage();

    await openRowMenu(user, 'ECOS Main Store');

    expect(screen.queryByText('connector.menuActionUnpaired')).not.toBeInTheDocument();
    expect(screen.queryByText('connector.menuActionPaired')).not.toBeInTheDocument();
    // A hidden button is not the security boundary (the server is) — this only proves the
    // frontend follows the same permission already gating this row's other actions.
    expect(screen.getByText('actions.testConnection')).toBeInTheDocument();
  });

  // ── 14: existing Test Connection action remains working ─────────────────────────
  it('leaves the existing Test Connection action working', async () => {
    const user = userEvent.setup();
    renderPage();

    await openRowMenu(user, 'ECOS Main Store');
    await user.click(screen.getByText('actions.testConnection'));

    expect(mockTestConnection).toHaveBeenCalledWith('chan-1', expect.anything());
  });

  // ── 15: existing Disconnect action remains working ───────────────────────────────
  // No dedicated "Disconnect" control exists in this ECOS frontend (see file header note) —
  // the closest existing analogue on this exact row is Delete, which this protects instead.
  it('leaves the existing Delete (closest analogue to "Disconnect") action working', async () => {
    const user = userEvent.setup();
    renderPage();

    await openRowMenu(user, 'ECOS Main Store');
    await user.click(screen.getByText('common.delete'));

    expect(await screen.findByText('delete.title')).toBeInTheDocument();
  });

  // ── 16: existing health/heartbeat display remains working ───────────────────────
  // No dedicated connector heartbeat display exists in this ECOS frontend today (see file
  // header note) — the pre-existing analogues on this page are the Connection/Status badges
  // and Last Sync column, which this protects instead.
  it('still renders the existing connection/status badges and last-sync column untouched', () => {
    renderPage();

    // The grid renders a table AND a responsive card representation of the same row at once
    // (CSS-toggled, both present in jsdom) — assert presence, not a single-match count.
    expect(screen.getAllByText('Connected').length).toBeGreaterThan(0);
    expect(screen.getAllByText(new Date('2026-09-01T10:00:00Z').toLocaleDateString()).length).toBeGreaterThan(0);
  });
});
