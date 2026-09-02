import { useTranslation } from 'react-i18next';

import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { WorkspacePage } from '@/components/page';
import { WorkspaceHeader } from '@/components/workspace';

import { CashFlowTab } from '../components/cash-flow-tab';
import { CostAllocationTab } from '../components/cost-allocation-tab';
import { CostIntelligenceTab } from '../components/cost-intelligence-tab';
import { DriverLedgerTab } from '../components/driver-ledger-tab';
import { ProfitabilityTab } from '../components/profitability-tab';

/**
 * Finance — Costing & Profitability (TASK-ECOS-FINANCE-UX-REPORTING-
 * CLOSURE-008). Assembles five independently-built, independently
 * permission-gated tabs — each renders its own access-denied state when the
 * signed-in user lacks its specific permission (finance.cost_allocation.view,
 * finance.driver.view, finance.analytics.view), so this page itself applies
 * no single top-level gate; there is no one permission that covers every
 * capability here.
 *
 * Cost Allocation and the Driver Ledger are Task 7's own new Finance
 * capabilities (Modules\Finance\CostAllocation, DriverLedgerEntry); the
 * Profitability / Cost Intelligence / Cash Flow tabs consume the existing
 * F5 Intelligence read-model backend, which had zero frontend consumers
 * before this task.
 */
export function CostingProfitabilityPage() {
  const { t } = useTranslation('finance');

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: t(($) => $.breadcrumb.finance) }, { label: t(($) => $.costing.title) }]}
        title={t(($) => $.costing.title)}
        description={t(($) => $.costing.subtitle)}
      />
      <WorkspacePage>
        <Tabs defaultValue="profitability">
          <TabsList>
            <TabsTrigger value="profitability">{t(($) => $.costing.tab.profitability)}</TabsTrigger>
            <TabsTrigger value="cost-intelligence">{t(($) => $.costing.tab.costIntelligence)}</TabsTrigger>
            <TabsTrigger value="cash-flow">{t(($) => $.costing.tab.cashFlow)}</TabsTrigger>
            <TabsTrigger value="cost-allocation">{t(($) => $.costing.tab.costAllocation)}</TabsTrigger>
            <TabsTrigger value="driver-ledger">{t(($) => $.costing.tab.driverLedger)}</TabsTrigger>
          </TabsList>

          <TabsContent value="profitability" className="mt-4"><ProfitabilityTab /></TabsContent>
          <TabsContent value="cost-intelligence" className="mt-4"><CostIntelligenceTab /></TabsContent>
          <TabsContent value="cash-flow" className="mt-4"><CashFlowTab /></TabsContent>
          <TabsContent value="cost-allocation" className="mt-4"><CostAllocationTab /></TabsContent>
          <TabsContent value="driver-ledger" className="mt-4"><DriverLedgerTab /></TabsContent>
        </Tabs>
      </WorkspacePage>
    </>
  );
}

export default CostingProfitabilityPage;
