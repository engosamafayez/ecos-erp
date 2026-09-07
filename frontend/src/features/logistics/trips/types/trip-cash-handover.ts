// Treasury physical cash handover — the second-actor confirmation step
// (TASK-ECOS-DRIVER-SETTLEMENT-TREASURY-FINAL-IMPLEMENTATION-002).
//
// Kept in its own file rather than merged into `trip-settlement.ts` to avoid
// touching that existing, working file at all — see the implementation
// report's "Shared Files Avoided" section.

export interface CashHandoverAccountOption {
  /** CashAccount uuid — the only identifier ever sent back to the API. */
  id: string;
  code: string;
  name: string;
}

/** A. Driver Declared Cash / B. System Expected Cash / any existing handover, in one read. */
export interface CashHandoverContext {
  expected_cash: number;
  driver_declared_cash: number | null;
  handover: TripCashHandover | null;
  cash_accounts: CashHandoverAccountOption[];
}

/** C. Treasury Physically Received Cash — the confirmed, posted fact. */
export interface TripCashHandover {
  id: number;
  uuid: string;
  trip_id: number;
  trip_settlement_id: number;

  driver_declared_cash: number | null;
  expected_cash: number;
  received_cash: number;
  difference: number;
  is_exact: boolean;
  is_short: boolean;
  is_over: boolean;

  cash_account_id: number;
  cash_transaction_id: number | null;

  received_by: number;
  confirmed_at: string;
  notes: string | null;
}

export interface ConfirmCashHandoverPayload {
  received_cash: number;
  cash_account_id: string;
  notes?: string | null;
}
