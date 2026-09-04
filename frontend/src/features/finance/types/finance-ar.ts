/**
 * Accounts Receivable types (EPIC-FINANCE-UI-001 Phase 4). Mirror the certified AR API
 * shapes exactly. Read-only. Money values are numbers (EGP default) — never recalculated
 * client-side. NOTE: AR payloads expose only `customer_id` (uuid); the API carries no
 * customer name (a documented backend gap).
 */

export type DocumentStatus = 'draft' | 'posted' | 'void';
export type CustomerDocumentType = 'invoice' | 'credit_note' | 'debit_note';
export type CustomerLedgerEntryType =
  | 'invoice'
  | 'credit_note'
  | 'debit_note'
  | 'receipt'
  | 'write_off';

export const AGING_BUCKETS = ['current', '1_30', '31_60', '61_90', '90_plus'] as const;
export type AgingBucket = (typeof AGING_BUCKETS)[number];

export type AgingTotals = Record<AgingBucket, number> & { total: number };
export type AgingCustomerRow = { customer_id: string } & AgingTotals;

export type ArAging = {
  as_of: string;
  buckets: AgingBucket[];
  customers: AgingCustomerRow[];
  totals: AgingTotals;
};

export type ArInvoice = {
  id: string; // uuid
  customer_id: string;
  document_type: CustomerDocumentType;
  number: string;
  invoice_date: string | null;
  due_date: string | null;
  currency: string;
  subtotal: number;
  tax_total: number;
  total: number;
  status: DocumentStatus;
  outstanding: number | null; // null unless posted
  journal_entry_id: number | null;
  posted_at: string | null;
  lines: ArInvoiceLine[] | null;
};

export type ArInvoiceLine = {
  revenue_account_id: string;
  description: string | null;
  quantity: number;
  unit_price: number;
  net_amount: number;
  tax_code_id: number | null;
  tax_amount: number;
  cost_center_id: number | null;
  branch_id: string | null;
};

export type ArReceipt = {
  id: string; // uuid
  customer_id: string;
  number: string;
  receipt_date: string | null;
  amount: number;
  currency: string;
  status: DocumentStatus;
  unallocated: number | null; // null unless posted
  journal_entry_id: number | null;
  posted_at: string | null;
  /**
   * Traces a receipt back to the event that created it (e.g. a COD collection
   * settled to the "Cash in Transit" clearing account — TASK-ECOS-FINANCE-
   * COMMERCIAL-ACCOUNTING-006). Null for an ordinary manually-entered receipt.
   */
  source_type: string | null;
  source_id: string | null;
};

export type CustomerLedgerLine = {
  id: number;
  uuid: string;
  entry_date: string;
  entry_type: CustomerLedgerEntryType;
  description: string | null;
  debit: number;
  credit: number;
  amount: number;
  running_balance: number;
  journal_entry_id: number | null;
};

export type CustomerLedger = {
  customer_id: string;
  opening_balance: number;
  closing_balance: number;
  lines: CustomerLedgerLine[];
};

export type ArControlReconciliation = {
  subledger: string;
  control_account: { id: number; code: string; name: string };
  gl_balance: number;
  subledger_balance: number;
  difference: number;
  is_reconciled: boolean;
};

export type ArInvoiceParams = { customer_id?: string; status?: DocumentStatus };
export type ArReceiptParams = { customer_id?: string };

// ── Allocation & reversal (write actions) ─────────────────────────────────────
// Mirrors the certified AllocationEngine / Posting Engine contract exposed by
// CustomerReceiptController::allocate/autoAllocate/reverseAllocation/reversePosting.
// Allocation is a pure subledger relationship (never posts a journal); reverse-posting
// reverses the receipt's journal AND its customer-ledger entry together.

export type ArAllocation = {
  id: string; // allocation uuid
  receipt_id: string;
  invoice_id: string;
  amount: number;
  receipt_unallocated: number;
  invoice_outstanding: number;
};

export type ArAutoAllocateResult = {
  allocations: number;
  receipt_unallocated: number;
};

/** An append-only, negative contra-allocation reversing part (or all) of one prior allocation. */
export type ArAllocationReversal = {
  id: string; // the new reversal row's uuid
  reverses_allocation_id: string;
  receipt_id: string;
  customer_invoice_id: string | null;
  amount: number;
  reason: string;
  receipt_unallocated: number;
};

export type ArReversePostingResult = {
  reversal_journal_id: string;
  reverses_journal_id: string | null;
  receipt_id: string;
};
