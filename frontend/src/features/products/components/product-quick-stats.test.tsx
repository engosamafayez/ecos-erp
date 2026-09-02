import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-COMMERCE-SCREENS-UX-REFINEMENT-001 §12 — the User asked for
// the "Unsynced" stat card removed from the MOBILE Products list only; desktop
// keeps it unchanged. jsdom has no media-query/layout engine, so these tests
// assert the underlying cause (the responsive `hidden md:block` wrapper) rather
// than actual computed visibility — a regression guard, not a viewport test.
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

import { ProductQuickStats } from './product-quick-stats';

const STATS = {
  total: 120,
  notSynced: 7,
  needsPricingReview: 3,
  mfgInStock: 90,
  mfgOutOfStock: 5,
};

describe('ProductQuickStats — Unsynced hidden on Mobile only (§12)', () => {
  it('wraps only the Unsynced card in a responsive hidden-on-mobile wrapper', () => {
    render(<ProductQuickStats stats={STATS} activeFilter={null} onFilterChange={vi.fn()} />);
    const notSyncedValue = screen.getByText('7');
    // The value text sits inside QuickStatCard -> the `hidden md:block` wrapper
    // this task added. Walking up finds that wrapper if it's really there.
    const wrapper = notSyncedValue.closest('.hidden.md\\:block');
    expect(wrapper).not.toBeNull();
  });

  it('does not hide any other stat card', () => {
    render(<ProductQuickStats stats={STATS} activeFilter={null} onFilterChange={vi.fn()} />);
    for (const value of ['120', '3', '90', '5']) {
      const el = screen.getByText(value);
      expect(el.closest('.hidden.md\\:block')).toBeNull();
    }
  });

  it('still renders the Unsynced count and its filter click still works — desktop behavior unchanged', () => {
    const onFilterChange = vi.fn();
    render(<ProductQuickStats stats={STATS} activeFilter={null} onFilterChange={onFilterChange} />);
    const card = screen.getByText('7').closest('button');
    expect(card).not.toBeNull();
    card!.click();
    expect(onFilterChange).toHaveBeenCalledWith({ type: 'not_synced', value: true });
  });
});
