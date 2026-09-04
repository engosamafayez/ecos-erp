import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

import type {
  Expense,
  ExpenseCategory,
  ExpenseCategoryCreateInput,
  ExpenseCreateInput,
  ExpenseListParams,
} from '../types/finance-expense';

/**
 * Finance Expenses API client (TASK-ECOS-FINANCE-UX-REPORTING-CLOSURE-008),
 * against the canonical Task 7 endpoints. Unwraps the `{ data }` envelope.
 * No backend changes beyond what Task 7 already shipped.
 */
export const financeExpenseService = {
  async list(params: ExpenseListParams = {}): Promise<Expense[]> {
    const { data } = await api.get<ApiResponse<Expense[]>>('/finance/expenses', { params });
    return data.data;
  },

  async get(uuid: string): Promise<Expense> {
    const { data } = await api.get<ApiResponse<Expense>>(`/finance/expenses/${uuid}`);
    return data.data;
  },

  async create(input: ExpenseCreateInput): Promise<Expense> {
    const { data } = await api.post<ApiResponse<Expense>>('/finance/expenses', input);
    return data.data;
  },

  async approve(uuid: string): Promise<Expense> {
    const { data } = await api.patch<ApiResponse<Expense>>(`/finance/expenses/${uuid}/approve`);
    return data.data;
  },

  async post(uuid: string): Promise<Expense> {
    const { data } = await api.patch<ApiResponse<Expense>>(`/finance/expenses/${uuid}/post`);
    return data.data;
  },

  async reversePosting(uuid: string, reason: string): Promise<{ reversal_journal_id: string; expense_id: string }> {
    const { data } = await api.post<ApiResponse<{ reversal_journal_id: string; expense_id: string }>>(
      `/finance/expenses/${uuid}/reverse-posting`,
      { reason },
    );
    return data.data;
  },

  categories: {
    async list(): Promise<ExpenseCategory[]> {
      const { data } = await api.get<ApiResponse<ExpenseCategory[]>>('/finance/expense-categories');
      return data.data;
    },

    async create(input: ExpenseCategoryCreateInput): Promise<ExpenseCategory> {
      const { data } = await api.post<ApiResponse<ExpenseCategory>>('/finance/expense-categories', input);
      return data.data;
    },
  },
};
