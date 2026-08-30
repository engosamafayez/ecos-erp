import { useTranslation } from 'react-i18next';
import { Info } from 'lucide-react';

import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';
import type { DaySettlementCollections } from '../types/driver-settlement';

function CashCard({
  label,
  value,
  unavailable,
  hint,
  emphasis,
  tone,
}: {
  label: string;
  value?: string;
  unavailable?: boolean;
  hint?: string;
  emphasis?: boolean;
  tone?: string;
}) {
  const { t } = useTranslation('logistics');
  return (
    <div className={cn('rounded-lg border p-3', emphasis ? 'border-primary/30 bg-primary/5' : 'bg-card')}>
      <p className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      {unavailable ? (
        <p className="mt-0.5 text-sm font-medium text-muted-foreground">{t(($) => $.driverSettlement.notAvailable)}</p>
      ) : (
        <p className={cn('mt-0.5 text-lg font-semibold leading-tight tabular-nums break-words', tone)}>{value}</p>
      )}
      {hint ? <p className="mt-1 text-[10px] leading-snug text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

/**
 * The Driver cash-position summary (TASK-...-SINGLE-ACTIVE-TRIP-CLOSURE-CONTRACT-001).
 *
 * Belongs to the ONE active Trip/Custody (not a calendar day). Every figure is canonical or an
 * explicit "Not available" — never a fabricated zero (§8):
 *  - Expected Collection: the immutable per-stop handoff snapshot sum (its `available` flag is
 *    backend-derived; historical rows with no snapshot stay "Not available", never backfilled).
 *  - Cash Collected: canonical PHYSICAL cash only (`PaymentType::Cash`).
 *  - Electronic: bank transfer + card, kept SEPARATE from physical cash (§9).
 *  - Expenses / Cash In / Net Cash: canonical from approved DriverTripMovement records
 *    (TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001). Expenses = approved cash-out; Cash In =
 *    approved cash-in (advances, kept SEPARATE, §13); Net Cash = physical cash collected + cash-in
 *    − expenses (§14). A real zero is EGP 0.00, never "Not available".
 *
 * A read failure is handled by the page (this renders only for a successful read), so the three
 * states — real value / Not available / error — stay distinct.
 */
export function DriverCashPositionCards({
  collections,
  expenses,
  cashIn,
  netCash,
}: {
  collections: DaySettlementCollections;
  expenses: number;
  cashIn: number;
  netCash: number;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();
  const c = collections;

  const expectedAvailable = c.expected_collection_available && c.expected_collection !== null;
  const electronic = Math.round((c.bank_transfer + c.card) * 100) / 100;
  const totalCustomer = Math.round((c.cash + electronic) * 100) / 100;

  return (
    <section>
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        {t(($) => $.driverSettlement.cards.title)}
      </p>

      {/* The four headline cash cards (§1/§6): 2-col on mobile → 4-col from lg; values never clip. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <CashCard
          label={t(($) => $.driverSettlement.cards.expectedCollection)}
          value={c.expected_collection !== null ? money(c.expected_collection) : undefined}
          unavailable={!expectedAvailable}
          hint={t(($) => $.driverSettlement.cards.expectedCollectionHint)}
        />
        <CashCard
          label={t(($) => $.driverSettlement.cards.cashCollected)}
          value={money(c.cash)}
          emphasis
          hint={t(($) => $.driverSettlement.cards.cashHint)}
        />
        <CashCard
          label={t(($) => $.driverSettlement.cards.expenses)}
          value={money(expenses)}
          tone={expenses > 0 ? 'text-destructive' : undefined}
          hint={t(($) => $.driverSettlement.cards.expensesHint)}
        />
        <CashCard label={t(($) => $.driverSettlement.cards.netCash)} value={money(netCash)} emphasis />
      </div>

      {/* Electronic + totals + advances — electronic is NOT physical cash (§9); advances are cash IN (§13). */}
      <div className="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <CashCard
          label={t(($) => $.driverSettlement.cards.electronic)}
          value={money(electronic)}
          hint={t(($) => $.driverSettlement.cards.electronicHint)}
        />
        <CashCard label={t(($) => $.driverSettlement.cards.totalCustomerCollections)} value={money(totalCustomer)} />
        <CashCard
          label={t(($) => $.driverSettlement.cards.cashIn)}
          value={money(cashIn)}
          tone={cashIn > 0 ? 'text-emerald-600' : undefined}
          hint={t(($) => $.driverSettlement.cards.cashInHint)}
        />
        <CashCard
          label={t(($) => $.driverSettlement.cards.collectionDifference)}
          value={c.collection_difference !== null ? money(c.collection_difference) : undefined}
          unavailable={c.collection_difference === null}
          tone={
            c.collection_difference === null || Math.abs(c.collection_difference) < 0.01
              ? undefined
              : c.collection_difference < 0
                ? 'text-destructive'
                : 'text-emerald-600'
          }
        />
      </div>

      <p className="mt-2 flex items-center gap-1 text-[11px] text-muted-foreground">
        <Info className="h-3 w-3 shrink-0" />
        {t(($) => $.driverSettlement.cards.netCashNote)}
      </p>
    </section>
  );
}
