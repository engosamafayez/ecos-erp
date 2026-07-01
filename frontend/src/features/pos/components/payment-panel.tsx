import { useState } from 'react';
import { Banknote, CreditCard, Wallet, X, Check } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import { useCart, useProcessSale } from '@/features/pos/hooks/use-pos-queries';
import { usePosStore } from '@/features/pos/store/pos-store';
import type { PaymentMethod, PaymentTender } from '@/features/pos/types';

const PAYMENT_METHODS: { method: PaymentMethod; label: string; icon: React.ElementType }[] = [
  { method: 'cash',         label: 'Cash',         icon: Banknote },
  { method: 'card',         label: 'Card',         icon: CreditCard },
  { method: 'wallet',       label: 'Wallet',       icon: Wallet },
  { method: 'store_credit', label: 'Store Credit', icon: CreditCard },
];

const QUICK_AMOUNTS = [50, 100, 200, 500];

type PaymentPanelProps = {
  onClose: () => void;
  onSuccess: () => void;
};

export function PaymentPanel({ onClose, onSuccess }: PaymentPanelProps) {
  const { cartId, currency } = usePosStore();
  const { data: cart } = useCart();
  const processSale = useProcessSale();

  const total = parseFloat(cart?.total?.amount ?? '0');
  const [method, setMethod] = useState<PaymentMethod>('cash');
  const [amountStr, setAmountStr] = useState('');
  const [tenders, setTenders] = useState<PaymentTender[]>([]);

  const tendered = tenders.reduce((s, t) => s + parseFloat(t.amount), 0);
  const remaining = Math.max(0, total - tendered);
  const change = Math.max(0, tendered - total);
  const isSufficient = tendered >= total;

  const currentAmount = amountStr ? parseFloat(amountStr) : remaining;

  function addTender() {
    if (currentAmount <= 0) return;
    setTenders((prev) => [...prev, { method, amount: currentAmount.toFixed(2) }]);
    setAmountStr('');
  }

  async function handlePay() {
    if (!cartId || !isSufficient) return;

    const payments = tenders.length === 0
      ? [{ method, amount: total.toFixed(2) }]
      : tenders;

    await processSale.mutateAsync({ cart_id: cartId, payments });
    onSuccess();
  }

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 shrink-0">
        <h2 className="text-base font-semibold">Payment</h2>
        <Button variant="ghost" size="icon" className="size-8" onClick={onClose}>
          <X className="size-4" />
        </Button>
      </div>

      <Separator />

      <div className="flex-1 overflow-y-auto px-4 py-3 space-y-4">
        {/* Total due */}
        <div className="rounded-lg bg-muted p-3">
          <div className="flex justify-between text-sm text-muted-foreground">
            <span>Total Due</span>
            <span>{currency} {total.toFixed(2)}</span>
          </div>
          {tendered > 0 && (
            <>
              <div className="flex justify-between text-sm text-muted-foreground">
                <span>Tendered</span>
                <span>{currency} {tendered.toFixed(2)}</span>
              </div>
              <Separator className="my-2" />
              <div className={cn('flex justify-between text-base font-bold', remaining > 0 ? 'text-destructive' : 'text-emerald-600')}>
                <span>{remaining > 0 ? 'Remaining' : 'Change'}</span>
                <span>{currency} {remaining > 0 ? remaining.toFixed(2) : change.toFixed(2)}</span>
              </div>
            </>
          )}
        </div>

        {/* Method selector */}
        <div>
          <Label className="mb-2 text-xs">Payment Method</Label>
          <div className="grid grid-cols-2 gap-2">
            {PAYMENT_METHODS.map(({ method: m, label, icon: Icon }) => (
              <button
                key={m}
                onClick={() => setMethod(m)}
                className={cn(
                  'flex items-center gap-2 rounded-md border p-2.5 text-sm font-medium transition-colors',
                  method === m
                    ? 'border-primary bg-primary/10 text-primary'
                    : 'hover:bg-accent',
                )}
              >
                <Icon className="size-4" />
                {label}
              </button>
            ))}
          </div>
        </div>

        {/* Amount */}
        <div>
          <Label className="mb-2 text-xs">Amount</Label>
          <Input
            type="number"
            min="0"
            step="0.01"
            placeholder={remaining.toFixed(2)}
            value={amountStr}
            onChange={(e) => setAmountStr(e.target.value)}
            className="text-right tabular-nums"
          />
          {/* Quick amounts for cash */}
          {method === 'cash' && (
            <div className="mt-2 flex gap-2">
              {QUICK_AMOUNTS.map((amt) => (
                <button
                  key={amt}
                  onClick={() => setAmountStr(amt.toString())}
                  className="flex-1 rounded border py-1 text-xs hover:bg-accent"
                >
                  {amt}
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Add tender (for split payments) */}
        {(tenders.length > 0 || remaining > 0) && tendered < total && (
          <Button variant="outline" className="w-full" onClick={addTender}>
            + Add Tender
          </Button>
        )}

        {/* Tenders list */}
        {tenders.length > 0 && (
          <div className="space-y-1">
            <Label className="text-xs">Tenders</Label>
            {tenders.map((t, i) => (
              <div key={i} className="flex items-center justify-between rounded bg-muted px-3 py-1.5 text-sm">
                <span className="capitalize">{t.method.replace('_', ' ')}</span>
                <div className="flex items-center gap-2">
                  <span className="tabular-nums">{currency} {t.amount}</span>
                  <button
                    onClick={() => setTenders((prev) => prev.filter((_, j) => j !== i))}
                    className="text-muted-foreground hover:text-destructive"
                  >
                    <X className="size-3" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <Separator />

      {/* Confirm button */}
      <div className="px-4 py-3 shrink-0">
        <Button
          className="w-full gap-2"
          size="lg"
          disabled={!isSufficient || processSale.isPending}
          onClick={handlePay}
        >
          {processSale.isPending ? (
            'Processing...'
          ) : (
            <>
              <Check className="size-4" />
              Confirm Payment
            </>
          )}
        </Button>
      </div>
    </div>
  );
}
