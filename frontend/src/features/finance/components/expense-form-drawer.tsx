import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { PageDrawer } from '@/components/page';

import { useAccounts } from '../hooks/use-finance-gl';
import { useCreateExpense, useExpenseCategories } from '../hooks/use-finance-expense';

type Props = { open: boolean; onOpenChange: (open: boolean) => void };

const today = () => new Date().toISOString().slice(0, 10);

/**
 * Create a draft Finance expense (POST /finance/expenses). The maker only
 * creates here — approve/post are separate, checker-gated actions on the
 * list (TASK §16 segregation of duties).
 */
export function ExpenseFormDrawer({ open, onOpenChange }: Props) {
  const { t } = useTranslation('finance');
  const categories = useExpenseCategories();
  const fundingAccounts = useAccounts({ postable_only: true });
  const create = useCreateExpense();

  const [categoryId, setCategoryId] = useState('');
  const [number, setNumber] = useState('');
  const [expenseDate, setExpenseDate] = useState(today());
  const [amount, setAmount] = useState('');
  const [fundingAccountId, setFundingAccountId] = useState('');
  const [description, setDescription] = useState('');
  const [error, setError] = useState<string | null>(null);

  const canSubmit =
    categoryId !== '' && number.trim() !== '' && expenseDate !== '' &&
    Number(amount) > 0 && fundingAccountId !== '' && !create.isPending;

  const reset = () => {
    setCategoryId(''); setNumber(''); setExpenseDate(today());
    setAmount(''); setFundingAccountId(''); setDescription(''); setError(null);
  };
  const close = () => { reset(); onOpenChange(false); };

  const submit = () => {
    setError(null);
    if (!canSubmit) return;
    create.mutate(
      {
        expense_category_id: categoryId,
        number: number.trim(),
        expense_date: expenseDate,
        amount: Number(amount),
        funding_account_id: fundingAccountId,
        description: description.trim() || undefined,
      },
      { onSuccess: close, onError: () => setError(t(($) => $.expense.form.error)) },
    );
  };

  return (
    <PageDrawer
      open={open}
      onOpenChange={(o) => (o ? onOpenChange(true) : close())}
      title={t(($) => $.expense.form.title)}
      description={t(($) => $.expense.form.subtitle)}
      size="2xl"
      footer={
        <div className="flex w-full items-center justify-end gap-2">
          <Button variant="outline" onClick={close}>{t(($) => $.gl.actions.cancel)}</Button>
          <Button onClick={submit} disabled={!canSubmit}>
            {create.isPending ? t(($) => $.gl.actions.saving) : t(($) => $.gl.actions.create)}
          </Button>
        </div>
      }
    >
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">{t(($) => $.expense.field.category)} *</Label>
            <Select value={categoryId} onValueChange={setCategoryId}>
              <SelectTrigger><SelectValue placeholder={t(($) => $.expense.field.category)} /></SelectTrigger>
              <SelectContent>
                {(categories.data ?? []).map((c) => (
                  <SelectItem key={c.id} value={c.id}>{c.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">{t(($) => $.expense.field.number)} *</Label>
            <Input value={number} onChange={(e) => setNumber(e.target.value)} maxLength={60} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">{t(($) => $.expense.field.date)} *</Label>
            <Input type="date" value={expenseDate} onChange={(e) => setExpenseDate(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">{t(($) => $.expense.field.amount)} *</Label>
            <Input
              type="number" min="0" step="0.01" inputMode="decimal"
              value={amount} onChange={(e) => setAmount(e.target.value)}
              className="text-end tabular-nums"
            />
          </div>
        </div>

        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">{t(($) => $.expense.field.fundingAccount)} *</Label>
          <Select value={fundingAccountId} onValueChange={setFundingAccountId}>
            <SelectTrigger><SelectValue placeholder={t(($) => $.expense.field.fundingAccount)} /></SelectTrigger>
            <SelectContent>
              {(fundingAccounts.data ?? []).map((a) => (
                <SelectItem key={a.id} value={a.id}>{a.code} · {a.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <Label className="text-xs text-muted-foreground">{t(($) => $.expense.field.description)}</Label>
          <Input value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
        </div>

        {error && <p className="text-sm text-red-600">{error}</p>}
      </div>
    </PageDrawer>
  );
}
