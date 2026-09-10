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
  // V-5 settlement anchor (§9, remediation-004) — the Goods Receipt Line this line explicitly
  // settles. Null until the user picks one (or the line was created from a receipt); never
  // inferred from product+qty.
  goods_receipt_line_id: string | null;
};

// VAT defaults to 0% — ECOS tax/VAT policy is NOT activated (Tax/VAT architecture = DEFERRED).
// The backend honours the submitted rate (syncLines uses `tax_rate ?? 0`), so 0 here persists as 0.
//
// TASK-...-020 §1/§2 — the initial line (and every line `emptyLine()` below adds) starts as
// Raw Material, never Product, and `quantity` starts blank, never `'1'`: a real quantity must
// always be a deliberate keystroke, never an artifact of the line simply existing. Applies
// identically to "Add Raw Material" and "Add Product" since both call `emptyLine()`, which only
// overrides `entity_type` — every other default, including the blank quantity, is shared.
export const EMPTY_LINE: InvoiceLineState = {
  entity_type: 'raw_material',
  product_id: '',
  product_name: '',
  quantity: '',
  unit_price: '',
  tax_rate: '0',
  line_total: '',
  goods_receipt_line_id: null,
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

/**
 * §14 — the approved landed-cost rule, mirrored here ONLY as a live, pre-post PREVIEW while the
 * invoice is still being drafted: `allocated_extra_per_unit = (freight + additional) /
 * total_invoice_qty`, one uniform per-unit rate shared by every line. This is deliberately NOT a
 * competing accounting authority — the backend's `LandedCostAllocator` (cent-exact,
 * largest-remainder allocation across lines) remains the sole persisted, binding value, stamped
 * only once the invoice actually posts (`PostSupplierInvoiceService::allocateLandedCosts`).
 * Once that persisted value exists on a line (`landedUnitCost` below), it always wins over this
 * preview.
 */
export function extraPerUnitPreview(lines: readonly { quantity: string }[], freight: number, additionalCosts: number): number {
  const totalQty = lines.reduce((s, l) => s + Math.max(parseNum(l.quantity), 0), 0);
  return totalQty > 0 ? round4((freight + additionalCosts) / totalQty) : 0;
}

/** Final Unit Cost for one line — the real posted value once it exists, else the live preview. */
export function finalUnitCostFor(unitPrice: number, extraPerUnit: number, landedUnitCost: number | null): number {
  return landedUnitCost ?? round4(unitPrice + extraPerUnit);
}
