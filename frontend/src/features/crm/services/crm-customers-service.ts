import { api } from '@/lib/axios';
import type {
  CrmCustomer,
  CrmCustomerGroup,
  CrmCustomersMeta,
  CrmCustomersQuery,
  CrmCustomersResult,
} from '@/features/crm/types/crm-customer';
import type { ApiResponse } from '@/types';

/**
 * The CRM customer API.
 *
 * Note the response shape: GET /crm/customers returns `data` and `meta` as
 * SIBLINGS at the top level, not a result object nested under `data`. That
 * differs from the Sales `/customers` endpoint, so the envelope is unwrapped
 * here rather than assumed to match.
 */
export const crmCustomersService = {
  async list(params: CrmCustomersQuery): Promise<CrmCustomersResult> {
    const { data } = await api.get<{ data: CrmCustomer[]; meta: CrmCustomersMeta }>(
      '/crm/customers',
      { params },
    );

    return { data: data.data, meta: data.meta };
  },

  async get(id: string): Promise<CrmCustomer> {
    const { data } = await api.get<ApiResponse<CrmCustomer>>(`/crm/customers/${id}`);
    return data.data;
  },

  async groups(): Promise<CrmCustomerGroup[]> {
    const { data } = await api.get<ApiResponse<CrmCustomerGroup[]>>('/crm/customers/groups');
    return data.data;
  },

  /** Archive is a state change, not a delete — the record stays for history. */
  async archive(id: string): Promise<void> {
    await api.patch(`/crm/customers/${id}/archive`);
  },
};
