import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

import { useAccounts } from '../hooks/use-finance-gl';
import { useCreateExpenseCategory } from '../hooks/use-finance-expense';

type Props = { open: boolean; onOpenChange: (open: boolean) => void };

/**
 * The smallest canonical Expense-Category → account mapping (no account is
 * ever created here — a category only NAMES an existing postable expense
 * account, TASK-ECOS-FINANCE-OPERATIONAL-COST-ACCOUNTING-007's own
 * ExpenseCategory design).
 */
export function ExpenseCategoryFormDialog({ open, onOpenChange }: Props) {
  const { t } = useTranslation('finance');
  const accounts = useAccounts({ type: 'expense', postable_only: true });
  const create = useCreateExpenseCategory();

  const [name, setName] = useState('');
  const [accountId, setAccountId] = useState('');
  const [error, setError] = useState<string | null>(null);

  const canSubmit = name.trim() !== '' && accountId !== '' && !create.isPending;

  const close = () => { setName(''); setAccountId(''); setError(null); onOpenChange(false); };

  const submit = () => {
    setError(null);
    if (!canSubmit) return;
    create.mutate(
      { name: name.trim(), expense_account_id: accountId },
      { onSuccess: close, onError: () => setError(t(($) => $.expense.category.form.error)) },
    );
  };

  return (
    <Dialog open={open} onOpenChange={(o) => (o ? onOpenChange(true) : close())}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t(($) => $.expense.category.form.title)}</DialogTitle>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">{t(($) => $.expense.category.field.name)} *</Label>
            <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={120} />
          </div>
          <div className="space-y-1.5">
            <Label className="text-xs text-muted-foreground">{t(($) => $.expense.category.field.account)} *</Label>
            <Select value={accountId} onValueChange={setAccountId}>
              <SelectTrigger><SelectValue placeholder={t(($) => $.expense.category.field.account)} /></SelectTrigger>
              <SelectContent>
                {(accounts.data ?? []).map((a) => (
                  <SelectItem key={a.id} value={a.id}>{a.code} · {a.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          {error && <p className="text-sm text-red-600">{error}</p>}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={close}>{t(($) => $.gl.actions.cancel)}</Button>
          <Button onClick={submit} disabled={!canSubmit}>
            {create.isPending ? t(($) => $.gl.actions.saving) : t(($) => $.gl.actions.create)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
