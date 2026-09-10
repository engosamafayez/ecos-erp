/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-FINAL-019 §5.
 *
 * The badge must show the 6-word approved vocabulary when a display_status is supplied,
 * never the raw 10-value internal status — and must still fall back cleanly to the old
 * behavior for any caller that hasn't been updated (the two still-unrouted/legacy pages).
 */

import '@testing-library/jest-dom/vitest';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';

import enPm from '@/i18n/locales/en/purchase-materials.json';

// Same string-path form PurchaseMaterialStatusBadge itself uses (t(`common...${x}`)).
import { vi } from 'vitest';
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key.split('.').reduce<unknown>((acc, k) => (acc as Record<string, unknown>)?.[k], enPm),
  }),
}));

import { PurchaseMaterialStatusBadge } from './purchase-material-status-badge';

describe('PurchaseMaterialStatusBadge', () => {
  it('shows the approved 6-word label when displayStatus is supplied, not the raw internal one', () => {
    render(<PurchaseMaterialStatusBadge status="under_review" displayStatus="awaiting_supplier" />);

    expect(screen.getByText(enPm.common.displayStatus.awaiting_supplier)).toBeInTheDocument();
    expect(screen.queryByText(enPm.common.status.under_review)).not.toBeInTheDocument();
  });

  it('falls back to the raw internal status label when displayStatus is omitted (back-compat)', () => {
    render(<PurchaseMaterialStatusBadge status="on_hold" />);

    expect(screen.getByText(enPm.common.status.on_hold)).toBeInTheDocument();
  });

  it('shows a pause indicator only when isOnHold is true, even though the word itself is the resolved bucket', () => {
    const { container, rerender } = render(
      <PurchaseMaterialStatusBadge status="on_hold" displayStatus="purchasing" isOnHold />,
    );

    expect(screen.getByText(enPm.common.displayStatus.purchasing)).toBeInTheDocument();
    expect(container.querySelector('svg')).not.toBeNull();

    rerender(<PurchaseMaterialStatusBadge status="purchasing" displayStatus="purchasing" />);
    expect(container.querySelector('svg')).toBeNull();
  });
});
