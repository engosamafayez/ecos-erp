import { Building2, GitBranch, Globe, Warehouse } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { PageHeader } from '@/components/crud';
import { WorkspaceCard } from '@/components/layout/workspace-card';
import { useBranchesQuery } from '@/features/branches/hooks/use-branches';
import { useChannelsQuery } from '@/features/channels/hooks/use-channels';
import { useCompaniesQuery } from '@/features/companies/hooks/use-companies';
import { useWarehousesQuery } from '@/features/warehouses/hooks/use-warehouses';
import { ROUTES } from '@/router/routes';

const COUNT_PARAMS = { page: 1, per_page: 1 } as const;

export function OrganizationWorkspace() {
  const { t: tCommon } = useTranslation('common');
  const { t: tCo } = useTranslation('companies');
  const { t: tBr } = useTranslation('branches');
  const { t: tWh } = useTranslation('warehouses');
  const { t: tCh } = useTranslation('channels');

  const { data: companiesData, isLoading: companiesLoading } = useCompaniesQuery(COUNT_PARAMS);
  const { data: branchesData, isLoading: branchesLoading } = useBranchesQuery(COUNT_PARAMS);
  const { data: warehousesData, isLoading: warehousesLoading } = useWarehousesQuery(COUNT_PARAMS);
  const { data: channelsData, isLoading: channelsLoading } = useChannelsQuery(COUNT_PARAMS);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Organization"
        subtitle="Manage your companies, branches, warehouses, and sales channels"
        breadcrumbs={[{ label: tCommon('home'), to: ROUTES.dashboard }, { label: 'Organization' }]}
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <WorkspaceCard
          icon={Building2}
          title={tCo('title')}
          description={tCo('subtitle')}
          count={companiesData?.meta.total}
          countLabel="total"
          href={ROUTES.companies}
          isLoading={companiesLoading}
        />
        <WorkspaceCard
          icon={GitBranch}
          title={tBr('title')}
          description={tBr('subtitle')}
          count={branchesData?.meta.total}
          countLabel="total"
          href={ROUTES.branches}
          isLoading={branchesLoading}
        />
        <WorkspaceCard
          icon={Warehouse}
          title={tWh('title')}
          description={tWh('subtitle')}
          count={warehousesData?.meta.total}
          countLabel="total"
          href={ROUTES.warehouses}
          isLoading={warehousesLoading}
        />
        <WorkspaceCard
          icon={Globe}
          title={tCh('title')}
          description={tCh('subtitle')}
          count={channelsData?.meta.total}
          countLabel="total"
          href={ROUTES.channels}
          isLoading={channelsLoading}
        />
      </div>
    </div>
  );
}
