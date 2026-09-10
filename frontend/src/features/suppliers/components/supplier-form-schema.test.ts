import { describe, it, expect } from 'vitest';

import { toFormValues, toPayload } from '@/features/suppliers/components/supplier-form-schema';
import type { Supplier } from '@/features/suppliers/types/supplier';

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §A.1 — Multiple
 * Categories. Covers the form<->payload boundary only (the part most likely to
 * silently regress into data loss): the legacy-singular-category fallback when a
 * Supplier's `categories` relation hasn't been eager-loaded, and that the payload
 * never re-sends the legacy field now that the array is canonical.
 */
describe('supplier-form-schema — Multiple Categories', () => {
  function baseSupplier(overrides: Partial<Supplier> = {}): Supplier {
    return {
      id: 's1',
      code: 'SUP-000001',
      supplier_category_id: null,
      name: 'Acme Supplies',
      contact_person: null,
      email: null,
      phone: null,
      mobile: null,
      country: null,
      state: null,
      city: null,
      district: null,
      address: null,
      google_maps_url: null,
      notes: null,
      is_active: true,
      created_at: null,
      updated_at: null,
      ...overrides,
    };
  }

  it('toFormValues prefers the full categories set when loaded', () => {
    const supplier = baseSupplier({
      supplier_category_id: 'cat-1',
      categories: [
        { id: 'cat-1', code: 'SC-1', name: 'Raw Material Vendor', name_ar: null, is_active: true, created_at: null },
        { id: 'cat-2', code: 'SC-2', name: 'Service Provider', name_ar: null, is_active: true, created_at: null },
      ],
    });

    const values = toFormValues(supplier);

    expect(values.supplier_category_ids).toEqual(['cat-1', 'cat-2']);
  });

  it('toFormValues falls back to the legacy singular id when categories was never eager-loaded', () => {
    const supplier = baseSupplier({ supplier_category_id: 'cat-1', categories: undefined });

    const values = toFormValues(supplier);

    expect(values.supplier_category_ids).toEqual(['cat-1']);
  });

  it('toFormValues defaults to an empty set for a brand-new supplier', () => {
    const values = toFormValues();

    expect(values.supplier_category_ids).toEqual([]);
  });

  it('toPayload sends supplier_category_ids and never re-sends the legacy singular field or code', () => {
    const values = toFormValues(baseSupplier({
      categories: [{ id: 'cat-1', code: 'SC-1', name: 'X', name_ar: null, is_active: true, created_at: null }],
    }));

    const payload = toPayload({ ...values, name: 'Acme Supplies' });

    expect(payload.supplier_category_ids).toEqual(['cat-1']);
    expect(payload).not.toHaveProperty('supplier_category_id');
    expect(payload).not.toHaveProperty('code');
  });

  it('toPayload sends an explicit empty array when every category chip was removed (full-replace, not "leave unchanged")', () => {
    const values = toFormValues(baseSupplier({ supplier_category_id: 'cat-1' }));
    values.supplier_category_ids = [];

    const payload = toPayload(values);

    expect(payload.supplier_category_ids).toEqual([]);
  });
});
