import '@testing-library/jest-dom/vitest';
import { useState } from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Recovered with the accordion mobile menu (TASK-ECOS-MOBILE-UX-CORE-CLOSURE-001).
// Mutable holder the mocks read at call time, so each test can set the module set + active module.
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
  nav.modules = [commerce, inventory, dashboard];
  nav.active = commerce;
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
        <MobileMenu open={open} onClose={() => { onClose(); setOpen(false); }} />
      </MemoryRouter>
    );
  }
  return { onClose, ...render(<Harness />) };
}

const rowButton = (id: string) => screen.getByText(id).closest('button') as HTMLButtonElement;
const childLink = (key: string) => screen.getByText(key).closest('a') as HTMLAnchorElement;

describe('MobileMenu — inline nested (accordion) module sub-navigation', () => {
  it('auto-expands the active module and shows its children inline (Commerce → Orders/Products/Customers)', () => {
    renderMenu();
    expect(screen.getByText('orders')).toBeInTheDocument();
    expect(screen.getByText('products')).toBeInTheDocument();
    expect(screen.getByText('customers')).toBeInTheDocument();
  });

  it('a child navigates to ITS OWN canonical route (Products → /products, not the Commerce default /orders) and closes the menu', () => {
    const { onClose } = renderMenu();
    fireEvent.click(childLink('products'));
    expect(screen.getByTestId('loc')).toHaveTextContent('/products');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('highlights the current child (active state)', () => {
    renderMenu('/products');
    expect(childLink('products')).toHaveAttribute('aria-current', 'page');
    expect(childLink('orders')).not.toHaveAttribute('aria-current', 'page');
  });

  it('is a single-open accordion — expanding Inventory collapses Commerce', () => {
    renderMenu(); // Commerce active + expanded
    expect(screen.getByText('products')).toBeInTheDocument();
    fireEvent.click(rowButton('inventory'));
    expect(screen.getByText('stock-ledger')).toBeInTheDocument(); // Inventory now open
    expect(screen.queryByText('products')).toBeNull();            // Commerce collapsed
  });

  it('does not show another module’s children until its row is tapped (no stale/cross-module state)', () => {
    nav.active = inventory; // Inventory active → Commerce starts collapsed
    renderMenu('/inventory');
    expect(screen.queryByText('products')).toBeNull();
    fireEvent.click(rowButton('commerce'));
    expect(screen.getByText('products')).toBeInTheDocument();
    expect(screen.queryByText('stock-ledger')).toBeNull(); // Inventory collapsed by single-open
  });

  it('omits unauthorized children — only what the navigation authority returns is rendered', () => {
    // Authority returns Commerce WITHOUT Products (user lacks that page).
    nav.modules = [{ ...commerce, items: [
      { key: 'orders', path: '/orders', icon: Stub },
      { key: 'customers', path: '/customers', icon: Stub },
    ] }, inventory, dashboard];
    renderMenu();
    expect(screen.getByText('customers')).toBeInTheDocument();
    expect(screen.queryByText('products')).toBeNull();
  });

  it('a module with a single destination navigates directly instead of a one-item accordion', () => {
    const { onClose } = renderMenu();
    // Dashboard has no children → it is a direct link, not an expandable button.
    expect(screen.getByText('dashboard').closest('button')).toBeNull();
    fireEvent.click(childLink('dashboard'));
    expect(screen.getByTestId('loc')).toHaveTextContent('/dashboard');
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
