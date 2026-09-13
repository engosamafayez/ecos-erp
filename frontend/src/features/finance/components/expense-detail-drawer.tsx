import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { EntityDrawer } from '@/components/crud';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';

import {
  useApproveExpense,
  useExpense,
  usePostExpense,
  useReverseExpensePosting,
} from '../hooks/use-finance-expense';
import { ExpenseStatusBadge } from './expense-badges';

type Props = { expenseId: string | null; open: boolean; onOpenChange: (open: boolean) => void };

/**
 * Full expense detail — status, posting info, audit, and the maker → checker
 * → poster → reverse actions. Actions are IAM-gated (hidden, never merely
 * disabled) and mirror JournalDetailDrawer's exact approve/reverse pattern
 * (TASK §16 segregation of duties: a maker without approval authority never
 * sees the approve/post/reverse controls, regardless of who created it).
 */
export function ExpenseDetailDrawer({ expenseId, open, onOpenChange }: Props) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const { can } = usePermission();

  const query = useExpense(open ? expenseId : null);
  const expense = query.data;

  const approve = useApproveExpense();
  const post = usePostExpense();
  const reverse = useReverseExpensePosting();
  const [reversing, setReversing] = useState(false);
  const [reason, setReason] = useState('');

  const close = () => { setReversing(false); setReason(''); onOpenChange(false); };
  const busy = approve.isPending || post.isPending || reverse.isPending;

  const isDraft = expense?.status === 'draft';
  const isApproved = expense?.status === 'approved';
  const isPosted = expense?.status === 'posted';
  const canApprovePost = can('finance.expense.approve');

  const footer = expense ? (
    <div className="flex w-full flex-col gap-2">
      {reversing && (
        <Textarea
          placeholder={t(($) => $.expense.detail.reverseReason)}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          maxLength={500}
        />
      )}
      <div className="flex justify-end gap-2">
        {isDraft && canApprovePost && (
          <Button onClick={() => approve.mutate(expense.id, { onSuccess: close })} disabled={busy}>
            {t(($) => $.gl.actions.approve)}
          </Button>
        )}
        {isApproved && canApprovePost && (
          <Button onClick={() => post.mutate(expense.id, { onSuccess: close })} disabled={busy}>
            {t(($) => $.expense.detail.post)}
          </Button>
        )}
        {isPosted && canApprovePost && !reversing && (
          <Button variant="outline" onClick={() => setReversing(true)} disabled={busy}>
            {t(($) => $.gl.actions.reverse)}
          </Button>
        )}
        {isPosted && canApprovePost && reversing && (
          <Button
            onClick={() => reverse.mutate({ uuid: expense.id, reason: reason.trim() }, { onSuccess: close })}
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
      title={expense ? expense.number : t(($) => $.expense.detail.title)}
      footer={footer}
    >
      {query.isLoading && <p className="text-sm text-muted-foreground">{t(($) => $.loading)}</p>}
      {query.isError && <p className="text-sm text-red-600">{t(($) => $.error)}</p>}
      {expense && (
        <div className="space-y-5">
          <div className="flex items-center justify-between">
            <ExpenseStatusBadge status={expense.status} />
            <span className="text-sm text-muted-foreground">{fmt.date(expense.expense_date)}</span>
          </div>

          <Section title={t(($) => $.expense.detail.amount)}>
            <Row label={t(($) => $.expense.field.amount)} value={fmt.money(expense.amount, expense.currency)} />
          </Section>

          <Section title={t(($) => $.expense.detail.audit)}>
            <Row label={t(($) => $.gl.journal.approvedBy)} value={idLabel(expense.approved_by)} />
            <Row label={t(($) => $.gl.journal.postedAt)} value={fmt.dateTime(expense.posted_at)} />
            {expense.journal_entry_id != null && (
              <Row label={t(($) => $.expense.detail.journal)} value={`#${expense.journal_entry_id}`} />
            )}
            {expense.source_type && (
              <Row label={t(($) => $.expense.detail.source)} value={`${expense.source_type} · ${expense.source_id ?? '—'}`} />
            )}
          </Section>
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

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-4 text-sm">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="text-end font-medium tabular-nums">{value}</dd>
    </div>
  );
}
