import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { EntityDrawer } from '@/components/crud';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';

import {
  useAllocatePayment,
  useApPayment,
  useAutoAllocatePayment,
  useReversePaymentPosting,
} from '../hooks/use-finance-ap';
import { backendMessage } from '../utils/backend-message';
import { PaymentStatusBadge, SupplierRef } from './ap-badges';
import { Field, Panel } from './finance-panels';

type Props = { paymentId: string | null; open: boolean; onOpenChange: (open: boolean) => void };

/**
 * Payment detail — fields, audit, an allocate-to-bill mini-form, and the
 * reverse-posting action. Mirrors JournalDetailDrawer/ExpenseDetailDrawer's
 * exact pattern: actions are IAM-gated (hidden, never merely disabled), and
 * the reversing+reason toggle is identical.
 *
 * Two deliberate absences, both because the backend does not expose what
 * they'd need (routes/api.php's `finance/ap/payments` group has no `show`
 * and no allocation-listing route — see SupplierPaymentController):
 *  - There is no single-payment GET, so the row is looked up from the
 *    already-fetched payments list by id (React Query cache) via
 *    useApPayment, instead of a fresh fetch.
 *  - There is no bill-picker endpoint, so "Allocate" targets a bill by its
 *    id (uuid) typed directly rather than a fabricated dropdown; and
 *    per-allocation reversal is omitted entirely (useReversePaymentAllocation
 *    exists in use-finance-ap.ts for when a listing endpoint is added, but
 *    nothing here can offer a picker to choose which allocation to reverse).
 */
export function PaymentDetailDrawer({ paymentId, open, onOpenChange }: Props) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const { can } = usePermission();

  const query = useApPayment(open ? paymentId : null);
  const payment = query.data;

  const allocate = useAllocatePayment();
  const autoAllocate = useAutoAllocatePayment();
  const reverse = useReversePaymentPosting();

  const [reversing, setReversing] = useState(false);
  const [reason, setReason] = useState('');
  const [billId, setBillId] = useState('');
  const [allocAmount, setAllocAmount] = useState('');
  const [autoResult, setAutoResult] = useState<{ allocations: number; unallocated: number } | null>(null);

  const close = () => {
    setReversing(false);
    setReason('');
    setBillId('');
    setAllocAmount('');
    setAutoResult(null);
    onOpenChange(false);
  };

  const busy = allocate.isPending || autoAllocate.isPending || reverse.isPending;
  const isPosted = payment?.status === 'posted';
  const canAllocate = can('finance.allocation.manage');
  const canReverse = can('finance.journal.post');
  const readyToAllocate = billId.trim() !== '' && allocAmount !== '' && Number(allocAmount) > 0;

  const footer = payment ? (
    <div className="flex w-full flex-col gap-2">
      {reversing && (
        <Textarea
          placeholder={t(($) => $.ap.detail.reverseReason)}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          maxLength={500}
        />
      )}
      <div className="flex justify-end gap-2">
        {isPosted && canReverse && !reversing && (
          <Button variant="outline" onClick={() => setReversing(true)} disabled={busy}>
            {t(($) => $.gl.actions.reverse)}
          </Button>
        )}
        {isPosted && canReverse && reversing && (
          <Button
            onClick={() => reverse.mutate({ uuid: payment.id, reason: reason.trim() }, { onSuccess: close })}
            disabled={busy || reason.trim() === ''}
          >
            {t(($) => $.gl.actions.confirmReverse)}
          </Button>
        )}
        <Button variant="ghost" onClick={close}>{t(($) => $.gl.actions.close)}</Button>
      </div>
    </div>
  ) : undefined;

  return (
    <EntityDrawer
      open={open}
      onOpenChange={(o) => (o ? onOpenChange(true) : close())}
      title={payment ? payment.number : t(($) => $.ap.detail.title)}
      footer={footer}
    >
      {query.isLoading && <p className="text-sm text-muted-foreground">{t(($) => $.loading)}</p>}
      {query.isError && <p className="text-sm text-red-600">{t(($) => $.error)}</p>}
      {!query.isLoading && !query.isError && !payment && (
        <p className="text-sm text-muted-foreground">{t(($) => $.ap.detail.notFound)}</p>
      )}

      {payment && (
        <div className="space-y-5">
          <div className="flex items-center justify-between">
            <PaymentStatusBadge status={payment.status} />
            <span className="text-sm text-muted-foreground">{fmt.date(payment.payment_date)}</span>
          </div>

          <Section title={t(($) => $.ap.detail.amount)}>
            <Row label={t(($) => $.ap.payment.supplier)} value={<SupplierRef id={payment.supplier_id} />} />
            <Row label={t(($) => $.ap.payment.amount)} value={fmt.money(payment.amount, payment.currency)} />
            {payment.unallocated != null && (
              <Row label={t(($) => $.ap.payment.unallocated)} value={fmt.money(payment.unallocated, payment.currency)} />
            )}
          </Section>

          <Section title={t(($) => $.ap.detail.audit)}>
            <Row label={t(($) => $.gl.journal.approvedBy)} value={idLabel(payment.approved_by)} />
            <Row label={t(($) => $.gl.journal.postedAt)} value={fmt.dateTime(payment.posted_at)} />
            {payment.journal_entry_id != null && (
              <Row label={t(($) => $.ap.detail.journal)} value={`#${payment.journal_entry_id}`} />
            )}
          </Section>

          {isPosted && canAllocate && (
            <Panel title={t(($) => $.ap.detail.allocationTitle)} hint={t(($) => $.ap.detail.allocationHint)}>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field id="ap-alloc-bill" label={t(($) => $.ap.detail.billId)}>
                  <Input
                    id="ap-alloc-bill"
                    dir="ltr"
                    value={billId}
                    onChange={(e) => setBillId(e.target.value)}
                  />
                </Field>
                <Field id="ap-alloc-amount" label={t(($) => $.ap.detail.allocateAmount)}>
                  <Input
                    id="ap-alloc-amount"
                    type="number"
                    min={0}
                    step="0.01"
                    value={allocAmount}
                    onChange={(e) => setAllocAmount(e.target.value)}
                  />
                </Field>
              </div>

              <div className="flex flex-wrap gap-2">
                <Button
                  size="sm"
                  disabled={!readyToAllocate || busy}
                  onClick={() => {
                    allocate.mutate(
                      { uuid: payment.id, billId: billId.trim(), amount: Number(allocAmount) },
                      { onSuccess: () => { setBillId(''); setAllocAmount(''); } },
                    );
                  }}
                >
                  {t(($) => $.ap.action.allocate)}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={busy}
                  onClick={() => {
                    autoAllocate.mutate(payment.id, {
                      onSuccess: (result) =>
                        setAutoResult({ allocations: result.allocations, unallocated: result.payment_unallocated }),
                    });
                  }}
                >
                  {t(($) => $.ap.action.autoAllocate)}
                </Button>
              </div>

              {allocate.isError && (
                <p className="text-xs text-red-600">
                  {backendMessage(allocate.error) ?? t(($) => $.ap.detail.allocateFailed)}
                </p>
              )}
              {autoAllocate.isError && (
                <p className="text-xs text-red-600">
                  {backendMessage(autoAllocate.error) ?? t(($) => $.ap.detail.autoAllocateFailed)}
                </p>
              )}
              {autoResult && (
                <p className="text-xs text-muted-foreground">
                  {t(($) => $.ap.detail.autoAllocateResult, {
                    count: autoResult.allocations,
                    remaining: fmt.money(autoResult.unallocated, payment.currency),
                  })}
                </p>
              )}
              <p className="text-xs text-muted-foreground">{t(($) => $.ap.detail.noAllocationHistory)}</p>
            </Panel>
          )}
        </div>
      )}
    </EntityDrawer>
  );
}

function idLabel(id: number | null): string {
  return id == null ? '—' : `#${id}`;
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div>
      <h4 className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{title}</h4>
      <dl className="space-y-1.5">{children}</dl>
    </div>
  );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="text-end font-medium tabular-nums">{value}</dd>
    </div>
  );
}
