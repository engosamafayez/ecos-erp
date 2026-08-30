// Pure line-calculation helpers for the Supplier Invoice editor. Kept in their own module (no React
// component export) so the editor component file stays Fast-Refresh clean.

/** Which kind of purchasable entity a line represents. Both are `Product` rows (product_type);
 * this only drives the type-filtered search + label — the line always resolves to one product_id. */
export type LineEntityType = 'product' | 'raw_material';

/** The `product_type` sent to the products search for each entity kind. */
export const ENTITY_PRODUCT_TYPE: Record<LineEntityType, string> = {
  product: 'finished_good',
  raw_material: 'raw_material',
};

/** One editable invoice line. `line_total` is kept in state so it can be edited directly (§5). */
export type InvoiceLineState = {
  entity_type: LineEntityType;
  product_id: string;
  product_name: string;
  quantity: string;
  unit_price: string;
  tax_rate: string;
  line_total: string;
};

// VAT defaults to 0% — ECOS tax/VAT policy is NOT activated (Tax/VAT architecture = DEFERRED).
// The backend honours the submitted rate (syncLines uses `tax_rate ?? 0`), so 0 here persists as 0.
export const EMPTY_LINE: InvoiceLineState = {
  entity_type: 'product',
  product_id: '',
  product_name: '',
  quantity: '1',
  unit_price: '',
  tax_rate: '0',
  line_total: '',
};

/** A fresh empty line of a given entity type (§4 — explicit Add Product / Add Raw Material). */
export function emptyLine(entityType: LineEntityType): InvoiceLineState {
  return { ...EMPTY_LINE, entity_type: entityType };
}

/** Parse a numeric input string, treating blanks / NaN as 0. */
export const parseNum = (s: string): number => {
  const v = parseFloat(s);
  return Number.isFinite(v) ? v : 0;
};

const round4 = (n: number): number => Math.round(n * 10000) / 10000;

/**
 * The canonical line formula, mirrored from the backend `syncLines()`:
 * line_total = qty × unit_price + tax − discount, tax = (qty × unit_price) × rate/100.
 * (Discount is 0 in this editor; the backend stays authoritative on save.)
 */
export function computeLineTotal(qty: number, price: number, taxRate: number): number {
  const sub = qty * price;
  return round4(sub + (sub * taxRate) / 100);
}

/** Inverse of the formula at fixed qty/tax — derives Unit Price from an edited Line Total (§5). */
export function deriveUnitPrice(lineTotal: number, qty: number, taxRate: number): number {
  if (qty <= 0) return 0;
  const denom = qty * (1 + taxRate / 100);
  return denom > 0 ? round4(lineTotal / denom) : 0;
}
