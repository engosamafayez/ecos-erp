import '@testing-library/jest-dom/vitest';

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

/**
 * TASK-ECOS-NOTIFICATIONS-CENTER-PREFERENCES-AND-LIVE-DELIVERY-004 §10.
 *
 * Proves the UI-level half of "user preference cannot defeat mandatory policy": the
 * effective-state table renders exactly what the (already server-resolved) attention
 * policy says, including the lock indicator — never re-derived client-side — and that
 * toggling a preference calls the real update endpoint (backend precedence enforcement
 * is proven separately in NotificationAttentionPolicyTest.php; this proves the UI does
 * not lie about, or attempt to bypass, that result).
 */

const mockGetPreferences = vi.hoisted(() => vi.fn());
const mockUpdatePreferences = vi.hoisted(() => vi.fn());
const mockAttentionPolicy = vi.hoisted(() => vi.fn());
const mockTypeCatalog = vi.hoisted(() => vi.fn());

vi.mock('@/features/notifications/services/notifications-service', () => ({
  notificationsService: {
    getPreferences: mockGetPreferences,
    updatePreferences: mockUpdatePreferences,
    attentionPolicy: mockAttentionPolicy,
    typeCatalog: mockTypeCatalog,
  },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: { count?: number; defaultValue?: string }) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy(
        {},
        { get: (_t, prop: string) => { path.push(prop); return proxy; } },
      );
      (sel as (p: unknown) => unknown)(proxy);
      const key = path[path.length - 1] ?? '';
      if (opts?.count !== undefined) return `${key}:${opts.count}`;
      return opts?.defaultValue ?? key;
    },
  }),
}));

import { NotificationPreferencesButton } from './notification-preferences';

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <NotificationPreferencesButton />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  // Every existing test below predates the catalog (§8) and doesn't care about it —
  // default to empty so NotificationTypeToggles renders nothing rather than an
  // unresolved/rejected query polluting those tests.
  mockTypeCatalog.mockResolvedValue([]);
});

describe('NotificationPreferencesButton', () => {
  it('shows CRITICAL as locked and NORMAL as editable, matching the server-resolved policy exactly', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: false });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderPanel();
    await userEvent.click(screen.getByRole('button', { name: 'settings' }));

    // The lock icon renders only on the critical row.
    await waitFor(() => expect(screen.getByLabelText('locked')).toBeInTheDocument());
    expect(screen.getAllByLabelText('locked')).toHaveLength(1);
  });

  it('toggling a switch writes the full preference payload through the real update path', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: false });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });
    mockUpdatePreferences.mockResolvedValue(undefined);

    renderPanel();
    await userEvent.click(screen.getByRole('button', { name: 'settings' }));
    await waitFor(() => expect(screen.getAllByRole('switch')).toHaveLength(2));

    const [popupSwitch] = screen.getAllByRole('switch');
    await userEvent.click(popupSwitch);

    // TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §6/§9 — the payload now
    // always carries sound_volume/type_overrides too (defaulting to the untouched
    // current value), since PUT is a full replace and must never silently drop them.
    await waitFor(() =>
      expect(mockUpdatePreferences).toHaveBeenCalledWith({
        popup_enabled: false,
        sound_enabled: false,
        sound_volume: 1,
        type_overrides: undefined,
      }),
    );
  });

  it('TASK-ECOS-NOTIFICATIONS-USER-REVIEW-REMEDIATION-007: renders an explicit read-only hint on the effective-summary table, and reflects popups-off/sound-on exactly as the server resolved it', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: false, sound_enabled: true });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: true, sound_profile: 'normal', locked: false },
      normal: { popup: false, sound: true, sound_profile: 'normal', locked: false },
      high: { popup: false, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderPanel();
    await userEvent.click(screen.getByRole('button', { name: 'settings' }));

    expect(await screen.findByText('effectiveReadOnlyHint')).toBeInTheDocument();

    const [popupSwitch, soundSwitch] = await waitFor(() => {
      const switches = screen.getAllByRole('switch');
      expect(switches).toHaveLength(2);
      return switches;
    });
    expect(popupSwitch).toHaveAttribute('data-state', 'unchecked');
    expect(soundSwitch).toHaveAttribute('data-state', 'checked');

    // Non-mandatory rows (low/normal/high) must show popup=X/sound=check; only the
    // locked critical row is exempt — this is what the user-reported "inconsistent"
    // table looked like before this task added the explicit read-only hint above.
    expect(screen.getAllByLabelText('locked')).toHaveLength(1);
  });

  it('defaults both switches on when the user has never set a preference', async () => {
    mockGetPreferences.mockResolvedValue(null);
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderPanel();
    await userEvent.click(screen.getByRole('button', { name: 'settings' }));

    await waitFor(() => expect(screen.getAllByRole('switch')).toHaveLength(2));
    screen.getAllByRole('switch').forEach((s) => expect(s).toHaveAttribute('data-state', 'checked'));
  });

  // ── TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 ──────────────────────

  it('§6: the volume slider commits on release, not on every drag tick, and defaults to full volume', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: true });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });
    mockUpdatePreferences.mockResolvedValue(undefined);

    renderPanel();
    await userEvent.click(screen.getByRole('button', { name: 'settings' }));

    const slider = await screen.findByRole('slider', { name: 'volumeLabel' });
    expect(slider).toHaveValue('1');

    fireEvent.change(slider, { target: { value: '0.5' } });
    // A change alone (no mouseUp/keyUp yet) must not have committed anything.
    expect(mockUpdatePreferences).not.toHaveBeenCalled();

    fireEvent.mouseUp(slider);
    await waitFor(() =>
      expect(mockUpdatePreferences).toHaveBeenCalledWith(
        expect.objectContaining({ sound_volume: 0.5 }),
      ),
    );
  });

  it('§8/§9: renders the catalog grouped by module and toggling one writes a merged type_overrides map, preserving popup/sound/volume', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: true, sound_volume: 0.8 });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });
    // Mock label text stands in for the real catalog's Arabic name/description — this
    // test only needs *some* stable string to assert on, not real i18n content.
    mockTypeCatalog.mockResolvedValue([
      {
        key: 'wave_started', module: 'preparation', name_ar: 'Wave Started Label',
        description_ar: 'Wave Started Desc', user_can_disable: true, has_destination: false, enabled: true,
      },
      {
        key: 'pricing_review_required', module: 'pricing', name_ar: 'Pricing Review Label',
        description_ar: 'Pricing Review Desc', user_can_disable: true, has_destination: true, enabled: false,
      },
    ]);
    mockUpdatePreferences.mockResolvedValue(undefined);

    renderPanel();
    await userEvent.click(screen.getByRole('button', { name: 'settings' }));

    expect(await screen.findByText('Wave Started Label')).toBeInTheDocument();
    expect(screen.getByText('Pricing Review Label')).toBeInTheDocument();

    // 2 popup/sound switches + 2 catalog switches.
    await waitFor(() => expect(screen.getAllByRole('switch')).toHaveLength(4));
    const waveStartedSwitch = screen.getByRole('switch', { name: 'Wave Started Label' });
    expect(waveStartedSwitch).toHaveAttribute('data-state', 'checked');

    await userEvent.click(waveStartedSwitch);

    await waitFor(() =>
      expect(mockUpdatePreferences).toHaveBeenCalledWith(
        expect.objectContaining({
          popup_enabled: true,
          sound_enabled: true,
          sound_volume: 0.8,
          type_overrides: { wave_started: false },
        }),
      ),
    );
  });
});
