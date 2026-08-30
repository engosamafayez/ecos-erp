/* eslint-disable ecos-i18n/no-hardcoded-ui-strings -- test fixtures are data, not UI copy */
import { describe, expect, it } from 'vitest';

import { toFormValues, toPayload, type ProductFormValues } from './product-form-schema';

/**
 * Regression for DEF-PROD-01 (TASK-FRESH-DATA-BLOCKERS-001 · Blocker 01).
 *
 * Business contract (D-5): EVERY PRODUCT MUST HAVE A UNIT. The create form
 * collected `unit_id`, the Zod schema required it, and the DB column is NOT NULL —
 * but `toPayload()` never forwarded it, so every UI-created product hit a NOT-NULL
 * violation (HTTP 500). These tests pin the mapping so the field can never silently
 * fall out of the payload again.
 */

const baseValues: ProductFormValues = {
  sku: 'FG-TEST-001',
  name: 'Test Finished Good',
  description: 'desc',
  category_id: 'cat-uuid',
  unit_id: 'unit-uuid',
  brand_id: 'brand-uuid',
  channel_ids: ['chan-uuid'],
  product_type: 'finished_good',
  is_active: true,
  image_url: null,
  manual_cost: null,
  markup_pct: null,
  discount_pct: null,
  use_brand_pricing: true,
  regular_price: 100,
  sale_price: null,
  long_description: '',
  stock_status: null,
};

describe('toPayload — unit_id mapping (DEF-PROD-01)', () => {
  // A — a Finished Product built from a UI payload contains unit_id.
  it('includes unit_id from the form values', () => {
    expect(toPayload(baseValues).unit_id).toBe('unit-uuid');
  });

  it('forwards the exact unit_id chosen in the form, not a guessed one', () => {
    expect(toPayload({ ...baseValues, unit_id: 'another-unit' }).unit_id).toBe('another-unit');
  });

  it('round-trips unit_id from an existing product through toFormValues → toPayload', () => {
    const values = toFormValues({
      id: 'p1',
      sku: 'FG-RT',
      name: 'RoundTrip',
      category_id: 'c1',
      unit_id: 'existing-unit',
      brand_id: 'b1',
      product_type: 'finished_good',
      is_active: true,
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    } as any);
    expect(toPayload(values).unit_id).toBe('existing-unit');
  });

  // D — existing mapping is unchanged: the other required contract fields still map.
  it('leaves the rest of the create contract intact', () => {
    const payload = toPayload(baseValues);
    expect(payload.sku).toBe('FG-TEST-001');
    expect(payload.name).toBe('Test Finished Good');
    expect(payload.category_id).toBe('cat-uuid');
    expect(payload.brand_id).toBe('brand-uuid');
    expect(payload.product_type).toBe('finished_good');
    expect(payload.channel_ids).toEqual(['chan-uuid']);
    expect(payload.is_active).toBe(true);
  });
});
