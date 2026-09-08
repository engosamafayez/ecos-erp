import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { History } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ROUTES } from '@/router/routes';

import { EnterpriseWorkspacePage } from '@/features/logistics/operations/pages/enterprise-workspace-page';
import { OperationalDashboardsPage } from '@/features/logistics/operations/pages/operational-dashboards-page';
import { LogisticsIntelligencePage } from '@/features/logistics/intelligence/pages/logistics-intelligence-page';
import { EnterpriseReadinessPage } from '@/features/logistics/operations/pages/enterprise-readiness-page';

type AnalyticsTab = 'enterprise' | 'dashboards' | 'intelligence' | 'readiness';

/**
 * Executive / Analytics — `?tab=analytics`.
 *
 * Absorbs the four old standalone pages whose routes now redirect here
 * (Enterprise Workspace, Dashboards, Intelligence, Enterprise Readiness).
 * Each renders VERBATIM — same component, same hooks, same data — re-homed
 * under this tab rather than rebuilt; their own internal PageHeader/title is
 * accepted nesting redundancy for this foundation task, per the task spec.
 * Deliberately secondary: this whole view only renders behind the tab
 * switch, never by default.
 */
export function AnalyticsView() {
  const { t } = useTranslation('control-tower');
  const navigate = useNavigate();
  const [tab, setTab] = useState<AnalyticsTab>('enterprise');

  return (
    <div className="space-y-4 rounded-lg border border-dashed bg-muted/20 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-base font-semibold text-muted-foreground">
            {t(($) => $.analytics.sectionTitle)}
          </h2>
          <p className="text-xs text-muted-foreground">{t(($) => $.analytics.sectionHint)}</p>
        </div>

        {/* Activity & Audit — a contextual history/audit entry point, not primary nav. */}
        <Button
          variant="outline"
          size="sm"
          className="gap-1.5"
          onClick={() => navigate(ROUTES.logisticsOpsActivity)}
          data-testid="control-tower-activity-link"
        >
          <History className="size-3.5" aria-hidden />
          {t(($) => $.analytics.activityLink)}
        </Button>
      </div>

      <Tabs value={tab} onValueChange={(v) => setTab(v as AnalyticsTab)}>
        <TabsList className="flex-wrap">
          <TabsTrigger value="enterprise" data-testid="analytics-tab-enterprise">
            {t(($) => $.analytics.tabs.enterpriseWorkspace)}
          </TabsTrigger>
          <TabsTrigger value="dashboards" data-testid="analytics-tab-dashboards">
            {t(($) => $.analytics.tabs.dashboards)}
          </TabsTrigger>
          <TabsTrigger value="intelligence" data-testid="analytics-tab-intelligence">
            {t(($) => $.analytics.tabs.intelligence)}
          </TabsTrigger>
          <TabsTrigger value="readiness" data-testid="analytics-tab-readiness">
            {t(($) => $.analytics.tabs.readiness)}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="enterprise" className="mt-3">
          <EnterpriseWorkspacePage />
        </TabsContent>
        <TabsContent value="dashboards" className="mt-3">
          <OperationalDashboardsPage />
        </TabsContent>
        <TabsContent value="intelligence" className="mt-3">
          <LogisticsIntelligencePage />
        </TabsContent>
        <TabsContent value="readiness" className="mt-3">
          <EnterpriseReadinessPage />
        </TabsContent>
      </Tabs>
    </div>
  );
}
