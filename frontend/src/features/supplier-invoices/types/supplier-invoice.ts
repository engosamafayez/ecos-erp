export type SupplierInvoiceStatus =
  | 'draft'
  | 'validated'
  | 'auto_processing'
  | 'posted'
  | 'failed'
  | 'cancelled';

export type SupplierInvoiceLine = {
  id: string;
  product_id: string;
  // V-5 settlement anchor — the receipt line this invoice line settles, if any (§15).
  goods_receipt_line_id: string | null;
  // 'finished_good' | 'raw_material' | 'packaging_material' — for edit-mode line entity typing.
  product_type: string | null;
  product: { id: string; name: string; sku: string } | null;
  description: string | null;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  tax_amount: number;
  discount_amount: number;
  line_total: number;
  landed_unit_cost: number | null;
  uom_name_snapshot: string | null;
  uom_symbol_snapshot: string | null;
};

/** Payment read-model — DERIVED from canonical AP allocations; never editable on the invoice (§9–§12). */
export type SupplierInvoicePaymentStatus = 'unpaid' | 'partially_paid' | 'paid';

/**
 * One posted supplier payment applied to this invoice's payable through the canonical AP allocation
 * authority (TASK-...-AP-PAYMENT-INTEGRATION-001). Read-only history — never written from this UI.
 */
export type SupplierInvoicePaymentHistoryEntry = {
  payment_number: string | null;
  payment_date: string | null;
  amount: number;
  payment_status: string | null;
};

export type SupplierInvoicePayment = {
  total: number;
  paid: number;
  remaining: number;
  payment_status: SupplierInvoicePaymentStatus;
  billed: boolean;
  bill_number: string | null;
  due_date: string | null;
  history: SupplierInvoicePaymentHistoryEntry[];
};

/** Read-only ordered → received → invoiced linkage for an anchored line (§15–§17). */
export type SupplierInvoiceReceiptLink = {
  line_id: string;
  product: string | null;
  goods_receipt_line_id: string;
  receipt_number: string | null;
  po_number: string | null;
  ordered_qty: number | null;
  received_qty: number | null;
  invoiced_qty: number;
};

/** An invoice attachment record (the canonical documents table; §3). */
export type SupplierInvoiceDocument = {
  id: string;
  name: string;
  mime_type: string | null;
  file_size: number | null;
  notes: string | null;
  uploaded_by: number | null;
  created_at: string | null;
};

export type SupplierInvoice = {
  id: string;
  invoice_number: string;
  supplier_invoice_ref: string | null;
  status: SupplierInvoiceStatus;
  status_label: string;
  status_color: string;
  invoice_date: string;
  due_date: string | null;
  delivery_date: string | null;
  currency: string;
  exchange_rate: number;
  subtotal: number;
  tax_total: number;
  freight_amount: number;
  additional_costs: number;
  discount_amount: number;
  grand_total: number;
  payment_terms: string | null;
  payment_terms_days: number | null;
  payment_method: string | null;
  notes: string | null;
  posting_log: string[] | null;
  posting_error: string | null;
  posted_at: string | null;
  auto_purchase_id: string | null;
  auto_receipt_id: string | null;
  supplier: { id: string; name: string } | null;
  warehouse: { id: string; name: string; code: string } | null;
  lines: SupplierInvoiceLine[];
  // Present on the detail (show) payload only — derived read-models (§9–§17).
  payment?: SupplierInvoicePayment;
  receipt_links?: SupplierInvoiceReceiptLink[];
  created_at: string | null;
  updated_at: string | null;
};

export type SupplierInvoiceLinePayload = {
  product_id: string;
  description?: string | null;
  quantity: number;
  unit_price: number;
  tax_rate?: number;
  discount_amount?: number;
  uom_id_snapshot?: string | null;
  uom_name_snapshot?: string | null;
  uom_symbol_snapshot?: string | null;
  notes?: string | null;
};

export type CreateSupplierInvoicePayload = {
  supplier_invoice_ref?: string | null;
  supplier_id: string;
  warehouse_id: string;
  invoice_date: string;
  due_date?: string | null;
  delivery_date?: string | null;
  currency?: string;
  exchange_rate?: number;
  freight_amount?: number;
  additional_costs?: number;
  discount_amount?: number;
  payment_terms?: string | null;
  payment_terms_days?: number | null;
  payment_method?: string | null;
  notes?: string | null;
  internal_notes?: string | null;
  lines: SupplierInvoiceLinePayload[];
};

export type SupplierInvoicesQuery = {
  search?: string;
  status?: SupplierInvoiceStatus | 'all';
  supplier_id?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
};

export type PaginationMeta = {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
};

export type SupplierInvoicesResult = {
  items: SupplierInvoice[];
  meta: PaginationMeta;
};
