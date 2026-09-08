import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';

import { PageHeader } from '@/components/crud';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import { ActiveTripsTab } from '../components/active-trips-tab';
import { AssignmentTab } from '../components/assignment-tab';
import { GroupsTab } from '../components/groups-tab';
import { LoadingBucketTab } from '../components/loading-bucket-tab';

const TAB_VALUES = ['groups', 'assignment', 'loading', 'handover', 'trips'] as const;
type DispatchExecutionTab = (typeof TAB_VALUES)[number];

function isDispatchExecutionTab(value: string | null): value is DispatchExecutionTab {
  return value !== null && (TAB_VALUES as readonly string[]).includes(value);
}

/**
 * Dispatch & Execution — TASK-ECOS-SHIPPING-OS-REDESIGN-001.
 *
 * A unified operational COORDINATION view above the existing Distribution
 * Workspace / Loading OS / Trips Workspace lifecycles — explicitly not a rebuild
 * of any of them. "UI consolidation does NOT mean domain-authority
 * consolidation": every tab reads through the exact existing hooks/services those
 * pages already use, and every deep action (assign vehicle, confirm loading,
 * etc.) stays on the full pages this one links out to. Nothing here is a second
 * engine, and no count is shown unless it is real — a tab that cannot honestly
 * filter its source data (see AssignmentTab) says so instead of inventing one.
 */
export function DispatchExecutionPage() {
  const { t } = useTranslation('dispatch-execution');
  const [searchParams, setSearchParams] = useSearchParams();

  const tabParam = searchParams.get('tab');
  const tab: DispatchExecutionTab = isDispatchExecutionTab(tabParam) ? tabParam : 'groups';

  function setTab(next: string) {
    const params = new URLSearchParams(searchParams);
    // 'groups' is the default — kept out of the URL so the no-param state and the
    // explicit-groups state are the same, single state rather than two.
    if (next === 'groups') {
      params.delete('tab');
    } else {
      params.set('tab', next);
    }
    setSearchParams(params, { replace: true });
  }

  return (
    <div className="flex flex-col gap-4 p-4">
      <PageHeader title={t($ => $.pageTitle)} subtitle={t($ => $.pageSubtitle)} />

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="groups">{t($ => $.tabs.groups)}</TabsTrigger>
          <TabsTrigger value="assignment">{t($ => $.tabs.assignment)}</TabsTrigger>
          <TabsTrigger value="loading">{t($ => $.tabs.loading)}</TabsTrigger>
          <TabsTrigger value="handover">{t($ => $.tabs.handover)}</TabsTrigger>
          <TabsTrigger value="trips">{t($ => $.tabs.trips)}</TabsTrigger>
        </TabsList>

        <TabsContent value="groups" className="pt-4">
          <GroupsTab />
        </TabsContent>

        <TabsContent value="assignment" className="pt-4">
          <AssignmentTab />
        </TabsContent>

        <TabsContent value="loading" className="pt-4">
          <LoadingBucketTab
            bucket="current_actionable"
            description={t($ => $.loading.description)}
            emptyLabel={t($ => $.loading.empty)}
          />
        </TabsContent>

        <TabsContent value="handover" className="pt-4">
          <LoadingBucketTab
            bucket="waiting_driver_confirmation"
            description={t($ => $.handover.description)}
            emptyLabel={t($ => $.handover.empty)}
          />
        </TabsContent>

        <TabsContent value="trips" className="pt-4">
          <ActiveTripsTab />
        </TabsContent>
      </Tabs>
    </div>
  );
}
