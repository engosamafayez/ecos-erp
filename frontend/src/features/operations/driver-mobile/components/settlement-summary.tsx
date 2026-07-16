import type { TripSettlement } from '../types/driver-mobile';

interface SettlementSummaryProps {
  settlement: TripSettlement;
}

function fmt(v: number | null | undefined) {
  return `EGP ${Number(v ?? 0).toLocaleString('en-EG', { minimumFractionDigits: 2 })}`;
}

export function SettlementSummary({ settlement }: SettlementSummaryProps) {
  const rows = [
    { label: 'Cash Collected',               value: fmt(settlement.cash_collected),         highlight: false },
    { label: 'Bank Transfers (Pending Verification)', value: fmt(settlement.bank_transfers_pending), highlight: false },
    { label: 'Already Paid (Excluded)',      value: fmt(settlement.already_paid),           highlight: false },
    { label: 'Total Collected',              value: fmt(settlement.total_collected),        highlight: true  },
    { label: 'Cash Expected',                value: fmt(settlement.cash_expected),          highlight: false },
    { label: 'Driver Submitted',             value: fmt(settlement.driver_cash_submitted),  highlight: false },
    { label: 'Discrepancy',                  value: fmt(settlement.discrepancy),            highlight: settlement.discrepancy !== null && settlement.discrepancy !== 0 },
  ];

  const statusColors: Record<string, string> = {
    draft:     'bg-gray-100 text-gray-700',
    submitted: 'bg-blue-100 text-blue-700',
    verified:  'bg-green-100 text-green-700',
    closed:    'bg-gray-100 text-gray-500',
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="font-semibold text-sm">Settlement Summary</p>
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${statusColors[settlement.status] ?? ''}`}>
          {settlement.status.charAt(0).toUpperCase() + settlement.status.slice(1)}
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
