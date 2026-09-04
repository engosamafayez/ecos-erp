import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { PageDrawer } from '@/components/page';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';

import {
  useAllocateReceipt,
  useArReceipt,
  useAutoAllocateReceipt,
  useReverseReceiptPosting,
} from '../hooks/use-finance-ar';
import { backendMessage } from '../utils/backend-message';
import { CustomerRef, DocumentStatusBadge } from './ar-badges';
import { Field, Panel } from './finance-panels';

type Props = { receiptId: string | null; open: boolean; onOpenChange: (open: boolean) => void };

/**
 * Receipt detail — fields, audit, an allocate-to-invoice mini-form, and the
 * reverse-posting action. Mirrors JournalDetailDrawer/ExpenseDetailDrawer's
 * exact pattern (the AP PaymentDetailDrawer's exact structure, in fact —
 * this is its AR mirror): actions are IAM-gated (hidden, never merely
 * disabled), and the reversing+reason toggle is identical.
 *
 * Two deliberate absences, both because the backend does not expose what
 * they'd need (routes/api.php's `finance/ar/receipts` group has no `show`
 * and no allocation-listing route — see CustomerReceiptController):
 *  - There is no single-receipt GET, so the row is looked up from the
 *    already-fetched receipts list by id (React Query cache) via
 *    useArReceipt, instead of a fresh fetch.
 *  - There is no invoice-picker endpoint, so "Allocate" targets an invoice
 *    by its id (uuid) typed directly rather than a fabricated dropdown; and
 *    per-allocation reversal is omitted entirely (useReverseReceiptAllocation
 *    exists in use-finance-ar.ts for when a listing endpoint is added, but
 *    nothing here can offer a picker to choose which allocation to reverse).
 */
export function ReceiptDetailDrawer({ receiptId, open, onOpenChange }: Props) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const { can } = usePermission();

  const query = useArReceipt(open ? receiptId : null);
  const receipt = query.data;

  const allocate = useAllocateReceipt();
  const autoAllocate = useAutoAllocateReceipt();
  const reverse = useReverseReceiptPosting();

  const [reversing, setReversing] = useState(false);
  const [reason, setReason] = useState('');
  const [invoiceId, setInvoiceId] = useState('');
  const [allocAmount, setAllocAmount] = useState('');
  const [autoResult, setAutoResult] = useState<{ allocations: number; unallocated: number } | null>(null);

  const close = () => {
    setReversing(false);
    setReason('');
    setInvoiceId('');
    setAllocAmount('');
    setAutoResult(null);
    onOpenChange(false);
  };

  const busy = allocate.isPending || autoAllocate.isPending || reverse.isPending;
  const isPosted = receipt?.status === 'posted';
  const canAllocate = can('finance.allocation.manage');
  const canReverse = can('finance.journal.post');
  const readyToAllocate = invoiceId.trim() !== '' && allocAmount !== '' && Number(allocAmount) > 0;

  const footer = receipt ? (
    <div className="flex w-full flex-col gap-2">
      {reversing && (
        <Textarea
          placeholder={t(($) => $.ar.detail.reverseReason)}
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
            onClick={() => reverse.mutate({ uuid: receipt.id, reason: reason.trim() }, { onSuccess: close })}
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
    <PageDrawer
      open={open}
      onOpenChange={(o) => (o ? onOpenChange(true) : close())}
      title={receipt ? receipt.number : t(($) => $.ar.detail.title)}
      size="lg"
      footer={footer}
    >
      {query.isLoading && <p className="text-sm text-muted-foreground">{t(($) => $.loading)}</p>}
      {query.isError && <p className="text-sm text-red-600">{t(($) => $.error)}</p>}
      {!query.isLoading && !query.isError && !receipt && (
        <p className="text-sm text-muted-foreground">{t(($) => $.ar.detail.notFound)}</p>
      )}

      {receipt && (
        <div className="space-y-5">
          <div className="flex items-center justify-between">
            <DocumentStatusBadge status={receipt.status} />
            <span className="text-sm text-muted-foreground">{fmt.date(receipt.receipt_date)}</span>
          </div>

          <Section title={t(($) => $.ar.detail.amount)}>
            <Row label={t(($) => $.ar.receipt.customer)} value={<CustomerRef id={receipt.customer_id} />} />
            <Row label={t(($) => $.ar.receipt.amount)} value={fmt.money(receipt.amount, receipt.currency)} />
            {receipt.unallocated != null && (
              <Row label={t(($) => $.ar.receipt.unallocated)} value={fmt.money(receipt.unallocated, receipt.currency)} />
            )}
          </Section>

          <Section title={t(($) => $.ar.detail.audit)}>
            <Row label={t(($) => $.gl.journal.postedAt)} value={fmt.dateTime(receipt.posted_at)} />
            {receipt.journal_entry_id != null && (
              <Row label={t(($) => $.ar.detail.journal)} value={`#${receipt.journal_entry_id}`} />
            )}
            {receipt.source_type && (
              <Row label={t(($) => $.ar.detail.source)} value={`${receipt.source_type} · ${receipt.source_id ?? '—'}`} />
            )}
          </Section>

          {isPosted && canAllocate && (
            <Panel title={t(($) => $.ar.detail.allocationTitle)} hint={t(($) => $.ar.detail.allocationHint)}>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field id="ar-alloc-invoice" label={t(($) => $.ar.detail.invoiceId)}>
                  <Input
                    id="ar-alloc-invoice"
                    dir="ltr"
                    value={invoiceId}
                    onChange={(e) => setInvoiceId(e.target.value)}
                  />
                </Field>
                <Field id="ar-alloc-amount" label={t(($) => $.ar.detail.allocateAmount)}>
                  <Input
                    id="ar-alloc-amount"
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
                      { uuid: receipt.id, invoiceId: invoiceId.trim(), amount: Number(allocAmount) },
                      { onSuccess: () => { setInvoiceId(''); setAllocAmount(''); } },
                    );
                  }}
                >
                  {t(($) => $.ar.action.allocate)}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={busy}
                  onClick={() => {
                    autoAllocate.mutate(receipt.id, {
                      onSuccess: (result) =>
                        setAutoResult({ allocations: result.allocations, unallocated: result.receipt_unallocated }),
                    });
                  }}
                >
                  {t(($) => $.ar.action.autoAllocate)}
                </Button>
              </div>

              {allocate.isError && (
                <p className="text-xs text-red-600">
                  {backendMessage(allocate.error) ?? t(($) => $.ar.detail.allocateFailed)}
                </p>
              )}
              {autoAllocate.isError && (
                <p className="text-xs text-red-600">
                  {backendMessage(autoAllocate.error) ?? t(($) => $.ar.detail.autoAllocateFailed)}
                </p>
              )}
              {autoResult && (
                <p className="text-xs text-muted-foreground">
                  {t(($) => $.ar.detail.autoAllocateResult, {
                    count: autoResult.allocations,
                    remaining: fmt.money(autoResult.unallocated, receipt.currency),
                  })}
                </p>
              )}
              <p className="text-xs text-muted-foreground">{t(($) => $.ar.detail.noAllocationHistory)}</p>
            </Panel>
          )}
        </div>
      )}
    </PageDrawer>
  );
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
