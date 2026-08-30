// Receiving Center — Purchase-Order-driven receiving queue
// (TASK-PROCUREMENT-PO-DRIVEN-RECEIVING-CENTER-001).
//
// The Receiving Center is a work queue of RECEIVABLE PURCHASE ORDERS. Receiving delegates to the
// certified Goods Receipt authority (Create + Post) server-side; the frontend never posts stock and
// never edits Purchase Order quantities — it records only the actual "receive now" per line.

export type ReceivingScope = 'active' | 'history';

export interface ReceivingRef {
  id: string;
  code: string;
  name: string;
}

export interface ReceivingKpis {
  awaiting: number;
  partial: number;
  received: number;
}

/** One receivable Purchase Order in the queue, with canonical expected/received aggregates. */
export interface ReceivingQueueRow {
  id: string;
  po_number: string;
  supplier: ReceivingRef | null;
  warehouse: ReceivingRef | null;
  order_date: string | null;
  expected_date: string | null;
  product_count: number;
  expected_qty: number;
  received_qty: number;
  remaining_qty: number;
  received_pct: number;
  status: string;
  status_label: string;
}

export interface ReceivingQueueMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface ReceivingQueueResponse {
  scope: ReceivingScope;
  kpis: ReceivingKpis;
  items: ReceivingQueueRow[];
  meta: ReceivingQueueMeta;
}

export interface ReceivingQueueParams {
  scope: ReceivingScope;
  search?: string;
  supplier_id?: string;
  warehouse_id?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

export interface ReceivingPoLine {
  id: string;
  product_id: string;
  product_name: string | null;
  product_sku: string | null;
  ordered_qty: number;
  received_qty: number;
  remaining_qty: number;
}

export interface ReceivingPoDetail {
  id: string;
  po_number: string;
  supplier: ReceivingRef | null;
  warehouse: ReceivingRef | null;
  order_date: string | null;
  status: string;
  status_label: string;
  can_receive: boolean;
  lines: ReceivingPoLine[];
}

/** Payload for POST /receiving/purchase-orders/{po}/receive — actual quantity per line. */
export interface ReceiveLineInput {
  purchase_order_line_id: string;
  receive_now: number;
}
