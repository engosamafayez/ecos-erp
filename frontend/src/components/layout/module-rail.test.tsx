import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * TASK-ECOS-V1.1-CORE-01-UI-02-SHELL-NAVIGATION-046 §8 — first test coverage
 * for ModuleRail, added alongside upgrading its native `title` tooltip to the
 * canonical Radix Tooltip primitive (for consistency with AppSidebar's new
 * collapsed-state tooltips). Mirrors mobile-bottom-nav.test.tsx's mocking
 * convention.
 */
Element.prototype.scrollIntoView = Element.prototype.scrollIntoView ?? (() => {});

const nav = vi.hoisted(() => ({ modules: [] as unknown[] }));
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
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
  }),
}));
vi.mock('./use-nav-label', () => ({ useNavLabel: () => ({ group: (id: string) => `group.${id}`, item: (k: string) => k }) }));
vi.mock('@/features/authorization', () => ({ useNavigation: () => ({ modules: nav.modules }) }));
vi.mock('@/providers/language-context', () => ({ useLanguage: () => lang }));

import { ModuleRail } from './module-rail';

const Stub = () => null;
const dashboard = { id: 'dashboard', icon: Stub, defaultPath: '/dashboard' };
const commerce = { id: 'commerce', icon: Stub, defaultPath: '/orders' };

beforeEach(() => {
  nav.modules = [dashboard, commerce];
  lang.dir = 'ltr';
});

function renderRail(activeModule?: { id: string }) {
  return render(
    <MemoryRouter>
      <ModuleRail activeModule={activeModule as never} />
    </MemoryRouter>,
  );
}

describe('ModuleRail', () => {
  it('renders every authorized module as a link with its group label', () => {
    renderRail();
    expect(screen.getByRole('link', { name: 'group.dashboard' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'group.commerce' })).toBeInTheDocument();
  });

  it('marks only the active module with aria-current', () => {
    renderRail(commerce);
    expect(screen.getByRole('link', { name: 'group.commerce' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'group.dashboard' })).not.toHaveAttribute('aria-current');
  });

  it('shows a tooltip with the module label on hover', async () => {
    const user = userEvent.setup();
    renderRail();
    await user.hover(screen.getByRole('link', { name: 'group.dashboard' }));
    expect(await screen.findByRole('tooltip')).toHaveTextContent('group.dashboard');
  });

  it('no longer relies on the native title attribute (superseded by the styled tooltip)', () => {
    renderRail();
    expect(screen.getByRole('link', { name: 'group.dashboard' })).not.toHaveAttribute('title');
  });
});
