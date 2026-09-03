import type { ReactNode } from 'react';
import {
  CheckCircle2,
  Coins,
  HandCoins,
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
const SHELL = 'flex min-h-[84px] items-start gap-3 rounded-lg border bg-card p-3';

function CardShell({
  icon: Icon,
  tone,
  label,
  children,
}: {
  icon: LucideIcon;
  tone: string;
  label: string;
  children: ReactNode;
}) {
  return (
    <div className={SHELL}>
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-md ${tone}`}>
        <Icon className="h-5 w-5" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-[11px] uppercase leading-tight tracking-wide text-muted-foreground">{label}</p>
        {children}
      </div>
    </div>
  );
}

/**
 * One metric: a primary value with an optional monetary sub-value beneath it, an optional canonical
 * breakdown line and an optional scope note. The count stays visually primary; the money is smaller
 * but full-contrast-readable (§4).
 */
function KpiCard({
  icon,
  tone,
  label,
  value,
  sub,
  breakdown,
  note,
  valueTone,
}: {
  icon: LucideIcon;
  tone: string;
  label: string;
  value: string;
  sub?: string;
  breakdown?: string;
  note?: string;
  valueTone?: string;
}) {
  return (
    <CardShell icon={icon} tone={tone} label={label}>
      <p className={`mt-0.5 break-words text-base font-semibold leading-tight tabular-nums ${valueTone ?? ''}`}>
        {value}
      </p>
      {sub ? <p className="mt-0.5 break-words text-xs leading-tight tabular-nums text-foreground/70">{sub}</p> : null}
      {breakdown ? (
        <p className="mt-1 break-words text-[10px] leading-snug tabular-nums text-muted-foreground">{breakdown}</p>
      ) : null}
      {note ? <p className="mt-1 text-[10px] leading-snug text-muted-foreground">{note}</p> : null}
    </CardShell>
  );
}

type SplitPart = { label: string; value: string; tone?: string };

/**
 * TWO independent financial facts inside ONE card (§7). They are stacked and separately labelled —
 * never added, netted or otherwise combined into a single number, because Transfers/Paid and
 * Expenses are distinct canonical facts. This is presentation only; no Finance posting is involved.
 */
function KpiSplitCard({
  icon,
  tone,
  label,
  parts,
  note,
}: {
  icon: LucideIcon;
  tone: string;
  label: string;
  parts: [SplitPart, SplitPart];
  note?: string;
}) {
  return (
    <CardShell icon={icon} tone={tone} label={label}>
      <div className="mt-0.5 space-y-1">
        {parts.map((part) => (
          <div key={part.label}>
            <p className="truncate text-[10px] uppercase leading-tight tracking-wide text-muted-foreground">
              {part.label}
            </p>
            <p className={`break-words text-sm font-semibold leading-tight tabular-nums ${part.tone ?? ''}`}>
              {part.value}
            </p>
          </div>
        ))}
      </div>
      {note ? <p className="mt-1 text-[10px] leading-snug text-muted-foreground">{note}</p> : null}
    </CardShell>
  );
}

/**
 * The 8 canonical operational KPI cards over the currently-visible custodies, in the approved
 * 4 × 2 desktop layout (TASK-ECOS-DISTRIBUTION-DRIVER-DAY-SETTLEMENT-PAGE-001 §3):
 *
 *   Row 1 — Total Orders · Delivered Orders · Returned/Failed/Exceptions · Delivery Rate
 *   Row 2 — Total Sales · Transfers-Paid + Expenses (one card, two values) · Cash In/Advances · Net Cash
 *
 * The three order cards lead with the COUNT and carry the commercial ORDER VALUE of exactly that
 * same population beneath it (§4/§6). Commercial order value is not collected cash: the cash facts
 * stay in their own cards and are never netted against it.
 *
 * The third card leads with the disjoint union of the canonical undelivered outcomes and names the
 * three canonical components underneath, because `Failed`, `Returned` and `Skipped` are DISTINCT
 * DeliveryStopStatus cases — none of them is a rename of another (§5).
 *
 * Every value is server-aggregated from canonical row data; the frontend re-derives nothing. A real
 * canonical zero renders as EGP 0.00 and a missing authority as "Not available" — never a fabricated
 * zero. States stay distinct: Loading / Error / Loaded. Responsive 2-col on mobile → 4-col from md,
 * so nothing scrolls horizontally or clips in Arabic.
 *
 * When one Brand is selected, the cards that are NOT Brand-attributable (Transfers/Paid, Expenses,
 * Cash In, Net Cash) say so explicitly rather than implying a Brand-scoped figure (§15).
 */
export function DaySettlementKpiCards({
  kpis,
  loading,
  error,
  brandSelected,
}: {
  kpis?: DaySettlementKpis;
  loading?: boolean;
  error?: boolean;
  brandSelected?: boolean;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();

  // Error precedes loading: a failed read must not masquerade as "still loading" or as zeros.
  if (error) {
    return (
      <div className={GRID} data-testid="kpi-error" aria-label={t(($) => $.driverSettlement.loadError)}>
        {Array.from({ length: 8 }, (_, i) => (
          <div
            key={i}
            className="flex min-h-[84px] items-center justify-center rounded-lg border border-dashed bg-muted/20 text-muted-foreground"
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
          <Skeleton key={i} className="h-[84px] rounded-lg" />
        ))}
      </div>
    );
  }

  const na = t(($) => $.driverSettlement.notAvailable);
  // Real canonical zero → EGP 0.00; no canonical authority (null/absent) → "Not available".
  const moneyOrNa = (v: number | null | undefined): string => (v === null || v === undefined ? na : money(v));
  // A monetary sub-value is omitted entirely when the server did not send it — never shown as zero.
  const sub = (v: number | undefined): string | undefined => (v === undefined ? undefined : money(v));

  // The third outcome. `total_undelivered` is the canonical disjoint union; an older server that
  // only knows Failed falls back to it rather than under-reporting silently.
  const undelivered = kpis.total_undelivered ?? kpis.total_failed;
  const undeliveredValue = kpis.total_undelivered_value ?? kpis.total_failed_value;
  const hasOutcomeSplit = kpis.total_returned !== undefined && kpis.total_skipped !== undefined;
  const outcomeBreakdown = hasOutcomeSplit
    ? [
        `${t(($) => $.driverSettlement.outcomes.returned)} ${kpis.total_returned}`,
        `${t(($) => $.driverSettlement.outcomes.failed)} ${kpis.total_failed}`,
        `${t(($) => $.driverSettlement.outcomes.skipped)} ${kpis.total_skipped}`,
      ].join(' · ')
    : undefined;

  // Shown on the cards that carry no canonical Brand association (§15).
  const overall = brandSelected ? t(($) => $.driverSettlement.brand.overallFinancials) : undefined;

  return (
    <div className={GRID}>
      {/* ── Row 1 — operational / commercial ─────────────────────────────────── */}
      <KpiCard
        icon={Package}
        tone="bg-primary/10 text-primary"
        label={t(($) => $.driverSettlement.kpis.totalOrders)}
        value={String(kpis.total_orders)}
        sub={sub(kpis.total_orders_value)}
      />
      <KpiCard
        icon={CheckCircle2}
        tone="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
        label={t(($) => $.driverSettlement.kpis.totalDelivered)}
        value={String(kpis.total_delivered)}
        sub={sub(kpis.total_delivered_value)}
      />
      <KpiCard
        icon={PackageX}
        tone="bg-destructive/10 text-destructive"
        label={t(($) => $.driverSettlement.kpis.undelivered)}
        value={String(undelivered)}
        valueTone={undelivered > 0 ? 'text-destructive' : undefined}
        sub={sub(undeliveredValue)}
        breakdown={outcomeBreakdown}
      />
      <KpiCard
        icon={Percent}
        tone="bg-blue-500/10 text-blue-600 dark:text-blue-400"
        label={t(($) => $.driverSettlement.kpis.deliveryRate)}
        value={`${kpis.delivery_rate}%`}
      />

      {/* ── Row 2 — money ────────────────────────────────────────────────────── */}
      <KpiCard
        icon={Coins}
        tone="bg-amber-500/10 text-amber-600 dark:text-amber-400"
        label={t(($) => $.driverSettlement.kpis.totalSales)}
        value={money(kpis.total_sales)}
      />
      <KpiSplitCard
        icon={ReceiptText}
        tone="bg-indigo-500/10 text-indigo-600 dark:text-indigo-400"
        label={t(($) => $.driverSettlement.kpis.transfersAndExpenses)}
        parts={[
          {
            label: t(($) => $.driverSettlement.columns.transfersPaid),
            value: money(kpis.total_transfers_paid),
          },
          {
            label: t(($) => $.driverSettlement.cards.expenses),
            value: moneyOrNa(kpis.total_expenses),
            tone: (kpis.total_expenses ?? 0) > 0 ? 'text-destructive' : undefined,
          },
        ]}
        note={overall}
      />
      <KpiCard
        icon={HandCoins}
        tone="bg-teal-500/10 text-teal-600 dark:text-teal-400"
        label={t(($) => $.driverSettlement.kpis.cashIn)}
        value={moneyOrNa(kpis.total_cash_in)}
        note={overall}
      />
      <KpiCard
        icon={Wallet}
        tone="bg-muted text-muted-foreground"
        label={t(($) => $.driverSettlement.kpis.netCash)}
        value={moneyOrNa(kpis.net_cash)}
        note={overall}
      />
    </div>
  );
}
