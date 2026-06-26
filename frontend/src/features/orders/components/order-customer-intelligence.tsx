import { useTranslation } from 'react-i18next';

import type { CustomerIntelligenceFilter } from '@/features/orders/types/order';
import { cn } from '@/lib/utils';

type Props = {
  value: CustomerIntelligenceFilter | null;
  onChange: (next: CustomerIntelligenceFilter | null) => void;
};

const FILTERS: Array<{ key: CustomerIntelligenceFilter; emoji: string }> = [
  { key: 'first_order',  emoji: '🆕' },
  { key: 'repeated',     emoji: '🔁' },
  { key: 'more_than_5',  emoji: '📦' },
  { key: 'more_than_10', emoji: '⭐' },
  { key: 'has_cancelled',emoji: '❌' },
  { key: 'has_rejected', emoji: '🚫' },
  { key: 'incomplete',   emoji: '⏳' },
];

/**
 * DD-025 — Customer Intelligence smart filter chips.
 * Selecting a chip narrows the order list to matching customer profiles.
 * Clicking the active chip again clears the filter.
 */
export function OrderCustomerIntelligence({ value, onChange }: Props) {
  const { t } = useTranslation('orders');

  function toggle(key: CustomerIntelligenceFilter) {
    onChange(value === key ? null : key);
  }

  return (
    <div className="border-b bg-muted/30 px-4 py-3">
      <p className="mb-2 text-xs font-medium text-muted-foreground">
        {t('customerIntelligence.title')}
      </p>
      <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('customerIntelligence.title')}>
        {FILTERS.map(({ key, emoji }) => {
          const active = value === key;
          return (
            <button
              key={key}
              type="button"
              onClick={() => toggle(key)}
              aria-pressed={active}
              className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                active
                  ? 'border-primary bg-primary text-primary-foreground'
                  : 'border-border bg-background text-foreground hover:border-primary/40 hover:bg-accent',
              )}
            >
              <span>{emoji}</span>
              {t(`customerIntelligence.${key}`)}
            </button>
          );
        })}
      </div>
    </div>
  );
}
