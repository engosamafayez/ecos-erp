import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { EntityDrawer, EntityForm } from '@/components/crud';
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
  /** Called when a duplicate phone is found or after a new customer is created — open that customer. */
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
 * After save: stays open in edit mode (shows success message). Create mode opens the new customer.
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
  const [saveSuccess, setSaveSuccess]     = useState(false);

  const phoneRef = useRef<HTMLInputElement>(null);
  const stepRef  = useRef(step);
  useEffect(() => { stepRef.current = step; }, [step]);

  // ── Mutations ──────────────────────────────────────────────────────────────
  const createCustomer = useCreateCustomer();
  const updateCustomer = useUpdateCustomer();

  // ── Duplicate check query ──────────────────────────────────────────────────
  const checkQuery = useCustomersQuery(
    { search: phoneInput, per_page: 5 },
  );

  // ── Sync state when opened ─────────────────────────────────────────────────
  useEffect(() => {
    if (open) {
      setServerError(null);
      setFoundCustomer(null);
      setSaveSuccess(false);
      if (isEdit) {
        setStep('form');
        form.reset(toFormValues(customer));
      } else {
        setStep('phone');
        setPhoneInput(initialPhone);
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

  // ── Ctrl+S — submit form when drawer is open ───────────────────────────────
  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 's' && (e.ctrlKey || e.metaKey) && stepRef.current === 'form') {
        e.preventDefault();
        document.getElementById(FORM_ID)?.dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }),
        );
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

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
      setSaveSuccess(false);
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

      const match = items.find(
        (c) => c.phone === trimmed || c.mobile === trimmed,
      );

      if (match) {
        setFoundCustomer(match);
      } else {
        form.reset({ ...toFormValues(null), phone: trimmed });
        setStep('form');
        setFoundCustomer(null);
      }
    } catch {
      form.reset({ ...toFormValues(null), phone: phoneInput.trim() });
      setStep('form');
    } finally {
      setIsChecking(false);
    }
  };

  // ── Step 2 — Submit form ───────────────────────────────────────────────────
  const handleSubmit = (values: CustomerFormValues) => {
    setServerError(null);
    setSaveSuccess(false);
    const payload = toPayload(values);

    if (isEdit && customer) {
      updateCustomer.mutate({ id: customer.id, payload }, {
        onSuccess: () => {
          // Remain inside drawer — show success alert, user closes manually.
          setSaveSuccess(true);
        },
        onError: (error) => setServerError(extractMessage(error)),
      });
    } else {
      createCustomer.mutate(payload, {
        onSuccess: (newCustomer) => {
          // Open the new customer's profile and close the form drawer.
          if (onFoundExisting) onFoundExisting(newCustomer);
          handleOpenChange(false);
        },
        onError: (error) => setServerError(extractMessage(error)),
      });
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
          onClick={() => { setStep('phone'); setFoundCustomer(null); setSaveSuccess(false); }}
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
          {saveSuccess ? (
            <Alert className="mb-4 border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30">
              <AlertTitle className="text-emerald-800 dark:text-emerald-300">
                {t('drawer.savedMessage')}
              </AlertTitle>
            </Alert>
          ) : null}

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
