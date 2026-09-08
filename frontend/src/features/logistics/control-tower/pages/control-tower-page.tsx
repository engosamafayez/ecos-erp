import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';

import { PageHeader } from '@/components/crud';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import { OperationalView } from '../components/operational-view';
import { AnalyticsView } from '../components/analytics-view';

type ControlTowerTab = 'operational' | 'analytics';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-001 — Control Tower.
 *
 * The new primary landing page for the Shipping module: a management-by-
 * exception operational dashboard. It recreates no backend logic — every
 * number is either a real read from an existing canonical endpoint (see
 * `use-control-tower-kpis.ts` for exactly which one, per tile) or an honest
 * deep-link-only card with no fabricated count. Two views, switched by the
 * `?tab=` query param: the default "Operational" view, and the secondary
 * "Executive / Analytics" view (`?tab=analytics`) that folds in the four
 * pages whose standalone routes now redirect here.
 */
export function ControlTowerPage() {
  const { t } = useTranslation('control-tower');
  const [searchParams, setSearchParams] = useSearchParams();

  const tab: ControlTowerTab = searchParams.get('tab') === 'analytics' ? 'analytics' : 'operational';

  function setTab(next: ControlTowerTab) {
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (next === 'analytics') {
          params.set('tab', 'analytics');
        } else {
          params.delete('tab');
        }
        return params;
      },
      { replace: true },
    );
  }

  return (
    <div className="flex flex-col gap-4 p-4">
      <PageHeader title={t(($) => $.pageTitle)} subtitle={t(($) => $.subtitle)} />

      <Tabs value={tab} onValueChange={(v) => setTab(v as ControlTowerTab)}>
        <TabsList>
          <TabsTrigger value="operational" data-testid="control-tower-tab-operational">
            {t(($) => $.tabs.operational)}
          </TabsTrigger>
          <TabsTrigger value="analytics" data-testid="control-tower-tab-analytics">
            {t(($) => $.tabs.analytics)}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="operational" className="mt-4">
          <OperationalView />
        </TabsContent>

        <TabsContent value="analytics" className="mt-4">
          <AnalyticsView />
        </TabsContent>
      </Tabs>
    </div>
  );
}
