import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PageDrawer } from '@/components/page';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';

import { useApBill, useApplyAdvance, useSupplierFinancialSummary } from '../hooks/use-finance-ap';
import { backendMessage } from '../utils/backend-message';
import { BillStatusBadge, SupplierRef } from './ap-badges';
import { Field, Panel, Stat } from './finance-panels';

type Props = { billId: string | null; open: boolean; onOpenChange: (open: boolean) => void };

/**
 * Bill detail — fields plus the explicit "Apply Supplier Advance" action
 * (TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-FINAL-IMPLEMENTATION-CLOSURE-002).
 * Mirrors PaymentDetailDrawer's exact shape: a review-then-confirm toggle (here
 * `applying`, there `reversing`), IAM-gated (hidden, never merely disabled), and
 * looked up from the already-fetched bills list (no single-bill GET exists — see
 * useApBill).
 *
 * By explicit CTO decision this is a USER-INITIATED, SINGLE-BILL, CONFIRMED action
 * only — never an automatic sweep across a supplier's bills, and never triggered by
 * any lifecycle event. The amount defaults to min(available advance, bill
 * outstanding) but is user-editable within that same cap; the figures shown below
 * are a live PREVIEW only — the backend (SupplierOpeningBalanceService::
 * applyAdvanceToBill(), unchanged) remains the sole authority on eligibility and
 * capping, and is re-checked on every submission regardless of what this preview
 * shows. A stable idempotency key is minted once per review (not per keystroke, not
 * per click) and reused across retries of that same confirmation, so a double-click
 * or client retry cannot apply the same advance twice — see useApplyAdvance.
 */
export function BillDetailDrawer({ billId, open, onOpenChange }: Props) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const { can } = usePermission();

  const query = useApBill(open ? billId : null);
  const bill = query.data;

  const summary = useSupplierFinancialSummary(open ? bill?.supplier_id ?? null : null);
  const apply = useApplyAdvance();

  const [applying, setApplying] = useState(false);
  const [amount, setAmount] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState('');

  const close = () => {
    setApplying(false);
    setAmount('');
    onOpenChange(false);
  };

  const canApply = can('finance.ap.advance.apply');
  const availableAdvance = summary.data?.available_advance ?? 0;
  const outstanding = bill?.outstanding ?? 0;
  const isEligible = bill?.status === 'posted' && outstanding > 0;
  const maxApplicable = Math.min(availableAdvance, outstanding);

  const startApplying = () => {
    const suggested = maxApplicable > 0 ? maxApplicable : 0;
    setAmount(suggested > 0 ? String(suggested) : '');
    setIdempotencyKey(crypto.randomUUID());
    setApplying(true);
  };

  const cancelApplying = () => {
    setApplying(false);
    setAmount('');
  };

  const parsedAmount = Number(amount);
  const validAmount = amount !== '' && Number.isFinite(parsedAmount) && parsedAmount > 0 && parsedAmount <= maxApplicable;
  const resultingOutstanding = validAmount ? outstanding - parsedAmount : outstanding;
  const remainingAdvance = validAmount ? availableAdvance - parsedAmount : availableAdvance;

  const confirmApply = () => {
    if (!bill || !validAmount) return;
    apply.mutate(
      { uuid: bill.id, amount: parsedAmount, idempotencyKey },
      { onSuccess: () => { setApplying(false); setAmount(''); } },
    );
  };

  return (
    <PageDrawer
      open={open}
      onOpenChange={(o) => (o ? onOpenChange(true) : close())}
      title={bill ? bill.number : t(($) => $.ap.billDetail.title)}
      size="lg"
    >
      {query.isLoading && <p className="text-sm text-muted-foreground">{t(($) => $.loading)}</p>}
      {query.isError && <p className="text-sm text-red-600">{t(($) => $.error)}</p>}
      {!query.isLoading && !query.isError && !bill && (
        <p className="text-sm text-muted-foreground">{t(($) => $.ap.billDetail.notFound)}</p>
      )}

      {bill && (
        <div className="space-y-5">
          <div className="flex items-center justify-between">
            <BillStatusBadge status={bill.status} />
            <span className="text-sm text-muted-foreground">{fmt.date(bill.bill_date)}</span>
          </div>

          <dl className="space-y-1.5">
            <Row label={t(($) => $.ap.bill.supplier)} value={<SupplierRef id={bill.supplier_id} />} />
            <Row label={t(($) => $.ap.bill.total)} value={fmt.money(bill.total, bill.currency)} />
            <Row label={t(($) => $.ap.bill.outstanding)} value={fmt.money(outstanding, bill.currency)} />
            {bill.journal_entry_id != null && (
              <Row label={t(($) => $.ap.billDetail.journal)} value={`#${bill.journal_entry_id}`} />
            )}
          </dl>

          {isEligible && canApply && !applying && (
            <div className="flex justify-end">
              <Button size="sm" onClick={startApplying} disabled={summary.isLoading || maxApplicable <= 0}>
                {t(($) => $.ap.billDetail.apply)}
              </Button>
            </div>
          )}
          {isEligible && canApply && !applying && !summary.isLoading && maxApplicable <= 0 && (
            <p className="text-xs text-muted-foreground">{t(($) => $.ap.billDetail.noAdvanceAvailable)}</p>
          )}

          {isEligible && canApply && applying && (
            <Panel title={t(($) => $.ap.billDetail.advanceTitle)} hint={t(($) => $.ap.billDetail.advanceHint)}>
              <div className="grid gap-3 sm:grid-cols-2">
                <Stat label={t(($) => $.ap.billDetail.availableAdvance)} value={fmt.money(availableAdvance, bill.currency)} />
                <Stat label={t(($) => $.ap.bill.outstanding)} value={fmt.money(outstanding, bill.currency)} />
              </div>

              <Field id="apply-advance-amount" label={t(($) => $.ap.billDetail.amountToApply)}>
                <Input
                  id="apply-advance-amount"
                  type="number"
                  min={0.01}
                  max={maxApplicable}
                  step="0.01"
                  dir="ltr"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                />
              </Field>

              <div className="grid gap-3 sm:grid-cols-2">
                <Stat
                  label={t(($) => $.ap.billDetail.resultingOutstanding)}
                  value={fmt.money(resultingOutstanding, bill.currency)}
                  tone={resultingOutstanding < 0 ? 'danger' : 'default'}
                />
                <Stat
                  label={t(($) => $.ap.billDetail.remainingAdvance)}
                  value={fmt.money(remainingAdvance, bill.currency)}
                  tone={remainingAdvance < 0 ? 'danger' : 'default'}
                />
              </div>

              <div className="flex flex-wrap justify-end gap-2">
                <Button variant="ghost" size="sm" onClick={cancelApplying} disabled={apply.isPending}>
                  {t(($) => $.gl.actions.close)}
                </Button>
                <Button size="sm" onClick={confirmApply} disabled={!validAmount || apply.isPending}>
                  {t(($) => $.ap.billDetail.confirmApply)}
                </Button>
              </div>

              {apply.isError && (
                <p className="text-xs text-red-600">
                  {backendMessage(apply.error) ?? t(($) => $.ap.billDetail.applyFailed)}
                </p>
              )}
            </Panel>
          )}
        </div>
      )}
    </PageDrawer>
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
