import '@testing-library/jest-dom/vitest';
import { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Rewritten for TASK-ECOS-MOBILE-UX-COMPLETION-002 — the single-open accordion
// from TASK-ECOS-MOBILE-UX-CORE-CLOSURE-001 was rejected and replaced with a
// two-view launcher (Modules grid → per-module drill-in page list). These
// tests cover the new architecture; the RBAC/route guarantees the old suite
// checked (authorized-only items, own-route navigation, active-state) carry
// over unchanged since the data source (`useNavigation()`) is untouched.
const nav = vi.hoisted(() => ({ modules: [] as unknown[], active: undefined as unknown }));

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
vi.mock('@/components/common/brand-logo', () => ({ BrandLogo: () => <div data-testid="brand" /> }));
vi.mock('@/components/layout/header', () => ({
  CompanySwitcher: () => <div data-testid="company" />,
  WarehouseSwitcher: () => <div data-testid="warehouse" />,
}));
vi.mock('@/features/authorization', () => ({ useNavigation: () => ({ modules: nav.modules, canSeeModule: () => true }) }));
vi.mock('@/hooks/use-active-module', () => ({ useActiveModule: () => nav.active }));

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
  nav.active = commerce;
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

const tile = (id: string) => screen.getByText(id).closest('button') as HTMLButtonElement;
const childLink = (key: string) => screen.getByText(key).closest('a') as HTMLAnchorElement;

describe('MobileMenu — Modules launcher + drill-in (TASK-ECOS-MOBILE-UX-COMPLETION-002)', () => {
  it('opens on the Modules grid, listing every authorized module as a tile', () => {
    renderMenu();
    expect(screen.getByText('commerce')).toBeInTheDocument();
    expect(screen.getByText('inventory')).toBeInTheDocument();
    expect(screen.getByText('dashboard')).toBeInTheDocument();
  });

  it('a module with a single destination navigates directly and closes (no drill-in)', () => {
    const { onClose } = renderMenu();
    fireEvent.click(tile('dashboard'));
    expect(screen.getByTestId('loc')).toHaveTextContent('/dashboard');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('a module with multiple destinations drills in, restoring its section header (not filtered out)', () => {
    renderMenu();
    fireEvent.click(tile('commerce'));
    expect(screen.getByText('sales-section')).toBeInTheDocument(); // section header restored
    expect(screen.getByText('orders')).toBeInTheDocument();
    expect(screen.getByText('products')).toBeInTheDocument();
    expect(screen.getByText('customers')).toBeInTheDocument();
  });

  it('omits unauthorized children in the drill-in — only what the navigation authority returns is rendered', () => {
    nav.modules = [dashboard, { ...commerce, items: [
      { key: 'orders', path: '/orders', icon: Stub },
      { key: 'customers', path: '/customers', icon: Stub },
    ] }, inventory];
    renderMenu();
    fireEvent.click(tile('commerce'));
    expect(screen.getByText('customers')).toBeInTheDocument();
    expect(screen.queryByText('products')).toBeNull();
  });

  it('a drill-in child navigates to ITS OWN canonical route and closes the menu', () => {
    const { onClose } = renderMenu();
    fireEvent.click(tile('commerce'));
    fireEvent.click(childLink('products'));
    expect(screen.getByTestId('loc')).toHaveTextContent('/products');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('highlights the current child (active state) in the drill-in list', () => {
    renderMenu('/products');
    fireEvent.click(tile('commerce'));
    expect(childLink('products')).toHaveAttribute('aria-current', 'page');
    expect(childLink('orders')).not.toHaveAttribute('aria-current', 'page');
  });

  it('Back returns from the drill-in to the Modules grid', () => {
    renderMenu();
    fireEvent.click(tile('commerce'));
    expect(screen.getByText('orders')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'nav.backToModules' }));
    expect(screen.getByText('inventory')).toBeInTheDocument();
    expect(screen.queryByText('sales-section')).toBeNull();
  });

  it('reopening the menu always starts back on the Modules grid (no stale drill-in state)', () => {
    const { onClose } = renderMenu();
    fireEvent.click(tile('commerce'));
    fireEvent.click(childLink('orders'));
    expect(onClose).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByText('reopen'));
    // "commerce" now legitimately renders twice (the grid tile + the Recent
    // row's secondary label) — the grid tile specifically confirms we are
    // back on the launcher, not still drilled into commerce.
    expect(screen.getByText('inventory')).toBeInTheDocument();
    expect(screen.queryByText('sales-section')).toBeNull();
  });

  it('search finds a page inside an unopened module and navigates directly to it', () => {
    const { onClose } = renderMenu();
    fireEvent.change(screen.getByPlaceholderText('nav.searchModulesPlaceholder'), {
      target: { value: 'stock-ledger' },
    });
    fireEvent.click(screen.getByText('stock-ledger'));
    expect(screen.getByTestId('loc')).toHaveTextContent('/inventory/stock-ledger');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('records a visit and surfaces it under Recent the next time the menu opens', () => {
    renderMenu();
    fireEvent.click(tile('commerce'));
    fireEvent.click(childLink('orders')); // closes the menu, records the visit
    fireEvent.click(screen.getByText('reopen'));
    expect(screen.getByText('nav.recent')).toBeInTheDocument();
    expect(screen.getByText('orders')).toBeInTheDocument();
  });
});
