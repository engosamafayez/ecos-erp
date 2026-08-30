import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useToast } from '@/components/ds/use-toast';
import { useRecordOrderPayment } from '@/features/orders/hooks/use-orders';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  orderId: string;
  total: number;
  /** Amount already paid (deposit_amount) — used to prefill the outstanding balance. */
  paid: number;
};

/**
 * Record a payment against an order — TASK-ORDERS-PAYMENT-TIME-SHIPPING-REMAINING-001 (A4).
 *
 * This is the ONLY UI path that moves money, and it routes through the canonical
 * RecordOrderPaymentAction (POST /orders/{id}/record-payment), which owns the overpayment
 * guard, idempotency and the payment_recorded audit event. It never writes deposit_amount
 * directly and never sets Order.status — payment state is derived server-side (a deposit is
 * PARTIALLY_PAID, only full payment reaches PAID).
 */
export function RecordPaymentDialog({ open, onOpenChange, orderId, total, paid }: Props) {
  const { t } = useTranslation('orders');
  const { toast } = useToast();
  const record = useRecordOrderPayment();

  const outstanding = Math.max(0, Number((total - paid).toFixed(2)));
  const [amount, setAmount] = useState<string>(outstanding > 0 ? String(outstanding) : '');

  // Re-prime the field to the current outstanding balance each time the dialog opens.
  const [primedFor, setPrimedFor] = useState<string | null>(null);
  if (open && primedFor !== orderId) {
    setPrimedFor(orderId);
    setAmount(outstanding > 0 ? String(outstanding) : '');
  }
  if (!open && primedFor !== null) setPrimedFor(null);

  const value = Number(amount);
  const invalid = !Number.isFinite(value) || value <= 0 || value > outstanding;

  const submit = () => {
    if (invalid) return;
    record.mutate(
      { id: orderId, amount: value },
      {
        onSuccess: () => { onOpenChange(false); toast({ title: t(($) => $.orderDetail.paymentRecorded) }); },
        onError: () => toast({ title: t(($) => $.orderDetail.paymentRecordFailed), variant: 'destructive' }),
      },
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t(($) => $.orderDetail.recordPaymentTitle)}</DialogTitle>
          <DialogDescription>{t(($) => $.orderDetail.recordPaymentDesc)}</DialogDescription>
        </DialogHeader>

        <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 py-2 text-sm">
          <span className="text-muted-foreground">{t(($) => $.orderDetail.total)}</span>
          <span className="tabular-nums text-end">{total.toLocaleString()}</span>
          <span className="text-muted-foreground">{t(($) => $.orderDetail.paidAmount)}</span>
          <span className="tabular-nums text-end">{paid.toLocaleString()}</span>
          <span className="text-muted-foreground">{t(($) => $.orderDetail.outstanding)}</span>
          <span className="tabular-nums text-end font-semibold">{outstanding.toLocaleString()}</span>
        </div>

        <div className="flex flex-col gap-1.5">
          <label className="text-xs font-medium text-muted-foreground">{t(($) => $.orderDetail.paymentAmount)}</label>
          <Input
            type="number"
            min={0}
            max={outstanding}
            step="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
          />
          {value > outstanding && (
            <p className="text-xs text-destructive">{t(($) => $.orderDetail.paymentExceeds)}</p>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>{t(($) => $.orderDetail.cancel)}</Button>
          <Button disabled={invalid || record.isPending} onClick={submit}>
            {t(($) => $.orderDetail.recordPaymentConfirm)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
