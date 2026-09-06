import { api } from '@/lib/axios';
import type {
  CrmPortfolioQuery,
  CrmPortfolioResult,
  CrmPortfolioRow,
} from '@/features/crm/types/crm-customer';
import type { ApiResponse } from '@/types';

/**
 * The CRM Portfolio API (TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003).
 *
 * `assignOwner` is NOT a `/crm/*` route — the ratified owner authority
 * (`customers.sales_owner_id`) is written through its owning module's own
 * endpoint (`Sales\Customers\CustomerController::assignOwner`), matching the
 * backend's "one write path, in the owning module" rule. The Portfolio only
 * reads the result.
 */
export const crmPortfolioService = {
  async list(params: CrmPortfolioQuery): Promise<CrmPortfolioResult> {
    const { data } = await api.get<{ data: CrmPortfolioRow[]; meta: CrmPortfolioResult['meta'] }>(
      '/crm/portfolio',
      { params },
    );

    return { data: data.data, meta: data.meta };
  },

  async assignOwner(
    customerId: string,
    salesOwnerId: string | null,
  ): Promise<{ sales_owner_id: string | null; sales_owner_name: string | null }> {
    const { data } = await api.patch<
      ApiResponse<{ sales_owner_id: string | null; sales_owner_name: string | null }>
    >(`/customers/${customerId}/sales-owner`, { sales_owner_id: salesOwnerId });

    return data.data;
  },
};
