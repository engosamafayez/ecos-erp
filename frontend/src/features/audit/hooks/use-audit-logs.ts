import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { auditService } from '@/features/audit/services/audit-service';
import type { AuditLogListParams } from '@/features/audit/types/audit-log';

const KEY = 'audit-logs';

export function useAuditLogsQuery(params: AuditLogListParams) {
  const { activeCompanyId } = useOrganizationContext();
  const companyId = activeCompanyId ?? 'global';

  return useQuery({
    queryKey: ['company', companyId, KEY, params],
    queryFn: () => auditService.list(params),
    placeholderData: keepPreviousData,
  });
}
