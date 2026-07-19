import type { TripSettlement } from '../types/driver-mobile';

interface SettlementSummaryProps {
  settlement: TripSettlement;
}

function fmt(v: number | null | undefined) {
  return `EGP ${Number(v ?? 0).toLocaleString('ar-EG', { minimumFractionDigits: 2 })}`;
}

export function SettlementSummary({ settlement }: SettlementSummaryProps) {
  const rows = [
    { label: 'النقد المحصّل',                value: fmt(settlement.cash_collected),         highlight: false },
    { label: 'تحويلات بنكية (بانتظار تحقق)', value: fmt(settlement.bank_transfers_pending), highlight: false },
    { label: 'مدفوع مسبقاً (مستثنى)',       value: fmt(settlement.already_paid),           highlight: false },
    { label: 'إجمالي المحصّل',             value: fmt(settlement.total_collected),        highlight: true  },
    { label: 'النقد المتوقع',               value: fmt(settlement.cash_expected),          highlight: false },
    { label: 'المقدَّم من السائق',          value: fmt(settlement.driver_cash_submitted),  highlight: false },
    { label: 'الفارق',                      value: fmt(settlement.discrepancy),            highlight: settlement.discrepancy !== null && settlement.discrepancy !== 0 },
  ];

  const statusLabels: Record<string, string> = {
    draft:     'مسودة',
    submitted: 'مقدَّم',
    verified:  'محقَّق',
    closed:    'مغلق',
  };
  const statusColors: Record<string, string> = {
    draft:     'bg-gray-100 text-gray-700',
    submitted: 'bg-blue-100 text-blue-700',
    verified:  'bg-green-100 text-green-700',
    closed:    'bg-gray-100 text-gray-500',
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="font-semibold text-sm">ملخص التسوية</p>
        <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${statusColors[settlement.status] ?? ''}`}>
          {statusLabels[settlement.status] ?? settlement.status}
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
