/**
 * Finance Driver Ledger types (Costing & Profitability).
 * Mirrors DriverLedgerController payloads exactly
 * (Modules\Finance\Presentation\Http\Controllers\DriverLedgerController).
 *
 * Read-only in the Finance frontend: entries are created only by backend-side
 * automatic postings, never authored here. `driverId` is an opaque reference
 * — Finance has no driver directory/picker.
 *
 * Sign convention: `advance` and `shortage` increase what the driver owes
 * (positive amount); `expense` and `settlement` decrease it (negative
 * amount). A positive `balance`/`running_balance` means the driver owes the
 * company. Money values are numbers — never recalculated in the browser.
 */

export type DriverLedgerEntryType = 'advance' | 'expense' | 'shortage' | 'settlement';

export type DriverLedgerEntry = {
  id: string;
  entry_date: string;
  entry_type: DriverLedgerEntryType;
  amount: number;
  running_balance: number;
  source_type: string | null;
  source_id: string | null;
  journal_entry_id: string | null;
  description: string | null;
};

export type DriverLedger = {
  driver_id: string;
  balance: number;
  entries: DriverLedgerEntry[];
};

export type DriverBalance = {
  driver_id: string;
  balance: number;
};

export type DriverLedgerParams = { from?: string; to?: string };
