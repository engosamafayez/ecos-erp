import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string.
// Matches the established pattern in task-status-badge.test.tsx etc. — the
// real locale JSON isn't loaded in this test environment, so asserting
// against actual English copy would fail regardless of component behavior.
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

import { StatusBadge } from './index';

/**
 * TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045 — first test coverage
 * for StatusBadge, added alongside its new `tone` escape hatch. Covers both
 * the pre-existing `status` path (must render unchanged) and the new `tone`
 * path (renders the semantic-token classes and the caller-supplied label).
 */
describe('StatusBadge', () => {
  it('renders a built-in status with its translated default label', () => {
    render(<StatusBadge status="active" />);
    expect(screen.getByText('status.active')).toBeInTheDocument();
  });

  it('renders a built-in status with a caller-supplied label override', () => {
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture label
    render(<StatusBadge status="pending" label="Awaiting" />);
    expect(screen.getByText('Awaiting')).toBeInTheDocument();
    expect(screen.queryByText(/pending/i)).not.toBeInTheDocument();
  });

  it('renders a tone-based domain status with the caller-supplied label and the matching semantic classes', () => {
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture label
    render(<StatusBadge tone="success" label="Delivered" />);
    const badge = screen.getByText('Delivered').closest('span');
    expect(badge).toHaveClass('bg-success');
    expect(badge).toHaveClass('text-success-foreground');
    expect(badge).toHaveClass('border-success-border');
  });

  it.each(['success', 'warning', 'error', 'info', 'neutral'] as const)(
    'renders every tone (%s) without crashing',
    (tone) => {
      render(<StatusBadge tone={tone} label={`Status: ${tone}`} />);
      expect(screen.getByText(`Status: ${tone}`)).toBeInTheDocument();
    },
  );
});
