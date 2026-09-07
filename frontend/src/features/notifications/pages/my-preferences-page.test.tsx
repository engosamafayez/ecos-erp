import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import '@testing-library/jest-dom/vitest';

/**
 * TASK-ECOS-NOTIFICATIONS-USER-REVIEW-VISIBILITY-REMEDIATION-009.
 *
 * Proves the actual reachable page (not just the bell's popover) renders the same
 * editable controls and the same read-only effective-summary — the two things the
 * real DEV review needed to find and could not. Mirrors
 * notification-preferences.test.tsx's own established mocking convention exactly,
 * since this page renders that exact panel component.
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

import { MyPreferencesPage } from './my-preferences-page';

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MyPreferencesPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('MyPreferencesPage', () => {
  it('is not blank: renders the page title and both editable controls immediately, without needing the bell', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: true });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderPage();

    expect(screen.getByText('title')).toBeInTheDocument();
    await waitFor(() => expect(screen.getAllByRole('switch')).toHaveLength(2));
  });

  it('shows the explicit read-only hint on the effective-summary table, reachable through this page', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: true });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });

    renderPage();

    expect(await screen.findByText('effectiveReadOnlyHint')).toBeInTheDocument();
    expect(screen.getByRole('presentation')).toHaveAttribute('aria-readonly', 'true');
  });

  it('the editable switches remain interactive and write through the real update path', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: true });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });
    mockUpdatePreferences.mockResolvedValue(undefined);

    renderPage();

    const [popupSwitch] = await waitFor(() => {
      const switches = screen.getAllByRole('switch');
      expect(switches).toHaveLength(2);
      return switches;
    });

    await userEvent.click(popupSwitch);

    // TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §6/§9 — full payload
    // now always includes sound_volume/type_overrides too (PUT is a full replace).
    await waitFor(() =>
      expect(mockUpdatePreferences).toHaveBeenCalledWith({
        popup_enabled: false,
        sound_enabled: true,
        sound_volume: 1,
        type_overrides: undefined,
      }),
    );
  });
});
