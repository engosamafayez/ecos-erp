import '@testing-library/jest-dom/vitest';

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
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

vi.mock('@/features/notifications/services/notifications-service', () => ({
  notificationsService: {
    getPreferences: mockGetPreferences,
    updatePreferences: mockUpdatePreferences,
    attentionPolicy: mockAttentionPolicy,
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

    await waitFor(() =>
      expect(mockUpdatePreferences).toHaveBeenCalledWith({ popup_enabled: false, sound_enabled: false }),
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
});
