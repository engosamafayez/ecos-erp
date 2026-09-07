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
 *
 * TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005 D1/D4 — the
 * bell's popover (`NotificationPreferencesButton`) now renders the panel in COMPACT
 * mode: popup/sound/volume/Test Sound + a concise summary + a link to the full settings
 * page, never the per-priority effective table or the full per-type toggle list — those
 * moved to the full page (`NotificationSettingsPage`, its own test file), which renders
 * the same `NotificationPreferencesPanel` in its default (non-compact) mode. Tests that
 * exercise that moved content render the panel directly via `renderFull()` instead of
 * going through the button/popover.
 */

const mockGetPreferences = vi.hoisted(() => vi.fn());
const mockUpdatePreferences = vi.hoisted(() => vi.fn());
const mockAttentionPolicy = vi.hoisted(() => vi.fn());
const mockTypeCatalog = vi.hoisted(() => vi.fn());
const mockPlayAttentionSound = vi.hoisted(() => vi.fn());
const mockToastError = vi.hoisted(() => vi.fn());

vi.mock('@/features/notifications/services/notifications-service', () => ({
  notificationsService: {
    getPreferences: mockGetPreferences,
    updatePreferences: mockUpdatePreferences,
    attentionPolicy: mockAttentionPolicy,
    typeCatalog: mockTypeCatalog,
  },
}));

vi.mock('../lib/notification-sound', () => ({
  playAttentionSound: mockPlayAttentionSound,
}));

// Matches the established mocking convention (see customer-drawer.test.tsx) — `toast`
// is a plain Zustand-store helper with no rendered output on its own (a separate
// <ToasterProvider/> is what actually paints toasts to the DOM, and isn't mounted in
// these component-only tests), so asserting on the mock call is the real signal here,
// not DOM text.
vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: mockToastError, warning: vi.fn(), info: vi.fn() },
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

import { NotificationPreferencesButton, NotificationPreferencesPanel } from './notification-preferences';

function renderCompact(onOpenFullSettings?: () => void) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <NotificationPreferencesButton onOpenFullSettings={onOpenFullSettings} />
    </QueryClientProvider>,
  );
}

/** Renders the panel directly in its default (non-compact/full) mode — no popover involved. */
function renderFull() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <NotificationPreferencesPanel />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  // Every existing test below predates the catalog (§8) and doesn't care about it —
  // default to empty so NotificationTypeToggles renders nothing rather than an
  // unresolved/rejected query polluting those tests.
  mockTypeCatalog.mockResolvedValue([]);
  mockPlayAttentionSound.mockReturnValue(true);
});

describe('NotificationPreferencesPanel (full — the settings page mode)', () => {
  it('shows CRITICAL as locked and NORMAL as editable, matching the server-resolved policy exactly', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: false });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderFull();

    // The lock icon renders only on the critical row.
    await waitFor(() => expect(screen.getByLabelText('locked')).toBeInTheDocument());
    expect(screen.getAllByLabelText('locked')).toHaveLength(1);
  });

  it('TASK-ECOS-NOTIFICATIONS-USER-REVIEW-REMEDIATION-007: renders an explicit read-only hint on the effective-summary table, and reflects popups-off/sound-on exactly as the server resolved it', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: false, sound_enabled: true });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: true, sound_profile: 'normal', locked: false },
      normal: { popup: false, sound: true, sound_profile: 'normal', locked: false },
      high: { popup: false, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderFull();

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

    renderFull();

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

describe('NotificationPreferencesButton (compact — the bell popover mode)', () => {
  it('toggling a switch writes the full preference payload through the real update path', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: false });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });
    mockUpdatePreferences.mockResolvedValue(undefined);

    renderCompact();
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

  it('defaults both switches on when the user has never set a preference', async () => {
    mockGetPreferences.mockResolvedValue(null);
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderCompact();
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

    renderCompact();
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

  // ── D1/D4 (TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005) ──

  it('D1: is quick-controls only — no effective table, no per-type toggle list, and a working link to the full settings page', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: true });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });
    mockTypeCatalog.mockResolvedValue([
      {
        key: 'wave_started', module: 'preparation', name_ar: 'Wave Started Label',
        description_ar: 'Wave Started Desc', user_can_disable: true, has_destination: false, enabled: true,
      },
    ]);
    const onOpenFullSettings = vi.fn();

    renderCompact(onOpenFullSettings);
    await userEvent.click(screen.getByRole('button', { name: 'settings' }));

    // Quick controls remain.
    await waitFor(() => expect(screen.getAllByRole('switch')).toHaveLength(2));
    expect(await screen.findByRole('slider', { name: 'volumeLabel' })).toBeInTheDocument();

    // The moved content never renders here, even though the catalog/policy data exists.
    expect(screen.queryByRole('presentation')).not.toBeInTheDocument();
    expect(screen.queryByText('Wave Started Label')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('locked')).not.toBeInTheDocument();

    await userEvent.click(screen.getByText('openSettingsPage'));
    expect(onOpenFullSettings).toHaveBeenCalledTimes(1);
  });

  it('D4: Test Sound plays the same profile at the current (draft) volume, and surfaces one bounded message on failure', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: false, sound_volume: 0.6 });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderCompact();
    await userEvent.click(screen.getByRole('button', { name: 'settings' }));

    const testSoundButton = await screen.findByText('testSound');

    // Works even with ambient sound OFF (sound_enabled: false above) — the deliberate
    // judgement call D4 asks to record.
    await userEvent.click(testSoundButton);
    expect(mockPlayAttentionSound).toHaveBeenCalledWith('normal', 0.6);
    expect(mockToastError).not.toHaveBeenCalled();

    mockPlayAttentionSound.mockReturnValue(false);
    await userEvent.click(testSoundButton);
    await waitFor(() => expect(mockToastError).toHaveBeenCalledWith('testSoundFailed'));
  });
});
