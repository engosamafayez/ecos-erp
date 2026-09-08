import { useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';

import { PageHeader } from '@/components/crud';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import { DriverSettlementTab } from '../components/driver-settlement-tab';
import { ExpectedReturnsTab } from '../components/expected-returns-tab';
import { ReturnDiscrepanciesTab } from '../components/return-discrepancies-tab';
import { ReturningToWarehouseTab } from '../components/returning-to-warehouse-tab';
import { TreasuryCashHandoverTab } from '../components/treasury-cash-handover-tab';
import { WarehouseReceiptTab } from '../components/warehouse-receipt-tab';

/**
 * Returns & Settlement workspace.
 *
 * TASK-ECOS-SHIPPING-OS-REDESIGN-001 — one of six new Shipping consolidation
 * shells. This workspace introduces NO new business logic and owns NO data of
 * its own: it sits above five existing, independently-authoritative engines
 * (driver-declared TripReturn, vehicle-shift reconciliation / warehouse
 * receipt, TripCashHandover, and the canonical Driver Day Settlement rollup)
 * and either shows their real data directly or deep-links to the existing
 * workspace that already renders it.
 *
 * Two tabs (Returning to Warehouse, Driver Settlement) show real, live data
 * through hooks reused unchanged from their owning feature folders. The other
 * four (Expected Returns, Warehouse Receipt, Discrepancies, Cash Handover)
 * have no operator-facing aggregate/list endpoint today — per the task's hard
 * constraint against fabricating a number or inventing a shortage/liability/
 * discrepancy rule client-side, each of those says so plainly (GapCard) and
 * links to the existing workspace where the real, per-trip/per-assignment
 * record already lives. See each tab component's own doc comment for the
 * exact backend reasoning, and the implementation report for the full list of
 * gaps flagged for a future backend task.
 */

const TAB_KEYS = [
  'expected-returns',
  'returning-to-warehouse',
  'warehouse-receipt',
  'discrepancies',
  'driver-settlement',
  'cash-handover',
] as const;

type TabKey = (typeof TAB_KEYS)[number];

const DEFAULT_TAB: TabKey = 'expected-returns';

function isTabKey(value: string | null): value is TabKey {
  return value !== null && (TAB_KEYS as readonly string[]).includes(value);
}

export function ReturnsSettlementPage() {
  const { t } = useTranslation('returns-settlement');
  const [searchParams, setSearchParams] = useSearchParams();

  const requestedTab = searchParams.get('tab');
  const tab: TabKey = isTabKey(requestedTab) ? requestedTab : DEFAULT_TAB;

  const handleTabChange = useCallback(
    (next: string) => {
      setSearchParams({ tab: next }, { replace: true });
    },
    [setSearchParams],
  );

  return (
    <div className="flex flex-col gap-4 p-4">
      <PageHeader title={t(($) => $.pageTitle)} subtitle={t(($) => $.pageSubtitle)} />

      <Tabs value={tab} onValueChange={handleTabChange}>
        <TabsList className="flex h-auto flex-wrap justify-start gap-1">
          <TabsTrigger value="expected-returns" data-testid="tab-expected-returns">
            {t(($) => $.tabs.expectedReturns)}
          </TabsTrigger>
          <TabsTrigger value="returning-to-warehouse" data-testid="tab-returning-to-warehouse">
            {t(($) => $.tabs.returningToWarehouse)}
          </TabsTrigger>
          <TabsTrigger value="warehouse-receipt" data-testid="tab-warehouse-receipt">
            {t(($) => $.tabs.warehouseReceipt)}
          </TabsTrigger>
          <TabsTrigger value="discrepancies" data-testid="tab-discrepancies">
            {t(($) => $.tabs.discrepancies)}
          </TabsTrigger>
          <TabsTrigger value="driver-settlement" data-testid="tab-driver-settlement">
            {t(($) => $.tabs.driverSettlement)}
          </TabsTrigger>
          <TabsTrigger value="cash-handover" data-testid="tab-cash-handover">
            {t(($) => $.tabs.cashHandover)}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="expected-returns" className="mt-3">
          <ExpectedReturnsTab />
        </TabsContent>
        <TabsContent value="returning-to-warehouse" className="mt-3">
          <ReturningToWarehouseTab />
        </TabsContent>
        <TabsContent value="warehouse-receipt" className="mt-3">
          <WarehouseReceiptTab />
        </TabsContent>
        <TabsContent value="discrepancies" className="mt-3">
          <ReturnDiscrepanciesTab />
        </TabsContent>
        <TabsContent value="driver-settlement" className="mt-3">
          <DriverSettlementTab />
        </TabsContent>
        <TabsContent value="cash-handover" className="mt-3">
          <TreasuryCashHandoverTab />
        </TabsContent>
      </Tabs>
    </div>
  );
}
