import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowDownCircle,
  ArrowLeft,
  ArrowUpCircle,
  Ban,
  Camera,
  Paperclip,
  Plus,
  Receipt,
  Wallet,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

import { useCreateTripExpense, useTripExpenses } from '../hooks/use-driver-mobile';
import {
  TRIP_EXPENSE_CATEGORIES,
  type TripExpense,
  type TripExpenseCategory,
  type TripExpenseStatus,
} from '../types/trip-expenses';

const STATUS_TONE: Record<TripExpenseStatus, string> = {
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
  approved: 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-300',
  rejected: 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300',
  settled: 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
};

function fmtWhen(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

/**
 * Driver Trip Expenses — TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §30–§43. The driver's own
 * CURRENT active-custody operational movements: fuel / road toll / other (cash out) and advances
 * (cash in). Read + create only — approval/settlement is an operator authority. Company/driver/trip
 * are resolved server-side; the driver only supplies category / amount / note / optional receipt.
 */
export function DriverTripExpensesPage() {
  const { t } = useTranslation('driver-mobile');
  const { money } = useFormatter();
  const navigate = useNavigate();

  const { data, isLoading, isError, refetch } = useTripExpenses();
  const createExpense = useCreateTripExpense();

  const [open, setOpen] = useState(false);

  return (
    <div className="min-h-screen bg-background pb-24">
      <div className="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3">
        <Button variant="ghost" size="icon" aria-label={t(($) => $.nav.home)} onClick={() => navigate(ROUTES.driverHome)}>
          <ArrowLeft className="h-5 w-5" aria-hidden="true" />
        </Button>
        <h1 className="flex items-center gap-2 text-base font-semibold">
          <Wallet className="h-5 w-5" aria-hidden="true" />
          {t(($) => $.tripExpenses.title)}
        </h1>
      </div>

      <div className="space-y-4 p-4">
        {isLoading ? (
          <>
            <Skeleton className="h-24 w-full rounded-xl" />
            <Skeleton className="h-32 w-full rounded-xl" />
          </>
        ) : isError || !data ? (
          <div className="flex flex-col items-center gap-3 py-14 text-muted-foreground">
            <AlertTriangle className="h-9 w-9 text-destructive/70" aria-hidden="true" />
            <p className="text-sm">{t(($) => $.tripExpenses.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>{t(($) => $.tripExpenses.retry)}</Button>
          </div>
        ) : !data.has_active_custody ? (
          <div className="flex flex-col items-center gap-2 py-16 text-center text-muted-foreground">
            <Ban className="h-10 w-10 opacity-40" aria-hidden="true" />
            <p className="text-base font-medium text-foreground">{t(($) => $.tripExpenses.noCustody.title)}</p>
            <p className="max-w-xs text-sm">{t(($) => $.tripExpenses.noCustody.subtitle)}</p>
          </div>
        ) : (
          <>
            {/* Totals — approved only; advance is never folded into expenses (§41). */}
            <div className="rounded-xl border bg-card p-4">
              {data.trip?.trip_number && (
                <p className="mb-3 text-xs text-muted-foreground" dir="ltr">{data.trip.trip_number}</p>
              )}
              <div className="grid grid-cols-2 gap-2">
                <TotalTile label={t(($) => $.tripExpenses.totals.expenses)} value={money(data.totals.approved_expenses)} tone="text-red-600" />
                <TotalTile label={t(($) => $.tripExpenses.totals.advances)} value={money(data.totals.approved_advances)} tone="text-green-600" />
              </div>
              {data.totals.pending_count > 0 && (
                <p className="mt-2 text-center text-[11px] text-amber-600">
                  {t(($) => $.tripExpenses.totals.pending, { count: data.totals.pending_count })}
                </p>
              )}
              <p className="mt-2 text-center text-[11px] text-muted-foreground">{t(($) => $.tripExpenses.totals.approvedOnly)}</p>
            </div>

            <Button className="h-12 w-full gap-2 text-base font-semibold" onClick={() => setOpen(true)}>
              <Plus className="h-5 w-5" aria-hidden="true" />
              {t(($) => $.tripExpenses.add)}
            </Button>

            {data.items.length === 0 ? (
              <p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.tripExpenses.empty)}</p>
            ) : (
              <div className="space-y-2">
                {data.items.map((m) => (
                  <ExpenseCard key={m.id} movement={m} money={money} />
                ))}
              </div>
            )}
          </>
        )}
      </div>

      <AddExpenseSheet
        open={open}
        onOpenChange={setOpen}
        pending={createExpense.isPending}
        onSubmit={(input) => createExpense.mutate(input, { onSuccess: () => setOpen(false) })}
      />
    </div>
  );
}

function TotalTile({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="rounded-lg bg-muted/40 px-3 py-2 text-center">
      <p className={cn('text-lg font-bold tabular-nums leading-none', tone)}>{value}</p>
      <p className="mt-1 text-[11px] text-muted-foreground">{label}</p>
    </div>
  );
}

function ExpenseCard({ movement, money }: { movement: TripExpense; money: (n: number) => string }) {
  const { t } = useTranslation('driver-mobile');
  const cashIn = movement.direction === 'cash_in';
  return (
    <div className="rounded-lg border bg-card p-3">
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2">
          {cashIn
            ? <ArrowDownCircle className="h-5 w-5 shrink-0 text-green-600" aria-hidden="true" />
            : <ArrowUpCircle className="h-5 w-5 shrink-0 text-red-600" aria-hidden="true" />}
          <div className="min-w-0">
            <p className="text-sm font-medium">{t(($) => $.tripExpenses.category[movement.category])}</p>
            <p className="text-[11px] text-muted-foreground">{fmtWhen(movement.occurred_at)}</p>
          </div>
        </div>
        <div className="shrink-0 text-end">
          <p className={cn('text-sm font-semibold tabular-nums', cashIn ? 'text-green-600' : 'text-red-600')}>
            {cashIn ? '+' : '-'}{money(movement.amount)}
          </p>
          <Badge variant="secondary" className={cn('mt-1 text-[10px]', STATUS_TONE[movement.status])}>
            {t(($) => $.tripExpenses.status[movement.status])}
          </Badge>
        </div>
      </div>
      {movement.note && <p className="mt-2 text-xs text-muted-foreground">{movement.note}</p>}
      {movement.has_receipt && (
        <p className="mt-2 flex items-center gap-1 text-[11px] text-muted-foreground">
          <Paperclip className="h-3 w-3" aria-hidden="true" />
          {t(($) => $.tripExpenses.hasReceipt)}
        </p>
      )}
    </div>
  );
}

function AddExpenseSheet({
  open,
  onOpenChange,
  pending,
  onSubmit,
}: {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  pending: boolean;
  onSubmit: (input: { category: TripExpenseCategory; amount: number; note?: string; receipt?: File | null }) => void;
}) {
  const { t } = useTranslation('driver-mobile');
  const [category, setCategory] = useState<TripExpenseCategory>('fuel');
  const [amount, setAmount] = useState('');
  const [note, setNote] = useState('');
  const [receipt, setReceipt] = useState<File | null>(null);

  const parsed = Number(amount);
  const canSubmit = amount.trim() !== '' && Number.isFinite(parsed) && parsed > 0 && !pending;

  const reset = () => {
    setCategory('fuel');
    setAmount('');
    setNote('');
    setReceipt(null);
  };

  const submit = () => {
    if (!canSubmit) return;
    onSubmit({ category, amount: parsed, note: note.trim() || undefined, receipt });
    reset();
  };

  return (
    <Sheet open={open} onOpenChange={(o) => { onOpenChange(o); if (!o) reset(); }}>
      <SheetContent side="bottom" className="max-h-[90vh] overflow-y-auto">
        <SheetHeader className="mb-4">
          <SheetTitle>{t(($) => $.tripExpenses.add)}</SheetTitle>
        </SheetHeader>

        <div className="space-y-4">
          {/* Category — cash direction is shown so an advance is never mistaken for an expense. */}
          <div>
            <p className="mb-1.5 text-xs font-medium text-muted-foreground">{t(($) => $.tripExpenses.form.category)}</p>
            <div className="grid grid-cols-2 gap-2">
              {TRIP_EXPENSE_CATEGORIES.map((c) => {
                const selected = category === c;
                const cashIn = c === 'advance';
                return (
                  <button
                    key={c}
                    type="button"
                    onClick={() => setCategory(c)}
                    className={cn(
                      'flex flex-col items-start gap-0.5 rounded-xl border p-3 text-start text-sm font-medium transition-colors',
                      selected ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-accent/40',
                    )}
                  >
                    <span>{t(($) => $.tripExpenses.category[c])}</span>
                    <span className={cn('text-[10px] font-normal', cashIn ? 'text-green-600' : 'text-muted-foreground')}>
                      {t(($) => cashIn ? $.tripExpenses.direction.cash_in : $.tripExpenses.direction.cash_out)}
                    </span>
                  </button>
                );
              })}
            </div>
          </div>

          {/* Amount */}
          <div>
            <label className="mb-1.5 block text-xs font-medium text-muted-foreground" htmlFor="expense-amount">
              {t(($) => $.tripExpenses.form.amount)}
            </label>
            <Input
              id="expense-amount"
              type="number"
              inputMode="decimal"
              min={0}
              step="any"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              className="h-12 text-base"
              placeholder="0.00"
            />
          </div>

          {/* Note */}
          <div>
            <label className="mb-1.5 block text-xs font-medium text-muted-foreground" htmlFor="expense-note">
              {t(($) => $.tripExpenses.form.note)}
            </label>
            <textarea
              id="expense-note"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={2}
              maxLength={1000}
              className="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-1 focus-visible:ring-ring"
              placeholder={t(($) => $.tripExpenses.form.notePlaceholder)}
            />
          </div>

          {/* Receipt — camera-first on mobile (§38). Optional. */}
          <div>
            <p className="mb-1.5 text-xs font-medium text-muted-foreground">{t(($) => $.tripExpenses.form.receipt)}</p>
            <label className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed p-3 text-sm text-muted-foreground hover:bg-accent/40">
              {receipt ? <Receipt className="h-4 w-4" aria-hidden="true" /> : <Camera className="h-4 w-4" aria-hidden="true" />}
              <span className="truncate">{receipt ? receipt.name : t(($) => $.tripExpenses.form.captureReceipt)}</span>
              <input
                type="file"
                accept="image/*,application/pdf"
                capture="environment"
                className="hidden"
                onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
              />
            </label>
          </div>

          <div className="flex gap-2 pt-1">
            <Button variant="outline" className="flex-1" onClick={() => onOpenChange(false)}>
              {t(($) => $.tripExpenses.form.cancel)}
            </Button>
            <Button className="flex-1" disabled={!canSubmit} onClick={submit}>
              {pending ? t(($) => $.tripExpenses.form.saving) : t(($) => $.tripExpenses.form.submit)}
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  );
}
