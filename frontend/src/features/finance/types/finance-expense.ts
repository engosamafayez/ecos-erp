/**
 * Finance Expenses types (TASK-ECOS-FINANCE-UX-REPORTING-CLOSURE-008).
 * Mirrors ExpenseController/ExpenseCategoryController payloads exactly
 * (Modules\Finance\Expenses, TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007).
 * Money values are numbers — never recalculated in the browser.
 */

export type ExpenseStatus = 'draft' | 'approved' | 'posted' | 'void';

export type ExpenseCategory = {
  id: string; // uuid
  name: string;
  expense_account_id: string | null; // account uuid
  is_active: boolean;
};

export type Expense = {
  id: string; // uuid
  expense_category_id: string | null; // category uuid
  number: string;
  expense_date: string | null;
  amount: number;
  currency: string;
  status: ExpenseStatus;
  source_type: string | null;
  source_id: string | null;
  journal_entry_id: number | null;
  approved_by: number | null; // user id — not a name
  approved_at: string | null;
  posted_at: string | null;
};

export type ExpenseListParams = { status?: ExpenseStatus };

export type ExpenseCreateInput = {
  expense_category_id: string;
  number: string;
  expense_date: string;
  amount: number;
  funding_account_id: string;
  currency?: string;
  description?: string;
};

export type ExpenseCategoryCreateInput = {
  name: string;
  expense_account_id: string;
};
