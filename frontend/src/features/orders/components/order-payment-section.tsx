import { Controller, useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { FormField } from '@/components/crud';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { ManualOrderFormValues } from '@/features/orders/components/order-form-schema';

const PAYMENT_METHOD_VALUES = ['cod', 'instapay', 'mobile_wallet', 'bank_transfer', 'credit_card'] as const;

type OrderPaymentSectionProps = {
  paymentMethods?: ReadonlyArray<{ value: string; label: string }>;
};

/**
 * Payment METHOD selection only. Proof evidence is a separate concern owned entirely by
 * the canonical `payment_proofs` lifecycle (PaymentProofSection / POST /orders/{order}/
 * payment-proofs) — TASK-...-PAYMENT-PROOF-AND-PAYMENT-BASIS-003. This component used to
 * also render a proof upload control that posted to the generic /media/upload endpoint and
 * staged the result into the legacy `payment_proof_path` form field: on create that field
 * reached the order row but PaymentFulfillmentGate never reads it for eligibility, and on
 * edit UpdateOrderRequest has no rule for it at all, so Laravel silently dropped it before
 * save. Either way the operator saw an "uploaded" proof that carried no actual authority —
 * removed rather than fixed, since the upload can only become real evidence once the order
 * (and thus a payment_proofs parent row) exists.
 */
export function OrderPaymentSection({ paymentMethods }: OrderPaymentSectionProps = {}) {
  const { t } = useTranslation('orders');
  const defaultMethods = PAYMENT_METHOD_VALUES.map((v) => ({
    value: v,
    label: t($ => $.workspace.paymentMethodLabels[v], { defaultValue: v }),
  }));
  const methods = paymentMethods && paymentMethods.length > 0 ? paymentMethods : defaultMethods;
  const { control } = useFormContext<ManualOrderFormValues>();

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2">
        <FormField name="payment_method_manual" label={t($ => $.workspace.paymentSection.methodLabel)}>
          <Controller
            control={control}
            name="payment_method_manual"
            render={({ field }) => (
              <Select
                value={field.value ?? ''}
                onValueChange={(v) => field.onChange(v || undefined)}
              >
                <SelectTrigger>
                  <SelectValue placeholder={t($ => $.workspace.paymentSection.selectMethod)} />
                </SelectTrigger>
                <SelectContent>
                  {methods.map((m) => (
                    <SelectItem key={m.value} value={m.value}>
                      {m.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
        </FormField>
      </div>
    </div>
  );
}
