import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import '@testing-library/jest-dom/vitest';

/**
 * D1 (TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005).
 *
 * Proves the full settings page actually renders the FULL panel (effective table +
 * per-type toggle list included) — the content D1 moved off the bell's now-compact
 * popover — reachable at its own route without opening the bell at all. Mirrors
 * my-preferences-page.test.tsx's own established mocking convention exactly.
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

import { NotificationSettingsPage } from './notification-settings-page';

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <NotificationSettingsPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  mockTypeCatalog.mockResolvedValue([]);
});

describe('NotificationSettingsPage', () => {
  it('renders the page title and every quick control, without needing the bell', async () => {
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
    expect(screen.getByText('testSound')).toBeInTheDocument();
  });

  it('D1: unlike the bell popover, shows the full effective-priority table and the real, grouped notification type list', async () => {
    mockGetPreferences.mockResolvedValue({ popup_enabled: true, sound_enabled: true });
    mockAttentionPolicy.mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    });
    mockTypeCatalog.mockResolvedValue([
      {
        key: 'pricing_review_required', module: 'pricing', name_ar: 'Pricing Review Label',
        description_ar: 'Pricing Review Desc', user_can_disable: true, has_destination: true, enabled: true,
      },
    ]);

    renderPage();

    expect(await screen.findByText('effectiveReadOnlyHint')).toBeInTheDocument();
    expect(screen.getByRole('presentation')).toHaveAttribute('aria-readonly', 'true');
    expect(await screen.findByText('Pricing Review Label')).toBeInTheDocument();
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
