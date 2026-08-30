// Driver Trip Expenses — TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §30–§43.
// Mirrors the DriverTripExpenseController payload. Operational cash movements only; never GL.

export type TripExpenseCategory = 'fuel' | 'road_toll' | 'advance' | 'other';
export type TripExpenseDirection = 'cash_out' | 'cash_in';
export type TripExpenseStatus = 'pending' | 'approved' | 'rejected' | 'settled';

/** The four approved categories, in display order. Advance is a cash-IN (not an expense). */
export const TRIP_EXPENSE_CATEGORIES: TripExpenseCategory[] = ['fuel', 'road_toll', 'advance', 'other'];

export interface TripExpense {
  id: string;
  category: TripExpenseCategory;
  direction: TripExpenseDirection;
  /** True for cash-out categories (fuel/toll/other); false for an advance (§32). */
  is_expense: boolean;
  amount: number;
  note: string | null;
  status: TripExpenseStatus;
  occurred_at: string | null;
  created_at: string | null;
  has_receipt: boolean;
}

/** Approved-only operational totals (§41/§42) — advance is never folded into expenses. */
export interface TripExpenseTotals {
  approved_expenses: number;
  approved_advances: number;
  pending_count: number;
  net_movement: number;
}

export interface TripExpensesResponse {
  has_active_custody: boolean;
  trip: { id: string; trip_number: string | null } | null;
  items: TripExpense[];
  totals: TripExpenseTotals;
}

export interface CreateTripExpenseInput {
  category: TripExpenseCategory;
  amount: number;
  note?: string;
  /** Optional ISO datetime; the server defaults to now when omitted. */
  occurred_at?: string;
  receipt?: File | null;
}
