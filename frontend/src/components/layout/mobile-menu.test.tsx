import '@testing-library/jest-dom/vitest';
import { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-NAVIGATION-DRAWER-BOTTOM-TRIGGER-001 — the tile-grid +
// full-screen drill-in shell (TASK-ECOS-MOBILE-NAVIGATION-WORLD-CLASS-
// REDESIGN-004) was rejected on DEV and replaced with a single-screen Drawer:
// a multi-page module now expands IN PLACE (an accordion) instead of pushing
// a second screen. These tests replace the old drill-in suite; the Radix
// Dialog interaction suite (Escape/backdrop/accessible-dialog) carries over
// unchanged since that primitive composition wasn't touched.
const nav = vi.hoisted(() => ({ modules: [] as unknown[], active: undefined as unknown }));
const auth = vi.hoisted(() => ({ user: null as null | { name: string; email: string }, logout: vi.fn() }));

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
vi.mock('./use-nav-label', () => ({ useNavLabel: () => ({ group: (id: string) => id, item: (k: string) => k }) }));
vi.mock('@/components/layout/header', () => ({
  CompanySwitcher: () => <div data-testid="company" />,
  WarehouseSwitcher: () => <div data-testid="warehouse" />,
}));
vi.mock('@/features/authorization', () => ({ useNavigation: () => ({ modules: nav.modules, canSeeModule: () => true }) }));
vi.mock('@/hooks/use-active-module', () => ({ useActiveModule: () => nav.active }));
vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (selector: (s: typeof auth) => unknown) => selector(auth),
}));
vi.mock('@/features/cost-management/hooks/use-pricing-reviews', () => ({
  usePriceReviewBadge: () => ({ data: undefined }),
}));

import { MobileMenu } from './mobile-menu';

const Stub = () => null;
const commerce = {
  id: 'commerce', icon: Stub, defaultPath: '/orders',
  items: [
    { key: 'sales-section', isSection: true },
    { key: 'orders', path: '/orders', icon: Stub },
    { key: 'products', path: '/products', icon: Stub },
    { key: 'customers', path: '/customers', icon: Stub },
  ],
};
const inventory = {
  id: 'inventory', icon: Stub, defaultPath: '/inventory',
  items: [
    { key: 'inv-dashboard', path: '/inventory', icon: Stub },
    { key: 'stock-ledger', path: '/inventory/stock-ledger', icon: Stub },
  ],
};
const dashboard = { id: 'dashboard', icon: Stub, defaultPath: '/dashboard', items: [] };

beforeEach(() => {
  nav.modules = [dashboard, commerce, inventory];
  // No default active module — most tests exercise the click-to-expand
  // interaction directly; the one test that cares about auto-expanding the
  // active module sets `nav.active` itself, deliberately.
  nav.active = undefined;
  auth.user = { name: 'Jane Doe', email: 'jane@ecos.test' };
  auth.logout = vi.fn().mockResolvedValue(undefined);
  window.localStorage.clear();
});

function LocationDisplay() {
  const loc = useLocation();
  return <div data-testid="loc">{loc.pathname}</div>;
}

function renderMenu(initialPath = '/orders') {
  const onClose = vi.fn();
  function Harness() {
    const [open, setOpen] = useState(true);
    return (
      <MemoryRouter initialEntries={[initialPath]}>
        <LocationDisplay />
        {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test-harness-only control, not app UI */}
        <button onClick={() => setOpen(true)}>reopen</button>
        <MobileMenu open={open} onClose={() => { onClose(); setOpen(false); }} />
      </MemoryRouter>
    );
  }
  return { onClose, ...render(<Harness />) };
}

const moduleRow = (id: string) => screen.getByText(id).closest('button') as HTMLButtonElement;
const childLink = (key: string) => screen.getByText(key).closest('a') as HTMLAnchorElement;

describe('MobileMenu — grouped accordion navigation list (single Drawer, no drill-in)', () => {
  it('lists every authorized module as a row, grouped by family', () => {
    renderMenu();
    expect(screen.getByText('commerce')).toBeInTheDocument();
    expect(screen.getByText('inventory')).toBeInTheDocument();
    expect(screen.getByText('dashboard')).toBeInTheDocument();
  });

  it('a module with a single destination navigates directly and closes the Drawer', () => {
    const { onClose } = renderMenu();
    fireEvent.click(moduleRow('dashboard'));
    expect(screen.getByTestId('loc')).toHaveTextContent('/dashboard');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('a module with multiple destinations expands IN PLACE — the Drawer stays open, nothing navigates yet', () => {
    const { onClose } = renderMenu();
    fireEvent.click(moduleRow('commerce'));
    expect(screen.getByText('sales-section')).toBeInTheDocument(); // section header restored
    expect(screen.getByText('orders')).toBeInTheDocument();
    expect(screen.getByText('products')).toBeInTheDocument();
    expect(screen.getByText('customers')).toBeInTheDocument();
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByText('inventory')).toBeInTheDocument(); // still the same single screen
  });

  it('clicking an expanded module again collapses it', () => {
    renderMenu();
    fireEvent.click(moduleRow('commerce'));
    expect(screen.getByText('sales-section')).toBeInTheDocument();
    fireEvent.click(moduleRow('commerce'));
    expect(screen.queryByText('sales-section')).toBeNull();
  });

  it('omits unauthorized children — only what the navigation authority returns is rendered', () => {
    nav.modules = [dashboard, { ...commerce, items: [
      { key: 'orders', path: '/orders', icon: Stub },
      { key: 'customers', path: '/customers', icon: Stub },
    ] }, inventory];
    renderMenu();
    fireEvent.click(moduleRow('commerce'));
    expect(screen.getByText('customers')).toBeInTheDocument();
    expect(screen.queryByText('products')).toBeNull();
  });

  it('a child navigates to ITS OWN canonical route and closes the Drawer', () => {
    const { onClose } = renderMenu();
    fireEvent.click(moduleRow('commerce'));
    fireEvent.click(childLink('products'));
    expect(screen.getByTestId('loc')).toHaveTextContent('/products');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('highlights the current child (active state)', () => {
    renderMenu('/products');
    fireEvent.click(moduleRow('commerce'));
    expect(childLink('products')).toHaveAttribute('aria-current', 'page');
    expect(childLink('orders')).not.toHaveAttribute('aria-current', 'page');
  });

  it('the current module is expanded by default when the Drawer opens (§9: "where am I" is obvious)', () => {
    nav.active = commerce;
    renderMenu('/orders');
    expect(screen.getByText('sales-section')).toBeInTheDocument();
    expect(screen.getByText('orders')).toBeInTheDocument();
  });

  it('reopening the Drawer re-syncs to the (possibly new) active module — no stale expand state', () => {
    nav.active = commerce;
    const { onClose } = renderMenu();
    fireEvent.click(childLink('orders')); // already expanded (commerce is active) — no toggle click needed
    expect(onClose).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByText('reopen'));
    expect(screen.getByText('sales-section')).toBeInTheDocument(); // still expanded — commerce is still active
  });

  it('search finds a page inside an unexpanded module and navigates directly to it', () => {
    const { onClose } = renderMenu();
    fireEvent.change(screen.getByPlaceholderText('nav.searchModulesPlaceholder'), {
      target: { value: 'stock-ledger' },
    });
    fireEvent.click(screen.getByText('stock-ledger'));
    expect(screen.getByTestId('loc')).toHaveTextContent('/inventory/stock-ledger');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('records a visit and surfaces it under Recent the next time the Drawer opens', () => {
    renderMenu();
    fireEvent.click(moduleRow('commerce'));
    fireEvent.click(childLink('orders')); // closes the menu, records the visit
    fireEvent.click(screen.getByText('reopen'));
    expect(screen.getByText('nav.recent')).toBeInTheDocument();
    expect(screen.getByText('orders')).toBeInTheDocument();
  });
});

describe('MobileMenu — profile identity + logout (new in this task)', () => {
  it('shows the signed-in user\'s name and email', () => {
    renderMenu();
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    expect(screen.getByText('jane@ecos.test')).toBeInTheDocument();
  });

  it('falls back to the canonical placeholder name when no user is loaded yet', () => {
    auth.user = null;
    renderMenu();
    expect(screen.getByText('userMenu.fallbackName')).toBeInTheDocument();
  });

  it('renders the Company and Warehouse context controls', () => {
    renderMenu();
    expect(screen.getByTestId('company')).toBeInTheDocument();
    expect(screen.getByTestId('warehouse')).toBeInTheDocument();
  });

  it('Logout calls the canonical auth store logout and closes the Drawer', async () => {
    const { onClose } = renderMenu();
    fireEvent.click(screen.getByRole('button', { name: 'userMenu.logout' }));
    expect(auth.logout).toHaveBeenCalledTimes(1);
    await Promise.resolve();
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});

// TASK-ECOS-MOBILE-NAVIGATION-WORLD-CLASS-REDESIGN-004 — interaction behavior
// gained by composing the same Radix Dialog primitive `SheetContent` already
// wraps for every other drawer in the app, instead of a raw `<div>`. Untouched
// by this task's content/layout redesign.
describe('MobileMenu — Radix Dialog interaction behavior', () => {
  it('Escape closes the menu', () => {
    const { onClose } = renderMenu();
    expect(screen.getByText('commerce')).toBeInTheDocument();
    fireEvent.keyDown(document, { key: 'Escape', code: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('clicking the backdrop overlay closes the menu', async () => {
    // Radix Portals the overlay/content as a sibling of RTL's `container` div,
    // directly under `document.body` — not inside `container`. Radix's
    // dismissable-layer listens for a real pointer-event sequence on
    // `document`, which `userEvent` reproduces faithfully (plain `fireEvent`
    // on the element alone does not trigger it under jsdom).
    const user = userEvent.setup();
    const { onClose } = renderMenu();
    const overlay = document.body.querySelector('[data-slot="sheet-overlay"]') as HTMLElement;
    expect(overlay).toBeTruthy();
    await user.click(overlay);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('renders as an accessible Radix dialog (role + labelled + a real description, not a manual duplicate)', () => {
    renderMenu();
    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveAttribute('aria-labelledby');
    expect(dialog).toHaveAttribute('aria-describedby');
    // The sr-only Description Radix requires is present (silences the a11y
    // warning) without being visible chrome.
    expect(screen.getByText('nav.menuDescription')).toBeInTheDocument();
  });
});
