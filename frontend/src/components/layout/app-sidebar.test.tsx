import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * TASK-ECOS-V1.1-CORE-01-UI-02-SHELL-NAVIGATION-046 §5/§8 — first test
 * coverage for AppSidebar, added alongside the collapsed-state redesign: a
 * collapsed sidebar used to render nothing but an expand button (§5's
 * "usable, not merely visually narrower" requirement was previously
 * unenforced by anything). Mirrors mobile-bottom-nav.test.tsx's mocking
 * convention for consistency.
 */

// jsdom does not implement scrollIntoView / ResizeObserver-adjacent APIs Radix
// Tooltip's positioning relies on; a minimal stub is enough for these tests.
Element.prototype.scrollIntoView = Element.prototype.scrollIntoView ?? (() => {});

const lang = vi.hoisted(() => ({ dir: 'ltr' as 'ltr' | 'rtl' }));

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
    t: (sel: unknown, opts?: Record<string, unknown>) => {
      const resolved = typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel);
      return opts?.module ? `${resolved}:${opts.module}` : resolved;
    },
  }),
}));
vi.mock('./use-nav-label', () => ({ useNavLabel: () => ({ group: (id: string) => `group.${id}`, item: (k: string) => `item.${k}` }) }));
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: () => true }),
  useAuthorization: () => ({ context: { navigationOverrides: {} } }),
}));
vi.mock('@/features/cost-management/hooks/use-pricing-reviews', () => ({
  usePriceReviewBadge: () => ({ data: undefined }),
}));
vi.mock('@/providers/language-context', () => ({ useLanguage: () => lang }));

import { AppSidebar } from './app-sidebar';
import type { AppModule } from '@/config/module-navigation';

const Stub = () => null;
const activeModule = {
  id: 'inventory',
  items: [
    { key: 'products', path: '/inventory/products', icon: Stub },
    { key: 'stock', path: '/inventory/stock', icon: Stub },
  ],
} as unknown as AppModule;

function renderSidebar(props: Partial<React.ComponentProps<typeof AppSidebar>> = {}) {
  return render(
    <MemoryRouter initialEntries={['/inventory/products']}>
      <AppSidebar activeModule={activeModule} {...props} />
    </MemoryRouter>,
  );
}

beforeEach(() => {
  lang.dir = 'ltr';
});

describe('AppSidebar — expanded', () => {
  it('renders every visible item with its label and marks the current route active', () => {
    renderSidebar();
    const products = screen.getByRole('link', { name: 'item.products' });
    expect(products).toHaveClass('bg-primary');
    expect(screen.getByRole('link', { name: 'item.stock' })).not.toHaveClass('bg-primary');
  });

  it('calls onCollapse when the collapse button is clicked', async () => {
    const user = userEvent.setup();
    const onCollapse = vi.fn();
    renderSidebar({ onCollapse });
    await user.click(screen.getByRole('button', { name: 'nav.collapseSidebar' }));
    expect(onCollapse).toHaveBeenCalledTimes(1);
  });
});

describe('AppSidebar — collapsed (§5: usable, not merely narrower)', () => {
  it('still renders every item as an icon-only link, not just an expand button', () => {
    renderSidebar({ collapsed: true });
    expect(screen.getByRole('link', { name: 'item.products' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'item.stock' })).toBeInTheDocument();
  });

  it('keeps the active item visibly distinguished when collapsed', () => {
    renderSidebar({ collapsed: true });
    expect(screen.getByRole('link', { name: 'item.products' })).toHaveClass('bg-primary');
    expect(screen.getByRole('link', { name: 'item.stock' })).not.toHaveClass('bg-primary');
  });

  it('shows a tooltip with the full label on hover', async () => {
    const user = userEvent.setup();
    renderSidebar({ collapsed: true });
    await user.hover(screen.getByRole('link', { name: 'item.products' }));
    expect(await screen.findByRole('tooltip')).toHaveTextContent('item.products');
  });

  it('calls onCollapse when the expand button is clicked', async () => {
    const user = userEvent.setup();
    const onCollapse = vi.fn();
    renderSidebar({ collapsed: true, onCollapse });
    await user.click(screen.getByRole('button', { name: 'nav.expandSidebar' }));
    expect(onCollapse).toHaveBeenCalledTimes(1);
  });
});
