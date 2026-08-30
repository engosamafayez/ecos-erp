import '@testing-library/jest-dom/vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (sel: unknown) => (typeof sel === 'function' ? 'x' : String(sel)), i18n: { language: 'en' } }),
}));

// Capture the params the selector sends to the products query.
const useProductsQuery = vi.fn((...args: unknown[]) => {
  void args;
  return { data: { items: [{ id: 'p1', sku: 'SKU1', name: 'Item One' }] }, isFetching: false };
});
vi.mock('@/features/products/hooks/use-products', () => ({ useProductsQuery: (p: unknown) => useProductsQuery(p) }));

// Expose the combobox search input so we can drive server-side search.
vi.mock('@/components/crud', () => ({
  Combobox: ({ onSearchChange, filterClientSide }: { onSearchChange?: (q: string) => void; filterClientSide?: boolean }) => (
    <input data-testid="cb" data-client-filter={String(filterClientSide)} onChange={(e) => onSearchChange?.(e.target.value)} />
  ),
}));

import { ProductLineSelect } from './product-line-select';

function lastParams() {
  return (useProductsQuery.mock.calls.at(-1)?.[0] ?? {}) as Record<string, unknown>;
}

describe('ProductLineSelect', () => {
  beforeEach(() => { useProductsQuery.mockClear(); vi.useFakeTimers(); });
  afterEach(() => { vi.useRealTimers(); });

  it('queries finished goods for a Product line, active + capped, client-filter OFF (§7)', () => {
    render(<ProductLineSelect entityType="product" value="" valueLabel="" onChange={() => {}} />);
    expect(lastParams()).toMatchObject({ product_type: 'finished_good', status: 'active', per_page: 25 });
    expect(screen.getByTestId('cb').getAttribute('data-client-filter')).toBe('false');
  });

  it('queries raw materials for a Raw Material line — never mixing the two (§8)', () => {
    render(<ProductLineSelect entityType="raw_material" value="" valueLabel="" onChange={() => {}} />);
    expect(lastParams()).toMatchObject({ product_type: 'raw_material', status: 'active' });
  });

  it('drives the typed term into the SERVER query (debounced)', () => {
    render(<ProductLineSelect entityType="product" value="" valueLabel="" onChange={() => {}} />);
    fireEvent.change(screen.getByTestId('cb'), { target: { value: 'flour' } });
    act(() => { vi.advanceTimersByTime(300); });
    expect(lastParams()).toMatchObject({ search: 'flour', product_type: 'finished_good' });
  });
});
