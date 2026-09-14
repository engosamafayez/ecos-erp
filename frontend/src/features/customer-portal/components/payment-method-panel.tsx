import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  useChangePaymentMethodMutation,
  useTrackPaymentMethodOptionsQuery,
} from '@/features/customer-portal/hooks/use-customer-portal';

type MethodKey = 'cod' | 'instapay' | 'bank_transfer' | 'mobile_wallet' | 'credit_card';

/**
 * §11 — the eligible-methods list is rendered EXACTLY as returned by
 * GET /track/order/payment-method (PaymentFulfillmentGate::proofPolicyFor()'s real configured
 * policy) — never a hardcoded superset, never a payment-link/Paymob/wallet option that backend
 * did not itself return (§20).
 */
export function PaymentMethodPanel({ onChanged }: { onChanged: () => void }) {
  const { t } = useTranslation('customer-portal');
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState('');
  const [message, setMessage] = useState<{ kind: 'success' | 'error'; text: string } | null>(null);
  const optionsQuery = useTrackPaymentMethodOptionsQuery(open);
  const changeMutation = useChangePaymentMethodMutation();

  const methodLabel = (method: string): string => {
    const known: MethodKey[] = ['cod', 'instapay', 'bank_transfer', 'mobile_wallet', 'credit_card'];
    return (known as string[]).includes(method)
      ? t(($) => $.payment.methodLabels[method as MethodKey])
      : method;
  };

  if (!open) {
    return (
      <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
        {t(($) => $.payment.changeMethod)}
      </Button>
    );
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selected) return;
    setMessage(null);
    changeMutation.mutate(selected, {
      onSuccess: () => {
        setMessage({ kind: 'success', text: t(($) => $.payment.changeSuccess) });
        onChanged();
      },
      onError: (error: unknown) => {
        if (axios.isAxiosError(error) && error.response?.status === 422) {
          setMessage({ kind: 'error', text: t(($) => $.payment.changeRejected) });
          return;
        }
        setMessage({ kind: 'error', text: t(($) => $.errors.generic) });
      },
    });
  };

  return (
    <form
      onSubmit={handleSubmit}
      className="border-border flex flex-col gap-3 rounded-md border p-4"
    >
      <p className="text-sm font-medium">{t(($) => $.payment.changeMethodHeading)}</p>
      {optionsQuery.isLoading ? (
        <p className="text-muted-foreground text-sm">{t(($) => $.order.loading)}</p>
      ) : (
        <select
          aria-label={t(($) => $.payment.selectMethod)}
          className="border-input bg-background h-9 rounded-md border px-3 text-sm"
          value={selected}
          onChange={(e) => setSelected(e.target.value)}
        >
          <option value="">{t(($) => $.payment.selectMethod)}</option>
          {(optionsQuery.data?.methods ?? []).map((method) => (
            <option key={method} value={method}>
              {methodLabel(method)}
            </option>
          ))}
        </select>
      )}

      {message ? (
        <p
          role={message.kind === 'error' ? 'alert' : 'status'}
          className={
            message.kind === 'error'
              ? 'text-destructive text-sm'
              : 'text-sm text-emerald-600 dark:text-emerald-400'
          }
        >
          {message.text}
        </p>
      ) : null}

      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={!selected || changeMutation.isPending}>
          {changeMutation.isPending
            ? t(($) => $.payment.changing)
            : t(($) => $.payment.confirmChange)}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={() => setOpen(false)}>
          {t(($) => $.common.cancel)}
        </Button>
      </div>
    </form>
  );
}
