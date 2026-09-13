import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus, Trash2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { EntityDrawer } from '@/components/crud';
import { useFormatter } from '@/hooks/use-formatter';

import { useExpenses } from '../hooks/use-finance-expense';
import { useCreateCostAllocation } from '../hooks/use-finance-cost-allocation';
import type { CostAllocationDestinationInput, CostAllocationMethod } from '../types/finance-cost-allocation';

type Props = { open: boolean; onOpenChange: (open: boolean) => void };

/** One destination row. `value` means "amount" or "percentage" depending on the selected method
 *  — a single numeric field whose meaning switches, mirroring JournalFormDrawer's side+amount line. */
type DraftDestination = { profit_center_id: string; value: string };

const emptyDestination = (): DraftDestination => ({ profit_center_id: '', value: '' });

/**
 * Create a cost allocation (POST /finance/cost-allocations) against a POSTED
 * expense. This engine deliberately never creates a second GL journal — it is
 * a management-dimension attribution only. Destinations are a raw Brand /
 * Profit-Center reference id: Finance carries no Brand directory (a
 * documented upstream gap), so this is a text input, never a fabricated
 * dropdown of brand names.
 */
export function CostAllocationFormDrawer({ open, onOpenChange }: Props) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const postedExpenses = useExpenses({ status: 'posted' });
  const create = useCreateCostAllocation();

  const [expenseId, setExpenseId] = useState('');
  const [method, setMethod] = useState<CostAllocationMethod>('fixed');
  const [destinations, setDestinations] = useState<DraftDestination[]>([emptyDestination()]);
  const [error, setError] = useState<string | null>(null);

  const expense = (postedExpenses.data ?? []).find((e) => e.id === expenseId);

  const setDestination = (i: number, patch: Partial<DraftDestination>) =>
    setDestinations((prev) => prev.map((d, idx) => (idx === i ? { ...d, ...patch } : d)));

  // Informational only — the backend is authoritative on both the balance and the 100% cap.
  const totals = useMemo(() => {
    const sum = destinations.reduce((acc, d) => acc + (Number(d.value) || 0), 0);
    return { sum, overPercentage: method === 'percentage' && sum > 100 };
  }, [destinations, method]);

  const complete =
    destinations.length > 0 &&
    destinations.every((d) => d.profit_center_id.trim() !== '' && Number(d.value) > 0);
  const canSubmit = expenseId !== '' && complete && !create.isPending;

  const reset = () => {
    setExpenseId('');
    setMethod('fixed');
    setDestinations([emptyDestination()]);
    setError(null);
  };
  const close = () => { reset(); onOpenChange(false); };

  const submit = () => {
    setError(null);
    if (!canSubmit) return;
    const payloadDestinations: CostAllocationDestinationInput[] = destinations.map((d) => ({
      profit_center_id: d.profit_center_id.trim(),
      ...(method === 'fixed' ? { amount: Number(d.value) } : { percentage: Number(d.value) }),
    }));
    create.mutate(
      { expense_id: expenseId, method, destinations: payloadDestinations },
      { onSuccess: close, onError: () => setError(t(($) => $.costAllocation.form.error)) },
    );
  };

  return (
    <EntityDrawer
      open={open}
      onOpenChange={(o) => (o ? onOpenChange(true) : close())}
      title={t(($) => $.costAllocation.form.title)}
      description={t(($) => $.costAllocation.form.subtitle)}
      footer={
        <div className="flex w-full items-center justify-between gap-4">
          <div className="text-sm tabular-nums">
            {method === 'percentage' ? (
              <>
                <span className="text-muted-foreground">{t(($) => $.costAllocation.form.totalPercentage)}: </span>
                <span className="font-medium">{fmt.number(totals.sum, 2)}%</span>
                {totals.overPercentage && (
                  <span className="ms-2 text-amber-600">{t(($) => $.costAllocation.form.overPercentage)}</span>
                )}
              </>
            ) : (
              <>
                <span className="text-muted-foreground">{t(($) => $.costAllocation.form.totalAllocated)}: </span>
                <span className="font-medium">{fmt.money(totals.sum)}</span>
                {expense != null && totals.sum > expense.amount && (
                  <span className="ms-2 text-amber-600">{t(($) => $.costAllocation.form.overAllocated)}</span>
                )}
              </>
            )}
          </div>
          <div className="flex gap-2">
            <Button variant="outline" onClick={close}>{t(($) => $.gl.actions.cancel)}</Button>
            <Button onClick={submit} disabled={!canSubmit}>
              {create.isPending ? t(($) => $.gl.actions.saving) : t(($) => $.gl.actions.create)}
            </Button>
          </div>
        </div>
      }
    >
      <div className="space-y-4">
        <p className="text-xs text-muted-foreground">{t(($) => $.costAllocation.form.neverPosts)}</p>

        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">{t(($) => $.costAllocation.field.expense)} *</Label>
          <Select value={expenseId} onValueChange={setExpenseId}>
            <SelectTrigger><SelectValue placeholder={t(($) => $.costAllocation.field.expensePlaceholder)} /></SelectTrigger>
            <SelectContent>
              {(postedExpenses.data ?? []).map((e) => (
                <SelectItem key={e.id} value={e.id}>{e.number} · {fmt.money(e.amount, e.currency)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          {expense != null && (
            <p className="text-xs text-muted-foreground">
              {t(($) => $.costAllocation.form.availableToAllocate)}:{' '}
              <span className="tabular-nums font-medium text-foreground">{fmt.money(expense.amount, expense.currency)}</span>
            </p>
          )}
        </div>

        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">{t(($) => $.costAllocation.field.method)} *</Label>
          <Select value={method} onValueChange={(v) => setMethod(v as CostAllocationMethod)}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="fixed">{t(($) => $.costAllocation.method.fixed)}</SelectItem>
              <SelectItem value="percentage">{t(($) => $.costAllocation.method.percentage)}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <Label className="text-xs uppercase tracking-wide text-muted-foreground">
              {t(($) => $.costAllocation.field.destinations)}
            </Label>
            <Button variant="ghost" size="sm" onClick={() => setDestinations((p) => [...p, emptyDestination()])}>
              <Plus className="me-1 size-3.5" /> {t(($) => $.costAllocation.form.addDestination)}
            </Button>
          </div>

          {destinations.map((d, i) => (
            <div key={i} className="grid grid-cols-[1fr_140px_36px] items-center gap-2">
              <Input
                value={d.profit_center_id}
                onChange={(e) => setDestination(i, { profit_center_id: e.target.value })}
                placeholder={t(($) => $.costAllocation.field.profitCenterPlaceholder)}
                dir="ltr"
                className="h-9"
              />
              <Input
                type="number" min="0" step="0.01" inputMode="decimal"
                placeholder={method === 'fixed' ? t(($) => $.costAllocation.field.amount) : t(($) => $.costAllocation.field.percentage)}
                value={d.value}
                onChange={(e) => setDestination(i, { value: e.target.value })}
                className="h-9 text-end tabular-nums"
              />
              <Button
                variant="ghost" size="icon" className="size-9"
                onClick={() => setDestinations((p) => (p.length > 1 ? p.filter((_, idx) => idx !== i) : p))}
                disabled={destinations.length <= 1}
                aria-label={t(($) => $.costAllocation.form.removeDestination)}
              >
                <Trash2 className="size-4" />
              </Button>
            </div>
          ))}
          <p className="text-xs text-muted-foreground">{t(($) => $.costAllocation.form.profitCenterNote)}</p>
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    </EntityDrawer>
  );
}
