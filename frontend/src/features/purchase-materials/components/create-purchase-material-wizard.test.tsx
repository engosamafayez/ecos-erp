import '@testing-library/jest-dom/vitest';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-FINAL-019 §2.
 *
 * The picker is now two explicit actions (Add Product / Add Raw Material), each scoped
 * server-side to its own product type, defaulting to a 3-item browse until the user searches.
 * Covers the picker split and the default-browse cap; §1's scroll restructuring is a pure
 * layout/className change with no behavior to assert here.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, { get: (_t, prop: string) => { path.push(prop); return proxy; } });
      (sel as (p: unknown) => unknown)(proxy);
      return path[path.length - 1] ?? '';
    },
  }),
}));

vi.mock('@/features/branches/components/company-select', () => ({ CompanySelect: () => null }));
vi.mock('./enterprise-demand-panel', () => ({ EnterpriseDemandPanel: () => null }));

const mockCreateMutate = vi.hoisted(() => vi.fn());
vi.mock('../hooks/use-purchase-materials', () => ({
  useCreatePurchaseMaterial: () => ({ mutateAsync: mockCreateMutate, isPending: false }),
}));

const warehousesList = vi.hoisted(() => vi.fn());
vi.mock('@/features/warehouses/services/warehouses-service', () => ({
  warehousesService: { list: warehousesList },
}));

const productsList = vi.hoisted(() => vi.fn());
vi.mock('@/features/products/services/products-service', () => ({
  productsService: { list: productsList },
}));

import { CreatePurchaseMaterialWizard } from './create-purchase-material-wizard';

function renderWizard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <CreatePurchaseMaterialWizard open onOpenChange={vi.fn()} />
    </QueryClientProvider>,
  );
}

async function goToStep2() {
  const user = userEvent.setup();
  await waitFor(() => expect(screen.getByText('selectWarehouse')).toBeInTheDocument());
  // Step 1 has two <select>s (Warehouse, Priority); disambiguate via the Warehouse
  // select's own placeholder option rather than assuming DOM order.
  const warehouseSelect = screen.getByText('selectWarehouse').closest('select') as HTMLSelectElement;
  await user.selectOptions(warehouseSelect, 'wh-1');
  await user.click(screen.getByText('next'));
  return user;
}

describe('CreatePurchaseMaterialWizard — item picker split (§2)', () => {
  beforeEach(() => {
    mockCreateMutate.mockReset();
    warehousesList.mockReset().mockResolvedValue({ items: [{ id: 'wh-1', name: 'Main Warehouse' }] });
    productsList.mockReset().mockResolvedValue({ items: [], meta: { current_page: 1, per_page: 3, total: 0, last_page: 1 } });
  });

  it('shows two explicit picking actions, not a combined catalogue browser', async () => {
    renderWizard();
    await goToStep2();

    expect(screen.getByText('addProduct')).toBeInTheDocument();
    expect(screen.getByText('addRawMaterial')).toBeInTheDocument();
    // No catalogue result should be visible before either action is clicked.
    expect(productsList).not.toHaveBeenCalled();
  });

  it('"Add Raw Material" queries only raw materials, browsing at most 3 by default', async () => {
    const user = await goToStep2Wrapper();

    await user.click(screen.getByText('addRawMaterial'));

    await waitFor(() => expect(productsList).toHaveBeenCalledWith(
      expect.objectContaining({ product_type: 'raw_material', per_page: 3 }),
    ));
  });

  it('"Add Product" queries finished goods + packaging materials (never raw materials), browsing at most 3', async () => {
    const user = await goToStep2Wrapper();

    await user.click(screen.getByText('addProduct'));

    await waitFor(() => expect(productsList).toHaveBeenCalledWith(
      expect.objectContaining({ product_types: 'finished_good,packaging_material', per_page: 3 }),
    ));
  });

  it('typing a search term lifts the browse cap', async () => {
    const user = await goToStep2Wrapper();
    await user.click(screen.getByText('addRawMaterial'));
    await waitFor(() => expect(productsList).toHaveBeenCalled());

    await user.type(screen.getByPlaceholderText('searchRawMaterialsPlaceholder'), 'flour');

    await waitFor(() => expect(productsList).toHaveBeenCalledWith(
      expect.objectContaining({ product_type: 'raw_material', search: 'flour', per_page: 20 }),
    ));
  });

  it('selecting a result adds it to the Selected list', async () => {
    productsList.mockResolvedValue({
      items: [{ id: 'p1', name: 'Flour', sku: 'RM-1', product_type: 'raw_material' }],
      meta: { current_page: 1, per_page: 3, total: 1, last_page: 1 },
    });
    const user = await goToStep2Wrapper();
    await user.click(screen.getByText('addRawMaterial'));

    await user.click(await screen.findByText('add'));

    // The picker stays open after adding (so more items can be added in one go), so "Flour"
    // now legitimately appears twice: once in the still-open picker row, once in the new
    // Selected line — asserting the count proves both, rather than picking one arbitrarily.
    await waitFor(() => expect(screen.getAllByText('Flour')).toHaveLength(2));
    expect(screen.getByText('groupRawMaterials')).toBeInTheDocument();
  });

  async function goToStep2Wrapper() {
    renderWizard();
    return goToStep2();
  }
});
