import { useTranslation } from 'react-i18next';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { ReportPeriod, ReportPeriodValue } from '../types/reports';

const PRESETS: ReportPeriod[] = [
  'today',
  'this_week',
  'this_month',
  'previous_month',
  'this_year',
  'ytd',
  'previous_year',
  'custom',
];

/**
 * The shared driver report date filter (§4). Presets and any custom from/to are sent to the
 * server, which resolves the window — the client never loads lifetime history to filter it here.
 */
export function ReportPeriodFilter({
  value,
  onChange,
}: {
  value: ReportPeriodValue;
  onChange: (v: ReportPeriodValue) => void;
}) {
  const { t } = useTranslation('driver-mobile');

  return (
    <div className="space-y-2">
      <div className="flex gap-2 overflow-x-auto pb-1">
        {PRESETS.map((p) => (
          <button
            key={p}
            type="button"
            onClick={() => onChange({ period: p, from: value.from, to: value.to })}
            className={cn(
              'shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
              value.period === p ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent/40',
            )}
          >
            {t(($) => $.reports.period[p])}
          </button>
        ))}
      </div>
      {value.period === 'custom' && (
        <div className="flex items-center gap-2">
          <Input
            type="date"
            aria-label={t(($) => $.reports.customFrom)}
            value={value.from ?? ''}
            onChange={(e) => onChange({ ...value, period: 'custom', from: e.target.value })}
            className="h-9 text-xs"
          />
          <span className="text-xs text-muted-foreground">{t(($) => $.reports.customTo)}</span>
          <Input
            type="date"
            aria-label={t(($) => $.reports.customToLabel)}
            value={value.to ?? ''}
            onChange={(e) => onChange({ ...value, period: 'custom', to: e.target.value })}
            className="h-9 text-xs"
          />
        </div>
      )}
    </div>
  );
}
