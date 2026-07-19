import { CheckCircle2, XCircle } from 'lucide-react';
import type { DispatchChecklist } from '../types/distribution-board';
import { cn } from '@/lib/utils';

interface Props {
  checklist: DispatchChecklist;
}

const CHECKLIST_ITEMS: { key: keyof Omit<DispatchChecklist, 'can_dispatch'>; label: string }[] = [
  { key: 'loading_completed',              label: 'اكتمل التحميل' },
  { key: 'driver_accepted_products',       label: 'قبل السائق المنتجات' },
  { key: 'driver_accepted_custody',        label: 'قبل السائق العهدة' },
  { key: 'driver_accepted_equipment',      label: 'قبل السائق المعدات' },
  { key: 'no_outstanding_shortages',       label: 'لا نواقص معلّقة' },
  { key: 'no_outstanding_discrepancies',   label: 'لا تعارضات معلّقة' },
];

export function DispatchChecklistPanel({ checklist }: Props) {
  const passCount = CHECKLIST_ITEMS.filter((i) => checklist[i.key]).length;

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between text-sm">
        <span className="text-muted-foreground">شروط الإرسال</span>
        <span className={cn(
          'font-semibold tabular-nums',
          passCount === CHECKLIST_ITEMS.length ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400',
        )}>
          {passCount}/{CHECKLIST_ITEMS.length} مستوفاة
        </span>
      </div>

      <div className="space-y-2">
        {CHECKLIST_ITEMS.map((item) => {
          const ok = checklist[item.key];
          return (
            <div
              key={item.key}
              className={cn(
                'flex items-center gap-3 p-3 rounded-lg border',
                ok
                  ? 'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900/40 dark:bg-emerald-950/10'
                  : 'border-red-200 bg-red-50/50 dark:border-red-900/40 dark:bg-red-950/10',
              )}
            >
              {ok ? (
                <CheckCircle2 className="h-4 w-4 text-emerald-500 shrink-0" />
              ) : (
                <XCircle className="h-4 w-4 text-red-500 shrink-0" />
              )}
              <span className={cn('text-sm', ok ? 'text-foreground' : 'text-muted-foreground')}>
                {item.label}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}
