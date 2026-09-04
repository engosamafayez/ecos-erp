import { useTranslation } from 'react-i18next';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import type { FinanceIntelligenceWindowParams } from '../types/finance-intelligence';

/**
 * The shared from/to reporting-window control for the Finance Intelligence
 * views that actually accept one — profitability (all 7 dimensions) and cost
 * breakdown/operational, confirmed from their controllers calling
 * ResolvesFinanceContext::financeWindow(). Cost trend and both cash-flow
 * endpoints do NOT take from/to (months / horizon / nothing, respectively —
 * see each hook's params type) and must not use this control.
 *
 * Mirrors ExecutiveFilterBar's native `<input type="date">` convention (this
 * app has no dedicated date-range-picker component). Left empty, a field is
 * omitted from the request and the backend applies its own default (a
 * trailing 12 months ending today).
 */
export function FinanceIntelligenceDateRange({
  value,
  onChange,
  idPrefix,
}: {
  value: FinanceIntelligenceWindowParams;
  onChange: (next: FinanceIntelligenceWindowParams) => void;
  idPrefix: string;
}) {
  const { t } = useTranslation('finance');

  return (
    <div className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor={`${idPrefix}-from`}>{t(($) => $.profitability.filter.from)}</Label>
        <Input
          id={`${idPrefix}-from`}
          type="date"
          className="w-40"
          value={value.from ?? ''}
          onChange={(e) => onChange({ ...value, from: e.target.value || undefined })}
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <Label htmlFor={`${idPrefix}-to`}>{t(($) => $.profitability.filter.to)}</Label>
        <Input
          id={`${idPrefix}-to`}
          type="date"
          className="w-40"
          value={value.to ?? ''}
          onChange={(e) => onChange({ ...value, to: e.target.value || undefined })}
        />
      </div>
    </div>
  );
}
