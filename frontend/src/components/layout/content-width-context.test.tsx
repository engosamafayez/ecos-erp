import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ContentWidthProvider, useContentWidth, useFixedContentWidth } from './content-width-context';

/**
 * TASK-ECOS-V1.1-CORE-01-UI-02-SHELL-NAVIGATION-046 §6 — the canonical
 * fixed-width content-container capability: additive (default stays fluid),
 * a page opts in via `useFixedContentWidth()`, and reverts on unmount so a
 * later page that doesn't opt in isn't left stuck fixed-width.
 */

function Probe() {
  const { contentWidth } = useContentWidth();
  return <span data-testid="width">{contentWidth}</span>;
}

function FixedWidthPage() {
  useFixedContentWidth();
  return null;
}

describe('ContentWidthProvider / useFixedContentWidth', () => {
  it('defaults to fluid', () => {
    render(
      <ContentWidthProvider>
        <Probe />
      </ContentWidthProvider>,
    );
    expect(screen.getByTestId('width')).toHaveTextContent('fluid');
  });

  it('a mounted page calling useFixedContentWidth switches the shell to fixed', () => {
    render(
      <ContentWidthProvider>
        <Probe />
        <FixedWidthPage />
      </ContentWidthProvider>,
    );
    expect(screen.getByTestId('width')).toHaveTextContent('fixed');
  });

  it('unmounting the opted-in page reverts the shell to fluid', () => {
    const { rerender } = render(
      <ContentWidthProvider>
        <Probe />
        <FixedWidthPage />
      </ContentWidthProvider>,
    );
    expect(screen.getByTestId('width')).toHaveTextContent('fixed');

    rerender(
      <ContentWidthProvider>
        <Probe />
      </ContentWidthProvider>,
    );
    expect(screen.getByTestId('width')).toHaveTextContent('fluid');
  });

  it('useContentWidth throws outside the provider (fails loudly instead of silently no-op-ing)', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => render(<Probe />)).toThrow('useContentWidth must be inside ContentWidthProvider');
    spy.mockRestore();
  });
});
