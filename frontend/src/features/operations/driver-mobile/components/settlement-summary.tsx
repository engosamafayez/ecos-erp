import { useTranslation } from 'react-i18next';
import { SETTLEMENT_STATUS_COLORS } from '../types/driver-mobile';
import { useFormatter } from '@/hooks/use-formatter';
import type { TripSettlement } from '../types/driver-mobile';

interface SettlementSummaryProps {
  settlement: TripSettlement;
}

export function SettlementSummary({ settlement }: SettlementSummaryProps) {
  const { t } = useTranslation('driver-mobile');
  const { money } = useFormatter();
  const fmt = (v: number | null | undefined) => money(Number(v ?? 0));
  const rows = [
    { label: t(($) => $.settlementPage.summary.cashCollected),         value: fmt(settlement.cash_collected),         highlight: false },
    { label: t(($) => $.settlementPage.summary.bankTransfersPending),  value: fmt(settlement.bank_transfers_pending), highlight: false },
    { label: t(($) => $.settlementPage.summary.prePaidExcluded),       value: fmt(settlement.already_paid),           highlight: false },
    { label: t(($) => $.settlementPage.summary.totalCollected),        value: fmt(settlement.total_collected),        highlight: true  },
    { label: t(($) => $.settlementPage.summary.expectedCash),          value: fmt(settlement.cash_expected),          highlight: false },
    { label: t(($) => $.settlementPage.summary.driverCashSubmitted),   value: fmt(settlement.driver_cash_submitted),  highlight: false },
    { label: t(($) => $.settlementPage.summary.discrepancy),           value: fmt(settlement.discrepancy),            highlight: settlement.discrepancy !== null && settlement.discrepancy !== 0 },
  ];

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="font-semibold text-sm">{t(($) => $.settlementPage.summary.title)}</p>
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${SETTLEMENT_STATUS_COLORS[settlement.status] ?? ''}`}>
          {t(($) => $.settlementPage.status[settlement.status])}
        </span>
      </div>

      <div className="rounded-lg border divide-y">
        {rows.map((row) => (
          <div
            key={row.label}
            className={`flex items-center justify-between px-3 py-2.5 text-sm ${row.highlight ? 'bg-muted/50 font-semibold' : ''}`}
          >
            <span className="text-muted-foreground">{row.label}</span>
            <span className={row.highlight ? 'text-foreground' : ''}>{row.value}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
