import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { EntityDrawer, EntityForm, FormField } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { CustomerFormFields } from '@/features/customers/components/customer-form';
import {
  customerSchema,
  toFormValues,
  toPayload,
  type CustomerFormValues,
} from '@/features/customers/components/customer-form-schema';
import { useCreateCustomer, useCustomersQuery, useUpdateCustomer } from '@/features/customers/hooks/use-customers';
import type { Customer } from '@/features/customers/types/customer';

const FORM_ID = 'customer-form';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  customer?: Customer | null;
  /** Phone pre-filled when opened from Quick Search / no-results CTA (DD-060). */
  initialPhone?: string;
  /** Called when a duplicate phone is found — open that customer instead. */
  onFoundExisting?: (customer: Customer) => void;
};

type Step = 'phone' | 'form';

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

/**
 * DD-060 — Phone Before Customer.
 * Create mode: 2-step flow (phone validation → form with phone prefilled).
 * Edit mode: skips step 1, opens form directly.
 */
export function CustomerFormDrawer({
  open,
  onOpenChange,
  customer,
  initialPhone = '',
  onFoundExisting,
}: Props) {
  const { t } = useTranslation('customers');
  const { t: tCommon } = useTranslation('common');
  const isEdit = Boolean(customer);

  // ── Step state (only for create mode) ─────────────────────────────────────
  const [step, setStep]             = useState<Step>(isEdit ? 'form' : 'phone');
  const [phoneInput, setPhoneInput] = useState('');
  const [isChecking, setIsChecking] = useState(false);
  const [foundCustomer, setFoundCustomer] = useState<Customer | null>(null);
  const [serverError, setServerError]     = useState<string | null>(null);

  const phoneRef = useRef<HTMLInputElement>(null);

  // ── Mutations ──────────────────────────────────────────────────────────────
  const createCustomer = useCreateCustomer();
  const updateCustomer = useUpdateCustomer();

  // ── Duplicate check query (only runs when checking) ────────────────────────
  const checkQuery = useCustomersQuery(
    { search: phoneInput, per_page: 5 },
  );

  // ── Sync state when opened ─────────────────────────────────────────────────
  useEffect(() => {
    if (open) {
      setServerError(null);
      setFoundCustomer(null);
      if (isEdit) {
        setStep('form');
        form.reset(toFormValues(customer));
      } else {
        setStep('phone');
        setPhoneInput(initialPhone);
        // If initialPhone provided, skip to form directly (DD-060: "never type twice")
        if (initialPhone) {
          setStep('form');
          form.reset({ ...toFormValues(null), phone: initialPhone });
        }
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, customer, initialPhone, isEdit]);

  // ── Auto-focus phone field on step 1 ──────────────────────────────────────
  useEffect(() => {
    if (open && step === 'phone') {
      setTimeout(() => phoneRef.current?.focus(), 50);
    }
  }, [open, step]);

  // ── Form ───────────────────────────────────────────────────────────────────
  const form = useForm<CustomerFormValues>({
    resolver: zodResolver(customerSchema),
    defaultValues: toFormValues(customer),
  });

  const isPending = createCustomer.isPending || updateCustomer.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setServerError(null);
      setFoundCustomer(null);
      setPhoneInput('');
      setStep(isEdit ? 'form' : 'phone');
    }
    onOpenChange(next);
  };

  // ── DD-060: Step 1 — Validate phone ───────────────────────────────────────
  const handlePhoneContinue = async () => {
    const trimmed = phoneInput.trim();
    if (!trimmed) return;

    setIsChecking(true);
    try {
      const result = await checkQuery.refetch();
      const items = result.data?.items ?? [];

      // Check if any customer has this exact phone (primary or mobile)
      const match = items.find(
        (c) => c.phone === trimmed || c.mobile === trimmed,
      );

      if (match) {
        setFoundCustomer(match);
      } else {
        // No duplicate — proceed to form with phone prefilled
        form.reset({ ...toFormValues(null), phone: trimmed });
        setStep('form');
        setFoundCustomer(null);
      }
    } catch {
      // If lookup fails, proceed to form anyway
      form.reset({ ...toFormValues(null), phone: trimmed });
      setStep('form');
    } finally {
      setIsChecking(false);
    }
  };

  // ── Step 2 — Submit form ───────────────────────────────────────────────────
  const handleSubmit = (values: CustomerFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    const handlers = {
      onSuccess: () => handleOpenChange(false),
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (isEdit && customer) {
      updateCustomer.mutate({ id: customer.id, payload }, handlers);
    } else {
      createCustomer.mutate(payload, handlers);
    }
  };

  // ── Title ──────────────────────────────────────────────────────────────────
  const title       = isEdit ? t('drawer.editTitle')   : t('drawer.createTitle');
  const description = isEdit ? t('drawer.editSubtitle') : t('drawer.createSubtitle');

  // ── Phone step footer ──────────────────────────────────────────────────────
  const phoneFooter = (
    <>
      <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
        {tCommon('common.cancel')}
      </Button>
      <Button
        type="button"
        onClick={() => void handlePhoneContinue()}
        disabled={!phoneInput.trim() || isChecking}
      >
        {isChecking ? t('drawer.phoneStep.checking') : t('drawer.phoneStep.continue')}
      </Button>
    </>
  );

  // ── Form step footer ───────────────────────────────────────────────────────
  const formFooter = (
    <>
      {!isEdit ? (
        <Button
          type="button"
          variant="ghost"
          className="mr-auto text-xs"
          onClick={() => { setStep('phone'); setFoundCustomer(null); }}
        >
          ← {t('drawer.phoneStep.label')}
        </Button>
      ) : null}
      <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
        {tCommon('common.cancel')}
      </Button>
      <Button type="submit" form={FORM_ID} disabled={isPending}>
        {isPending
          ? t('drawer.saving')
          : isEdit
            ? t('drawer.submitEdit')
            : t('drawer.submitCreate')}
      </Button>
    </>
  );

  return (
    <EntityDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={title}
      description={description}
      footer={step === 'phone' ? phoneFooter : formFooter}
    >
      {/* ── Step 1: Phone validation (DD-060) ──────────────────────────── */}
      {step === 'phone' ? (
        <div className="flex flex-col gap-5">
          <div className="flex flex-col gap-1.5">
            <label className="text-sm font-medium" htmlFor="phone-step-input">
              {t('drawer.phoneStep.label')}
            </label>
            <Input
              id="phone-step-input"
              ref={phoneRef}
              value={phoneInput}
              onChange={(e) => {
                setPhoneInput(e.target.value);
                setFoundCustomer(null);
              }}
              placeholder={t('drawer.phoneStep.placeholder')}
              onKeyDown={(e) => {
                if (e.key === 'Enter') void handlePhoneContinue();
              }}
              className="font-mono"
            />
            <p className="text-xs text-muted-foreground">
              {t('drawer.phoneStep.description')}
            </p>
          </div>

          {/* Duplicate found */}
          {foundCustomer ? (
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
              <p className="text-sm font-medium text-amber-800 dark:text-amber-300">
                {t('drawer.foundCustomer.title')}
              </p>
              <p className="mt-0.5 text-xs text-amber-700 dark:text-amber-400">
                {t('drawer.foundCustomer.description')}
              </p>
              <div className="mt-3 flex items-center gap-2">
                <Button
                  size="sm"
                  onClick={() => {
                    if (onFoundExisting) onFoundExisting(foundCustomer);
                    handleOpenChange(false);
                  }}
                >
                  {t('drawer.foundCustomer.open')}
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => setFoundCustomer(null)}
                >
                  {t('drawer.foundCustomer.cancel')}
                </Button>
              </div>
            </div>
          ) : null}
        </div>
      ) : null}

      {/* ── Step 2: Customer form ───────────────────────────────────────── */}
      {step === 'form' ? (
        <>
          {serverError ? (
            <Alert variant="destructive" className="mb-4">
              <AlertTitle>{t('drawer.errorTitle')}</AlertTitle>
              <AlertDescription>{serverError}</AlertDescription>
            </Alert>
          ) : null}

          <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit}>
            <CustomerFormFields />
          </EntityForm>
        </>
      ) : null}
    </EntityDrawer>
  );
}
