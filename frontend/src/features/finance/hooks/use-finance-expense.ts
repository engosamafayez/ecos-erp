import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';

import { financeExpenseService } from '../services/finance-expense-service';
import type { ExpenseCategoryCreateInput, ExpenseCreateInput, ExpenseListParams } from '../types/finance-expense';

/**
 * React-query hooks for the Finance Expenses workspace (TASK-ECOS-FINANCE-UX-
 * REPORTING-CLOSURE-008). Company-scoped keys, mirroring use-finance-gl.ts's
 * exact shape (query keys, mutation invalidation).
 */
function useCompanyId() {
  const { activeCompanyId } = useOrganizationContext();
  return activeCompanyId ?? 'global';
}

export function useExpenses(params: ExpenseListParams = {}) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'expenses', params],
    queryFn: () => financeExpenseService.list(params),
  });
}

export function useExpense(uuid: string | null) {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'expense', uuid],
    queryFn: () => financeExpenseService.get(uuid as string),
    enabled: Boolean(uuid),
  });
}

export function useCreateExpense() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: ExpenseCreateInput) => financeExpenseService.create(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'expenses'] }),
  });
}

export function useApproveExpense() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (uuid: string) => financeExpenseService.approve(uuid),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'expenses'] }),
  });
}

export function usePostExpense() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (uuid: string) => financeExpenseService.post(uuid),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'expenses'] }),
  });
}

export function useReverseExpensePosting() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ uuid, reason }: { uuid: string; reason: string }) =>
      financeExpenseService.reversePosting(uuid, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'expenses'] }),
  });
}

export function useExpenseCategories() {
  const companyId = useCompanyId();
  return useQuery({
    queryKey: ['company', companyId, 'finance', 'expense-categories'],
    queryFn: () => financeExpenseService.categories.list(),
    staleTime: 5 * 60 * 1000,
  });
}

export function useCreateExpenseCategory() {
  const companyId = useCompanyId();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: ExpenseCategoryCreateInput) => financeExpenseService.categories.create(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['company', companyId, 'finance', 'expense-categories'] }),
  });
}
