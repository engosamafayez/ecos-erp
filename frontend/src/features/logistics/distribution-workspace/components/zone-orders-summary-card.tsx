import { useTranslation } from 'react-i18next';
import { MapPin } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useFormatter } from '@/hooks/use-formatter';

/**
 * Zone Orders Summary — the approved card shown above the Orders table on every
 * Zone tab (TASK-DISTRIBUTION-ZONES-ORDERS-PANEL-UX-004).
 *
 *   📍 Nasr city & Masr Gedida                         View orders
 *      Group: DG-001
 *
 *      ORDERS      PRODUCTS      ORDER VALUE
 *      5           6             EGP 1,514.99
 *
 *      PAID        UNPAID / COD
 *      0           5
 *
 * A dumb, reusable presentational component: every figure is passed in from the
 * caller's canonical aggregate (the same `reviewZones` rollup the tab count uses)
 * — nothing is computed or fetched here. `onViewOrders` reuses the existing
 * ZoneOrdersDrawer behavior; it creates no new data source.
 */
export function ZoneOrdersSummaryCard({
  zoneName,
  groupLabel,
  spansSlots = false,
  orders,
  products,
  orderValue,
  paid,
  unpaid,
  onViewOrders,
  testId,
}: {
  zoneName: string;
  /** e.g. "DG-001" or "DG-001 — Morning". Null renders the "No group" line. */
  groupLabel: string | null;
  spansSlots?: boolean;
  orders: number;
  products: number;
  orderValue: number;
  paid: number;
  unpaid: number;
  onViewOrders?: () => void;
  testId?: string;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();

  return (
    <Card className="p-4" data-testid={testId}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-center gap-2">
          <MapPin className="size-4 text-muted-foreground" aria-hidden />
          <div>
            <h2 className="font-semibold">{zoneName}</h2>
            <span className="text-xs text-muted-foreground">
              {groupLabel
                ? t(($) => $.distributionWorkspace.zonePanel.group, { code: groupLabel })
                : t(($) => $.distributionWorkspace.zonePanel.noGroup)}
            </span>
            {spansSlots ? (
              <span className="block text-xs text-amber-600">
                {t(($) => $.distributionWorkspace.zonePanel.spansGroups)}
              </span>
            ) : null}
          </div>
        </div>
        {onViewOrders ? (
          <Button variant="outline" size="sm" onClick={onViewOrders} data-testid="zone-summary-view-orders">
            {t(($) => $.distributionWorkspace.zonePanel.viewOrders)}
          </Button>
        ) : null}
      </div>

      {/* ORDERS · PRODUCTS · ORDER VALUE */}
      <dl className="mt-3 grid grid-cols-3 gap-3">
        <Metric label={t(($) => $.distributionWorkspace.metrics.orders)} value={orders} />
        <Metric label={t(($) => $.distributionWorkspace.metrics.products)} value={products} />
        <Metric label={t(($) => $.distributionWorkspace.metrics.orderValue)} value={money(orderValue)} />
      </dl>

      {/* PAID · UNPAID / COD */}
      <dl className="mt-3 grid grid-cols-2 gap-3">
        <Metric label={t(($) => $.distributionWorkspace.metrics.paid)} value={paid} />
        <Metric label={t(($) => $.distributionWorkspace.metrics.unpaidCod)} value={unpaid} />
      </dl>
    </Card>
  );
}

function Metric({ label, value }: { label: string; value: string | number }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="font-semibold tabular-nums">{value}</dd>
    </div>
  );
}
