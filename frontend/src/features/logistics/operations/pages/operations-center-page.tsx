import { Activity, AlertTriangle, ArrowUpCircle, Gauge, Layers, MapPin, XCircle } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { WorkspaceHeader } from '@/components/workspace/header/workspace-header';
import { WorkspacePage } from '@/components/page/layout/workspace-page';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ROUTES } from '@/router/routes';

import { useHealthOverview } from '../hooks/use-operations';
import { ExceptionsPanel } from '../components/exceptions-panel';
import { ResourcePoolsPanel } from '../components/resource-pools-panel';
import { CapacityPanel } from '../components/capacity-panel';
import { UtilisationPanel } from '../components/utilisation-panel';
// TASK-ECOS-V1.1-OPS-04-TASK2 — Control Tower additions. Extending the
// existing Operations Center rather than a second page, per this task's own
// instruction (§2): all four reuse the Task 1 read model exclusively.
import { ShippingExecutionPanel } from '../components/shipping-execution-panel';
import { CustodyReturnsPanel } from '../components/custody-returns-panel';
import { SettlementPanel } from '../components/settlement-panel';
import { ExternalCarrierPanel } from '../components/external-carrier-panel';

/**
 * Logistics Operations Center.
 *
 * Exception-driven, not inventory-driven (§7.1): the headline is what needs a
 * person, and the target is that this screen is boring when the operation is
 * healthy. Every number belongs to another module — Fleet, Drivers, Network or
 * Dispatch — and is reported here rather than recomputed, so no figure on this
 * page can disagree with the module that owns it.
 */
export function OperationsCenterPage() {
  const { t } = useTranslation('logistics');
  const navigate = useNavigate();
  const { data: health, refetch, isFetching } = useHealthOverview();

  const headline = health?.headline;

  const metrics = [
    {
      id: 'alerts',
      icon: XCircle,
      label: 'Critical Alerts',
      value: headline?.critical_alerts ?? 0,
      isLoading: !health,
      colorClass: 'text-destructive',
    },
    {
      id: 'exceptions',
      icon: AlertTriangle,
      label: 'Needs Attention',
      value: headline?.open_exceptions ?? 0,
      isLoading: !health,
      colorClass: 'text-amber-600',
    },
    {
      id: 'pools',
      icon: Layers,
      label: 'Unhealthy Pools',
      value: headline?.unhealthy_pools ?? 0,
      isLoading: !health,
      colorClass: 'text-amber-600',
    },
    {
      id: 'capacity',
      icon: Gauge,
      label: 'Exhausted Slots',
      value: headline?.exhausted_capacity_slots ?? 0,
      isLoading: !health,
    },
    {
      id: 'fieldable',
      // Vehicles and drivers paired: whichever runs out first limits the day.
      icon: Activity,
      label: 'Can Field Today',
      value: headline?.fieldable_units ?? 0,
      isLoading: !health,
      colorClass: 'text-emerald-600',
    },
    {
      id: 'escalations',
      icon: ArrowUpCircle,
      label: 'Overdue Escalations',
      value: headline?.overdue_escalations ?? 0,
      isLoading: !health,
      colorClass: 'text-destructive',
    },
  ];

  const needsAttention = (headline?.open_exceptions ?? 0) + (headline?.critical_alerts ?? 0);

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: 'Logistics OS' }, { label: 'Operations' }]}
        title="Operations Center"
        description="Resource pools, capacity, health and the exception queue"
        metrics={metrics}
      />

      <WorkspacePage
        toolbar={
          <div className="flex items-center justify-between gap-2 px-4 sm:px-6">
            <SmartToolbar onRefresh={() => refetch()} isFetching={isFetching} />
            {/* §13 — reuse the existing live-location authority; no new tracking
                engine or fake pin lives on this page. */}
            <Button
              size="sm"
              variant="outline"
              className="gap-1.5"
              onClick={() => navigate(ROUTES.shippingLiveDriverMap)}
            >
              <MapPin className="size-4" />
              {t($ => $.operations.controlTower.liveMap.action)}
            </Button>
          </div>
        }
      >
        <div className="px-4 pb-6 sm:px-6">
          {/* Land on the queue when something needs a person; otherwise show
              what the operation is made of. */}
          <Tabs defaultValue={needsAttention > 0 ? 'exceptions' : 'shipping'} className="w-full">
            <TabsList className="flex-wrap">
              <TabsTrigger value="exceptions">
                Exceptions{needsAttention > 0 ? ` (${needsAttention})` : ''}
              </TabsTrigger>
              <TabsTrigger value="shipping">{t($ => $.operations.controlTower.tabs.shipping)}</TabsTrigger>
              <TabsTrigger value="custody">{t($ => $.operations.controlTower.tabs.custody)}</TabsTrigger>
              <TabsTrigger value="settlement">{t($ => $.operations.controlTower.tabs.settlement)}</TabsTrigger>
              <TabsTrigger value="external-carrier">
                {t($ => $.operations.controlTower.tabs.externalCarrier)}
              </TabsTrigger>
              <TabsTrigger value="pools">Resource Pools</TabsTrigger>
              <TabsTrigger value="capacity">Capacity</TabsTrigger>
              <TabsTrigger value="utilisation">Utilisation</TabsTrigger>
            </TabsList>

            <TabsContent value="exceptions" className="pt-4">
              <ExceptionsPanel />
            </TabsContent>

            <TabsContent value="shipping" className="pt-4">
              <ShippingExecutionPanel />
            </TabsContent>

            <TabsContent value="custody" className="pt-4">
              <CustodyReturnsPanel />
            </TabsContent>

            <TabsContent value="settlement" className="pt-4">
              <SettlementPanel />
            </TabsContent>

            <TabsContent value="external-carrier" className="pt-4">
              <ExternalCarrierPanel />
            </TabsContent>

            <TabsContent value="pools" className="pt-4">
              <ResourcePoolsPanel />
            </TabsContent>

            <TabsContent value="capacity" className="pt-4">
              <CapacityPanel />
            </TabsContent>

            <TabsContent value="utilisation" className="pt-4">
              <UtilisationPanel />
            </TabsContent>
          </Tabs>
        </div>
      </WorkspacePage>
    </>
  );
}
