import {
  CheckCircle2,
  Coins,
  CreditCard,
  Package,
  PackageX,
  Percent,
  ReceiptText,
  Wallet,
  type LucideIcon,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Skeleton } from '@/components/ui/skeleton';
import { useFormatter } from '@/hooks/use-formatter';
import type { DaySettlementKpis } from '../types/driver-settlement';

const GRID = 'grid grid-cols-2 gap-3 md:grid-cols-4';

function KpiCard({ icon: Icon, label, value, tone }: { icon: LucideIcon; label: string; value: string; tone: string }) {
  return (
    <div className="flex items-center gap-3 rounded-lg border bg-card p-3">
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-md ${tone}`}>
        <Icon className="h-5 w-5" />
      </span>
      <div className="min-w-0">
        <p className="text-[11px] uppercase leading-tight tracking-wide text-muted-foreground">{label}</p>
        <p className="mt-0.5 break-words text-base font-semibold leading-tight tabular-nums">{value}</p>
      </div>
    </div>
  );
}

/**
 * The 8 canonical operational KPI cards (TASK-...-KPI-TABLE-REALDATA-CORRECTION-001, §2) over the
 * currently-visible Active custodies. Every value is server-aggregated from canonical row data;
 * Expenses and Net Cash are honest "Not available" when no canonical cash-movement authority exists
 * (§10/§11/§12) — never a fabricated zero. States stay distinct: Loading / Error / Loaded. Responsive
 * 2-col on mobile → 4-col from md, so nothing scrolls horizontally or clips in Arabic (§31).
 */
export function DaySettlementKpiCards({
  kpis,
  loading,
  error,
}: {
  kpis?: DaySettlementKpis;
  loading?: boolean;
  error?: boolean;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();

  // Error precedes loading: a failed read must not masquerade as "still loading" or as zeros (§12).
  if (error) {
    return (
      <div className={GRID} data-testid="kpi-error" aria-label={t(($) => $.driverSettlement.loadError)}>
        {Array.from({ length: 8 }, (_, i) => (
          <div
            key={i}
            className="flex h-[60px] items-center justify-center rounded-lg border border-dashed bg-muted/20 text-muted-foreground"
          >
            <span className="text-lg leading-none">&mdash;</span>
          </div>
        ))}
      </div>
    );
  }

  if (loading || !kpis) {
    return (
      <div className={GRID} data-testid="kpi-loading">
        {Array.from({ length: 8 }, (_, i) => (
          <Skeleton key={i} className="h-[60px] rounded-lg" />
        ))}
      </div>
    );
  }

  const na = t(($) => $.driverSettlement.notAvailable);
  // Real canonical zero → EGP 0.00; no canonical authority (null) → "Not available" (§12).
  const moneyOrNa = (v: number | null): string => (v === null ? na : money(v));

  return (
    <div className={GRID}>
      <KpiCard
        icon={Package}
        tone="bg-primary/10 text-primary"
        label={t(($) => $.driverSettlement.kpis.totalOrders)}
        value={String(kpis.total_orders)}
      />
      <KpiCard
        icon={CheckCircle2}
        tone="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
        label={t(($) => $.driverSettlement.kpis.totalDelivered)}
        value={String(kpis.total_delivered)}
      />
      <KpiCard
        icon={PackageX}
        tone="bg-destructive/10 text-destructive"
        label={t(($) => $.driverSettlement.kpis.totalFailed)}
        value={String(kpis.total_failed)}
      />
      <KpiCard
        icon={Percent}
        tone="bg-blue-500/10 text-blue-600 dark:text-blue-400"
        label={t(($) => $.driverSettlement.kpis.deliveryRate)}
        value={`${kpis.delivery_rate}%`}
      />
      <KpiCard
        icon={Coins}
        tone="bg-amber-500/10 text-amber-600 dark:text-amber-400"
        label={t(($) => $.driverSettlement.kpis.totalSales)}
        value={money(kpis.total_sales)}
      />
      <KpiCard
        icon={CreditCard}
        tone="bg-indigo-500/10 text-indigo-600 dark:text-indigo-400"
        label={t(($) => $.driverSettlement.kpis.transfersPaid)}
        value={money(kpis.total_transfers_paid)}
      />
      <KpiCard
        icon={ReceiptText}
        tone="bg-muted text-muted-foreground"
        label={t(($) => $.driverSettlement.kpis.expenses)}
        value={moneyOrNa(kpis.total_expenses)}
      />
      <KpiCard
        icon={Wallet}
        tone="bg-muted text-muted-foreground"
        label={t(($) => $.driverSettlement.kpis.netCash)}
        value={moneyOrNa(kpis.net_cash)}
      />
    </div>
  );
}
