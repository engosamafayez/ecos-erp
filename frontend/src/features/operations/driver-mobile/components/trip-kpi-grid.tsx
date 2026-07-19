import type { DriverTripKpis } from '../types/driver-mobile';

interface TripKpiGridProps {
  kpis: DriverTripKpis;
}

interface KpiTile {
  label: string;
  value: number | string;
  color: string;
}

export function TripKpiGrid({ kpis }: TripKpiGridProps) {
  const tiles: KpiTile[] = [
    { label: 'إجمالي الطلبات', value: kpis.total_orders,     color: 'text-gray-900 dark:text-gray-100' },
    { label: 'قيد الانتظار',  value: kpis.pending,           color: 'text-blue-600' },
    { label: 'تم التوصيل',    value: kpis.delivered,         color: 'text-green-600' },
    { label: 'جزئي',          value: kpis.partial,           color: 'text-amber-600' },
    { label: 'فاشل',          value: kpis.failed,            color: 'text-red-600' },
    { label: 'التحصيلات',     value: `EGP ${Number(kpis.total_collections).toLocaleString('ar-EG', { minimumFractionDigits: 2 })}`, color: 'text-emerald-600' },
  ];

  return (
    <div className="grid grid-cols-3 gap-3">
      {tiles.map((tile) => (
        <div
          key={tile.label}
          className="rounded-lg border bg-card p-3 text-center shadow-sm"
        >
          <p className={`text-xl font-bold ${tile.color}`}>{tile.value}</p>
          <p className="mt-0.5 text-xs text-muted-foreground">{tile.label}</p>
        </div>
      ))}
    </div>
  );
}
