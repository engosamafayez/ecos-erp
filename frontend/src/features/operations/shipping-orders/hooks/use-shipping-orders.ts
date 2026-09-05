import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { shippingOrdersService } from '../services/shipping-orders-service';
import type { ShippingOrdersQuery } from '../types/shipping-order';

export const SHIPPING_ORDERS_KEY = 'shipping-orders';

export function useShippingOrdersQuery(params: ShippingOrdersQuery) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';

  return useQuery({
    queryKey: ['company', companyId, SHIPPING_ORDERS_KEY, params],
    queryFn: () => shippingOrdersService.list(params),
    placeholderData: keepPreviousData,
  });
}
