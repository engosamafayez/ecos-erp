import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckCircle2, HandCoins } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { usePermission } from '@/features/authorization';

import { useCashHandoverContext, useConfirmCashHandover } from '../hooks/use-cash-handover';

/**
 * Treasury physical cash handover — the second-actor confirmation step
 * (TASK-ECOS-DRIVER-SETTLEMENT-TREASURY-FINAL-IMPLEMENTATION-002).
 *
 * Rendered as a section inside the existing TripSettlementTab — this is
 * deliberately NOT a new page (the implementation task explicitly forbids a
 * duplicate Treasury/Settlement page). Same read-only philosophy as the rest
 * of that tab: every figure is computed by the backend; this component never
 * re-derives Expected Cash or the difference as an authoritative value — the
 * live subtraction shown while typing is a preview only, replaced by the
 * server's own `difference` the moment a confirmation response returns.
 */
export function CashHandoverPanel({ tripId }: { tripId: string }) {
  const { t, i18n } = useTranslation('logistics');
  const { can } = usePermission();
  const canConfirm = can('logistics.distribution.update');

  const { data: context, isLoading } = useCashHandoverContext(tripId);
  const confirm = useConfirmCashHandover(tripId);

  const [receivedCash, setReceivedCash] = useState('');
  const [cashAccountId, setCashAccountId] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState<string | null>(null);

  const money = (value: number | null | undefined) =>
    value === null || value === undefined
      ? t(($) => $.trips.settlement.cashHandover.notAvailable)
      : new Intl.NumberFormat(i18n.language).format(value);

  if (isLoading) return <Skeleton className="h-40 w-full" />;
  if (!context) return null;

  const { handover } = context;

  async function handleConfirm() {
    setError(null);
    const amount = Number(receivedCash);
    if (receivedCash === '' || Number.isNaN(amount) || amount < 0 || cashAccountId === '') {
      return;
    }
    try {
      await confirm.mutateAsync({
        received_cash: amount,
        cash_account_id: cashAccountId,
        notes: notes.trim() || null,
      });
      setReceivedCash('');
      setNotes('');
    } catch {
      // The domain refuses an invalid/conflicting/unauthorized confirmation with a
      // 422/403 and a reason; showing the refusal is the point (§21 of the task).
      setError(t(($) => $.trips.settlement.cashHandover.confirmFailed));
    }
  }

  return (
    <section className="flex flex-col gap-3 rounded-md border p-3">
      <div className="flex items-center gap-2">
        <HandCoins className="h-4 w-4" />
        <h4 className="text-sm font-semibold">{t(($) => $.trips.settlement.cashHandover.title)}</h4>
        {handover && (
          <Badge
            variant="outline"
            className={
              handover.is_exact
                ? 'text-[10px] text-emerald-600 dark:text-emerald-400'
                : 'text-[10px] text-destructive'
            }
          >
            {handover.is_exact ? (
              <CheckCircle2 className="me-1 h-3 w-3" />
            ) : (
              <AlertTriangle className="me-1 h-3 w-3" />
            )}
            {t(($) => $.trips.settlement.cashHandover.confirmed)}
          </Badge>
        )}
      </div>

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div className="rounded-md border p-3">
          <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.trips.settlement.cashHandover.expectedCash)}
          </p>
          <p className="mt-0.5 text-sm font-medium">{money(context.expected_cash)}</p>
        </div>
        <div className="rounded-md border p-3">
          <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.trips.settlement.cashHandover.driverDeclaredCash)}
          </p>
          <p className="mt-0.5 text-sm font-medium">{money(context.driver_declared_cash)}</p>
        </div>
      </div>

      {handover ? (
        // Already confirmed — an append-only fact. Show it; do not offer to re-enter.
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div className="rounded-md border p-3">
            <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
              {t(($) => $.trips.settlement.cashHandover.receivedCash)}
            </p>
            <p className="mt-0.5 text-sm font-medium">{money(handover.received_cash)}</p>
          </div>
          <div className="rounded-md border p-3">
            <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
              {t(($) => $.trips.settlement.cashHandover.difference)}
            </p>
            <p className="mt-0.5 text-sm font-medium">{money(handover.difference)}</p>
          </div>
          <div className="rounded-md border p-3">
            <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
              {t(($) => $.trips.settlement.cashHandover.confirmedAt)}
            </p>
            <p className="mt-0.5 text-sm font-medium">
              {new Date(handover.confirmed_at).toLocaleString(i18n.language)}
            </p>
          </div>
        </div>
      ) : (
        canConfirm && (
          <>
            <p className="text-xs text-muted-foreground">
              {t(($) => $.trips.settlement.cashHandover.description)}
            </p>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="cash-account">{t(($) => $.trips.settlement.cashHandover.destinationAccount)}</Label>
              <Select value={cashAccountId} onValueChange={setCashAccountId}>
                <SelectTrigger id="cash-account">
                  <SelectValue placeholder={t(($) => $.trips.settlement.cashHandover.selectAccount)} />
                </SelectTrigger>
                <SelectContent>
                  {context.cash_accounts.map((account) => (
                    <SelectItem key={account.id} value={account.id}>
                      {account.code} — {account.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="received-cash">{t(($) => $.trips.settlement.cashHandover.receivedCashInput)}</Label>
              {/* Deliberately left empty by default — never pre-filled with Expected
                  Cash. The receiver must explicitly count and enter the physical
                  amount (task §6: "Do NOT auto-copy expected cash into received cash
                  as an unquestioned fact"). */}
              <Input
                id="received-cash"
                type="number"
                min={0}
                step="0.01"
                value={receivedCash}
                onChange={(e) => setReceivedCash(e.target.value)}
              />
              {receivedCash !== '' && !Number.isNaN(Number(receivedCash)) && (
                <p className="text-[11px] text-muted-foreground">
                  {t(($) => $.trips.settlement.cashHandover.previewDifference)}:{' '}
                  {money(Number(receivedCash) - context.expected_cash)}
                </p>
              )}
            </div>

            <div className="flex flex-col gap-1.5">
              <Label htmlFor="handover-notes">{t(($) => $.trips.settlement.cashHandover.notes)}</Label>
              <Textarea
                id="handover-notes"
                rows={2}
                maxLength={2000}
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            </div>

            <Button
              size="sm"
              className="self-start"
              disabled={receivedCash === '' || cashAccountId === '' || confirm.isPending}
              onClick={() => void handleConfirm()}
            >
              {t(($) => $.trips.settlement.cashHandover.confirm)}
            </Button>
          </>
        )
      )}
    </section>
  );
}
