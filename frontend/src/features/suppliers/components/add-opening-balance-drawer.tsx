import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import axios from 'axios';

import { EntityDrawer } from '@/components/crud/entity-drawer';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ds/use-toast';
import { usePostSupplierOpeningBalance } from '@/features/suppliers/hooks/use-suppliers';
import type { Supplier } from '@/features/suppliers/types/supplier';

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

type Direction = 'payable' | 'advance';

type Props = {
  supplier: Supplier | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-FINAL-USER-REVIEW-REMEDIATION-002 §6.
 *
 * The missing discoverable UI for an already-built, already-tested backend capability
 * (`SupplierOpeningBalanceService` / `POST /suppliers/{id}/opening-balance`). Posts through the
 * canonical Supplier AP/Finance authority — idempotent, auditable, no parallel ledger. A posted
 * opening balance cannot be edited from here (the backend has no update path for it — a second
 * post for the same direction is a silent no-op); a correction goes through Finance's normal
 * adjustment/reversal tooling, not through this form.
 */
export function AddOpeningBalanceDrawer({ supplier, open, onOpenChange }: Props) {
  const { t } = useTranslation('suppliers');
  const [direction, setDirection] = useState<Direction>('payable');
  const [amount, setAmount] = useState('');
  const [openingDate, setOpeningDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [reference, setReference] = useState('');
  const [notes, setNotes] = useState('');

  const post = usePostSupplierOpeningBalance(supplier?.id ?? '');

  if (!supplier) return null;

  const amountValue = parseFloat(amount);
  const canSubmit = amount.trim() !== '' && Number.isFinite(amountValue) && amountValue > 0 && openingDate.trim() !== '';

  function reset() {
    setDirection('payable');
    setAmount('');
    setOpeningDate(new Date().toISOString().slice(0, 10));
    setReference('');
    setNotes('');
  }

  function handleSave() {
    if (!canSubmit) return;

    post.mutate(
      {
        type: direction,
        amount: amountValue,
        opening_date: openingDate,
        reference: reference.trim() || undefined,
        notes: notes.trim() || undefined,
      },
      {
        onSuccess: () => {
          toast.success(t($ => $.drawer360.financial.openingBalance.posted));
          reset();
          onOpenChange(false);
        },
        onError: (error) => toast.error(extractMessage(error)),
      },
    );
  }

  return (
    <EntityDrawer
      open={open}
      onOpenChange={(next) => {
        if (!next) reset();
        onOpenChange(next);
      }}
      title={t($ => $.drawer360.financial.openingBalance.title)}
      description={supplier.name}
      footer={
        <>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={post.isPending}>
            {t($ => $.drawer360.financial.openingBalance.cancel)}
          </Button>
          <Button onClick={handleSave} disabled={!canSubmit || post.isPending}>
            {post.isPending ? t($ => $.drawer360.financial.openingBalance.saving) : t($ => $.drawer360.financial.openingBalance.save)}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">{t($ => $.drawer360.financial.openingBalance.description)}</p>

        <div>
          <Label className="text-xs">{t($ => $.drawer360.financial.openingBalance.direction)}</Label>
          <div className="mt-2 flex flex-col gap-2">
            <label className="flex items-start gap-2 rounded-lg border p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
              <input
                type="radio"
                name="opening-balance-direction"
                className="mt-0.5"
                checked={direction === 'payable'}
                onChange={() => setDirection('payable')}
              />
              <span className="text-sm">
                <span className="block font-medium">{t($ => $.drawer360.financial.openingBalance.companyOwesSupplier)}</span>
                <span className="block text-xs text-muted-foreground mt-0.5">{t($ => $.drawer360.financial.openingBalance.companyOwesSupplierHint)}</span>
              </span>
            </label>
            <label className="flex items-start gap-2 rounded-lg border p-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
              <input
                type="radio"
                name="opening-balance-direction"
                className="mt-0.5"
                checked={direction === 'advance'}
                onChange={() => setDirection('advance')}
              />
              <span className="text-sm">
                <span className="block font-medium">{t($ => $.drawer360.financial.openingBalance.supplierOwesCompany)}</span>
                <span className="block text-xs text-muted-foreground mt-0.5">{t($ => $.drawer360.financial.openingBalance.supplierOwesCompanyHint)}</span>
              </span>
            </label>
          </div>
        </div>

        <div>
          <Label className="text-xs" htmlFor="opening-balance-amount">{t($ => $.drawer360.financial.openingBalance.amount)}</Label>
          <Input
            id="opening-balance-amount"
            type="number"
            min="0"
            step="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            className="mt-1"
          />
        </div>

        <div>
          <Label className="text-xs" htmlFor="opening-balance-date">{t($ => $.drawer360.financial.openingBalance.effectiveDate)}</Label>
          <Input
            id="opening-balance-date"
            type="date"
            value={openingDate}
            onChange={(e) => setOpeningDate(e.target.value)}
            className="mt-1"
          />
        </div>

        <div>
          <Label className="text-xs" htmlFor="opening-balance-reference">{t($ => $.drawer360.financial.openingBalance.reference)}</Label>
          <Input
            id="opening-balance-reference"
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            className="mt-1"
          />
        </div>

        <div>
          <Label className="text-xs" htmlFor="opening-balance-notes">{t($ => $.drawer360.financial.openingBalance.note)}</Label>
          <Textarea
            id="opening-balance-notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="mt-1"
            rows={3}
          />
        </div>
      </div>
    </EntityDrawer>
  );
}
