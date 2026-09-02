import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-NAVIGATION-DRAWER-BOTTOM-TRIGGER-001 — the bottom bar
// itself is unchanged (§5: "reuse it" rather than adding a second trigger);
// only its icon changed (LayoutGrid -> Menu). These are the first tests this
// component has ever had — added per §17's explicit "bottom nav remains
// fixed / tapping the dedicated bottom icon opens Drawer" requirement.
const nav = vi.hoisted(() => ({ modules: [] as unknown[] }));
const header = vi.hoisted(() => ({ openSearch: vi.fn() }));

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
vi.mock('./use-nav-label', () => ({ useNavLabel: () => ({ group: (id: string) => id, item: (k: string) => k }) }));
vi.mock('@/components/layout/header', () => ({ useHeaderContext: () => header }));
vi.mock('@/features/authorization', () => ({ useNavigation: () => ({ modules: nav.modules }) }));

import { MobileBottomNav } from './mobile-bottom-nav';

const Stub = () => null;
const dashboard = { id: 'dashboard', icon: Stub, defaultPath: '/dashboard' };
const commerce = { id: 'commerce', icon: Stub, defaultPath: '/orders' };
const inventory = { id: 'inventory', icon: Stub, defaultPath: '/inventory' };

beforeEach(() => {
  nav.modules = [dashboard, commerce, inventory];
  header.openSearch = vi.fn();
});

function renderNav(initialPath = '/dashboard', onOpenMenu = vi.fn()) {
  render(
    <MemoryRouter initialEntries={[initialPath]}>
      <MobileBottomNav onOpenMenu={onOpenMenu} />
    </MemoryRouter>,
  );
  return { onOpenMenu };
}

describe('MobileBottomNav — fixed bar with the Drawer trigger (§5)', () => {
  it('renders as a fixed bottom navigation bar', () => {
    renderNav();
    const bar = screen.getByRole('navigation', { name: 'nav.mobileNavigation' });
    expect(bar).toHaveClass('fixed', 'bottom-0');
  });

  it('renders Dashboard first, then the user\'s own first authorized modules — no hardcoded slot', () => {
    renderNav();
    expect(screen.getByRole('link', { name: 'dashboard' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'commerce' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'inventory' })).toBeInTheDocument();
  });

  it('marks the current route\'s pinned icon as active, and only that one', () => {
    renderNav('/orders');
    expect(screen.getByRole('link', { name: 'commerce' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'dashboard' })).not.toHaveAttribute('aria-current', 'page');
  });

  it('tapping the dedicated Menu icon opens the Drawer', () => {
    const { onOpenMenu } = renderNav();
    fireEvent.click(screen.getByRole('button', { name: 'nav.modules' }));
    expect(onOpenMenu).toHaveBeenCalledTimes(1);
  });

  it('the Menu trigger has an accessible label and is a real button, not a fake route', () => {
    renderNav();
    const trigger = screen.getByRole('button', { name: 'nav.modules' });
    expect(trigger.tagName).toBe('BUTTON');
    expect(trigger).not.toHaveAttribute('href');
  });

  it('the Menu trigger never carries an active/current state — it has no route of its own (§9)', () => {
    renderNav('/dashboard');
    const trigger = screen.getByRole('button', { name: 'nav.modules' });
    expect(trigger).not.toHaveAttribute('aria-current');
  });

  it('Search opens the global search dialog, not a route', () => {
    renderNav();
    fireEvent.click(screen.getByRole('button', { name: 'common.search' }));
    expect(header.openSearch).toHaveBeenCalledTimes(1);
  });
});
