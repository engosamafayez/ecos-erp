import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §A.2/§A.4.
 *
 * Covers the two structural UX requirements on Create Supplier: Supply Capabilities is
 * gone from this flow (still available from Edit / Supplier 360 — untouched, out of
 * this test's scope), and the step sequence is 2, not 3 (Basic Info + Contact merged
 * into one "Supplier Information" step).
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, {
        get: (_t, prop: string) => { path.push(prop); return proxy; },
      });
      (sel as (p: unknown) => unknown)(proxy);
      return path[path.length - 1] ?? '';
    },
  }),
}));

vi.mock('@/features/suppliers/hooks/use-suppliers', () => ({
  useCreateSupplier: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock('@/features/suppliers/hooks/use-supplier-categories', () => ({
  useSupplierCategoriesQuery: () => ({ data: [], isLoading: false }),
}));

import { SupplierWizard } from './supplier-wizard';

describe('SupplierWizard', () => {
  it('shows exactly 2 steps, not 3', () => {
    render(<SupplierWizard open onOpenChange={vi.fn()} />);

    expect(screen.getByText('info')).toBeInTheDocument();
    expect(screen.getByText('review')).toBeInTheDocument();
    expect(screen.queryByText('contact')).not.toBeInTheDocument();
    expect(screen.queryByText('basicInfo')).not.toBeInTheDocument();
  });

  it('does not render the Supply Capabilities section', () => {
    render(<SupplierWizard open onOpenChange={vi.fn()} />);

    expect(screen.queryByText('sectionTitle')).not.toBeInTheDocument();
  });

  it('renders the categories multi-select field on step 1', () => {
    render(<SupplierWizard open onOpenChange={vi.fn()} />);

    expect(screen.getByText('categories')).toBeInTheDocument();
  });
});
