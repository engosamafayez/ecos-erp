import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import '@testing-library/jest-dom';

import type { TrackSupportAvailability } from '@/features/customer-portal/types';

/**
 * TASK-ECOS-...-020 §33 items 39-41 — General Support is always offered; the 4 post-delivery-
 * specific categories are offered ONLY when the backend's own `post_delivery_window.available`
 * says so — never a frontend-computed "30 days" (§18), and an unprovable delivery timestamp
 * (the corrected 019R1 §2 behaviour) must NOT enable them either.
 */
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf')
        return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) =>
      typeof sel === 'function'
        ? String((sel as (p: unknown) => unknown)(pathProxy('')))
        : String(sel),
  }),
}));

vi.mock('@/features/customer-portal/hooks/use-customer-portal', () => ({
  useTrackSupportQuery: () => ({ data: [], isLoading: false }),
  useCreateSupportRequestMutation: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { SupportPanel } from './support-panel';

function renderPanel(availability: TrackSupportAvailability) {
  return render(<SupportPanel availability={availability} />);
}

describe('SupportPanel — category availability', () => {
  it('always offers General Support even when the post-delivery window is unavailable', () => {
    renderPanel({
      general_available: true,
      post_delivery_window: { available: false, reason: 'not_yet_delivered' },
    });

    const select = screen.getByLabelText('support.categoryLabel') as HTMLSelectElement;
    const optionValues = Array.from(select.options).map((o) => o.value);

    expect(optionValues).toContain('general_support');
    expect(optionValues).toContain('payment_issue');
    expect(optionValues).toContain('invoice_issue');
  });

  it('excludes the 4 post-delivery-specific categories when the backend reports the window unavailable', () => {
    renderPanel({
      general_available: true,
      post_delivery_window: { available: false, reason: 'delivery_timestamp_unavailable' },
    });

    const select = screen.getByLabelText('support.categoryLabel') as HTMLSelectElement;
    const optionValues = Array.from(select.options).map((o) => o.value);

    for (const restricted of ['wrong_item', 'damaged_item', 'missing_item', 'return_request']) {
      expect(optionValues).not.toContain(restricted);
    }
  });

  it('offers the post-delivery-specific categories only when the backend reports the window available', () => {
    renderPanel({
      general_available: true,
      post_delivery_window: { available: true, reason: 'within_post_delivery_window' },
    });

    const select = screen.getByLabelText('support.categoryLabel') as HTMLSelectElement;
    const optionValues = Array.from(select.options).map((o) => o.value);

    for (const restricted of ['wrong_item', 'damaged_item', 'missing_item', 'return_request']) {
      expect(optionValues).toContain(restricted);
    }
  });
});
