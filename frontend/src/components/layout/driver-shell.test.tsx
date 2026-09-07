import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';

import type { AuthUser } from '@/features/auth/types';

// Selector-mode i18n → dotted path, so assertions are stable without booting i18next
// (the same proxy the driver-mobile page tests use).
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf')
        return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) =>
      typeof sel === 'function'
        ? String((sel as (p: unknown) => unknown)(pathProxy('')))
        : String(sel),
  }),
}));

// EnterpriseAppShell reads the auth store and (for enterprise users) renders the real AppShell.
// Stub the store and the heavy enterprise shell so the guard can be tested in isolation; the
// real isDriverOnly predicate runs against the injected user.
let mockUser: AuthUser | null = null;
vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (selector: (s: { user: AuthUser | null }) => unknown) =>
    selector({ user: mockUser }),
}));
vi.mock('@/components/layout/app-shell', () => ({
  AppShell: () => <div data-testid="enterprise-shell" />,
}));

// The shell resolves the driver's current trip (§1) to build the "From current trip" section.
// Default: no active trip → no trip section. Individual tests can set an active trip.
let mockTrips: Array<{ id: string; stops_count: number }> = [];
vi.mock('@/features/operations/driver-mobile/hooks/use-driver-mobile', () => ({
  useDriverTrips: () => ({ data: mockTrips }),
}));

// D5 (TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005) — DriverShell
// renders the real NotificationCenter (§7), whose hooks need a QueryClientProvider (added
// below) AND a resolved service layer; a real axios call has no business running in this
// component test, so it's stubbed the same way notification-center.test.tsx's own suite does.
vi.mock('@/features/notifications/services/notifications-service', () => ({
  notificationsService: {
    list: vi.fn().mockResolvedValue({ data: [], unread_count: 0, meta: { page: 1, perPage: 25, total: 0, lastPage: 1 } }),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
    markReadSet: vi.fn(),
    attentionPolicy: vi.fn().mockResolvedValue({
      low: { popup: false, sound: false, sound_profile: null, locked: false },
      normal: { popup: true, sound: false, sound_profile: null, locked: false },
      high: { popup: true, sound: true, sound_profile: 'important', locked: false },
      critical: { popup: true, sound: true, sound_profile: 'critical', locked: true },
    }),
    getPreferences: vi.fn().mockResolvedValue(null),
    updatePreferences: vi.fn(),
    typeCatalog: vi.fn().mockResolvedValue([]),
  },
}));

import { DriverShell } from './driver-shell';
import { EnterpriseAppShell } from './enterprise-app-shell';

function user(overrides: Partial<AuthUser>): AuthUser {
  return {
    id: 1,
    name: 'Test',
    email: 't@example.com',
    company_id: 'c1',
    permissions: [],
    ...overrides,
  } as AuthUser;
}

describe('DriverShell', () => {
  function renderAt(path: string, content = 'PAGE') {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[path]}>
          <Routes>
            <Route element={<DriverShell />}>
              <Route path="/driver/home" element={<div>{content}</div>} />
              <Route path="/driver/orders" element={<div>{content}</div>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
  }

  it('renders the routed page through the shell Outlet', () => {
    renderAt('/driver/home', 'HOME PAGE');
    expect(screen.getByText('HOME PAGE')).toBeInTheDocument();
  });

  it('exposes the four primary driver destinations with the canonical routes', () => {
    renderAt('/driver/home');
    expect(screen.getByRole('link', { name: 'shell.nav.home' })).toHaveAttribute(
      'href',
      '/driver/home',
    );
    expect(screen.getByRole('link', { name: 'shell.nav.loading' })).toHaveAttribute(
      'href',
      '/driver/loading',
    );
    expect(screen.getByRole('link', { name: 'shell.nav.orders' })).toHaveAttribute(
      'href',
      '/driver/orders',
    );
    expect(screen.getByRole('link', { name: 'shell.nav.vehicle' })).toHaveAttribute(
      'href',
      '/driver/vehicle-inventory',
    );
    expect(screen.getByRole('button', { name: 'shell.openMenu' })).toBeInTheDocument();
  });

  it('does not leak enterprise navigation into the driver shell', () => {
    renderAt('/driver/home');
    // No enterprise dashboard/module links exist anywhere in the driver shell chrome.
    expect(screen.queryByRole('link', { name: /dashboard/i })).toBeNull();
    for (const link of screen.getAllByRole('link')) {
      expect(link.getAttribute('href')).toMatch(/^\/driver\//);
    }
  });

  it('marks the active destination via aria-current', () => {
    renderAt('/driver/orders');
    expect(screen.getByRole('link', { name: 'shell.nav.orders' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    expect(screen.getByRole('link', { name: 'shell.nav.home' })).not.toHaveAttribute(
      'aria-current',
    );
  });

  it('exposes Statement and the current-trip execution screens in the More sheet (§1)', () => {
    mockTrips = [{ id: 'T1', stops_count: 3 }];
    renderAt('/driver/home');
    fireEvent.click(screen.getByRole('button', { name: 'shell.openMenu' }));
    expect(screen.getByRole('link', { name: 'shell.nav.statement' })).toHaveAttribute(
      'href',
      '/driver/statement',
    );
    // Trip-scoped screens carry the resolved :tripId, not a raw parameterised route.
    expect(screen.getByRole('link', { name: 'shell.nav.returns' })).toHaveAttribute(
      'href',
      '/driver/trips/T1/returns',
    );
    expect(screen.getByRole('link', { name: 'shell.nav.exceptions' })).toHaveAttribute(
      'href',
      '/driver/trips/T1/exceptions',
    );
    mockTrips = [];
  });
});

describe('EnterpriseAppShell (driver isolation guard)', () => {
  function renderEnterprise() {
    return render(
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route path="/dashboard" element={<EnterpriseAppShell />} />
          <Route path="/driver/home" element={<div data-testid="driver-home" />} />
        </Routes>
      </MemoryRouter>,
    );
  }

  it('redirects a driver-only user to the driver home instead of the ERP shell', () => {
    mockUser = user({ permissions: ['logistics.shipping.view', 'loading.driver.operate'] });
    renderEnterprise();
    expect(screen.getByTestId('driver-home')).toBeInTheDocument();
    expect(screen.queryByTestId('enterprise-shell')).toBeNull();
  });

  it('renders the enterprise shell for an enterprise-only user', () => {
    mockUser = user({ permissions: ['sales.orders.view'] });
    renderEnterprise();
    expect(screen.getByTestId('enterprise-shell')).toBeInTheDocument();
  });

  it('renders the enterprise shell for a mixed driver + dispatcher user (never trapped)', () => {
    mockUser = user({ permissions: ['loading.driver.operate', 'logistics.distribution.update'] });
    renderEnterprise();
    expect(screen.getByTestId('enterprise-shell')).toBeInTheDocument();
  });
});
