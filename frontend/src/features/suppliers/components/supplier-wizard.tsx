import { useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { Check, ChevronRight } from 'lucide-react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ds/use-toast';
import {
  supplierSchema,
  toFormValues,
  toPayload,
  type SupplierFormValues,
} from '@/features/suppliers/components/supplier-form-schema';
import { SupplierCategoriesMultiSelect } from '@/features/suppliers/components/supplier-categories-multi-select';
import { useCreateSupplier } from '@/features/suppliers/hooks/use-suppliers';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: () => void;
};

// Reduced from 3 steps to 2 (TASK-...-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §A.4) — the
// former "Basic Info" and "Contact" steps are now one coherent "Supplier Information"
// step; "Review" is unchanged. Supply Capabilities (Raw Materials / Product Categories)
// is deliberately NOT part of Create anymore (§A.2) — it stays fully available from the
// Edit Supplier form and from Supplier 360 → Products → Manage Offerings, unchanged.
type Step = 1 | 2;

function StepIndicator({ current }: { current: Step }) {
  const { t } = useTranslation('suppliers');
  const steps: { id: Step; label: string }[] = [
    { id: 1, label: t($ => $.wizard.steps.info) },
    { id: 2, label: t($ => $.wizard.steps.review) },
  ];

  return (
    <div className="flex items-center gap-2 mb-6">
      {steps.map((step, i) => {
        const done = current > step.id;
        const active = current === step.id;
        return (
          <div key={step.id} className="flex items-center gap-2">
            <div
              className={`flex size-6 items-center justify-center rounded-full border text-xs font-medium transition-colors ${
                done
                  ? 'bg-primary border-primary text-primary-foreground'
                  : active
                    ? 'border-primary text-primary'
                    : 'border-muted-foreground/30 text-muted-foreground'
              }`}
            >
              {done ? <Check className="size-3" /> : step.id}
            </div>
            <span className={`text-xs ${active ? 'font-medium text-foreground' : 'text-muted-foreground'}`}>
              {step.label}
            </span>
            {i < steps.length - 1 && (
              <ChevronRight className="size-3.5 text-muted-foreground/40 mx-0.5" />
            )}
          </div>
        );
      })}
    </div>
  );
}

function Field({
  label,
  required,
  children,
  error,
}: {
  label: string;
  required?: boolean;
  children: React.ReactNode;
  error?: string;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label className="text-xs">
        {label}
        {required && <span className="text-destructive ml-1">*</span>}
      </Label>
      {children}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  );
}

export function SupplierWizard({ open, onOpenChange, onCreated }: Props) {
  const { t } = useTranslation('suppliers');
  const [step, setStep] = useState<Step>(1);
  const [serverError, setServerError] = useState<string | null>(null);
  const createSupplier = useCreateSupplier();

  const form = useForm<SupplierFormValues>({
    resolver: zodResolver(supplierSchema),
    defaultValues: toFormValues(),
    mode: 'onTouched',
  });

  const { register, formState: { errors }, trigger, getValues, reset, watch, setValue } = form;

  function extractMessage(error: unknown): string {
    return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
      ? error.response.data.message
      : t($ => $.wizard.toast.error);
  }

  function handleClose(next: boolean) {
    if (!next) {
      reset(toFormValues());
      setStep(1);
      setServerError(null);
    }
    onOpenChange(next);
  }

  async function goNext() {
    const step1Fields: (keyof SupplierFormValues)[] = [
      'name', 'is_active', 'contact_person', 'phone', 'email', 'mobile',
      'country', 'state', 'city', 'district', 'address', 'google_maps_url',
    ];

    const valid = await trigger(step1Fields);
    if (valid) setStep(2);
  }

  function goPrev() {
    setStep(1);
  }

  async function handleSubmit() {
    const values = getValues();
    setServerError(null);
    createSupplier.mutate(toPayload(values), {
      onSuccess: () => {
        toast.success(t($ => $.wizard.toast.success));
        handleClose(false);
        onCreated?.();
      },
      onError: (err) => setServerError(extractMessage(err)),
    });
  }

  const vals = getValues();
  const categoryIds = watch('supplier_category_ids');

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-w-lg max-h-[90vh] flex flex-col">
        <DialogHeader>
          <DialogTitle>{t($ => $.wizard.title)}</DialogTitle>
          <DialogDescription>{t($ => $.wizard.subtitle)}</DialogDescription>
        </DialogHeader>

        <StepIndicator current={step} />

        {serverError && (
          <Alert variant="destructive">
            <AlertDescription>{serverError}</AlertDescription>
          </Alert>
        )}

        <div className="flex-1 overflow-y-auto px-1 py-1">
        {/* Step 1 — Supplier Information (Basic Info + Contact merged, §A.4) */}
        {step === 1 && (
          <div className="flex flex-col gap-4">
            <Field label={t($ => $.wizard.fields.name)} required error={errors.name?.message}>
              <Input {...register('name')} placeholder={t($ => $.wizard.fields.namePlaceholder)} />
            </Field>
            <div className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
              {t($ => $.wizard.fields.codeAutoHint)}
            </div>
            <Field label={t($ => $.wizard.fields.categories)} error={undefined}>
              <SupplierCategoriesMultiSelect
                value={categoryIds}
                onChange={(ids) => setValue('supplier_category_ids', ids)}
              />
            </Field>
            <Field label={t($ => $.wizard.fields.status)} error={undefined}>
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_active"
                  {...register('is_active')}
                  className="size-4 rounded border-input accent-primary"
                />
                <label htmlFor="is_active" className="text-sm">{t($ => $.wizard.fields.activeSupplier)}</label>
              </div>
            </Field>

            <div className="border-border/60 border-t pt-4">
              <h4 className="text-muted-foreground mb-3 text-xs font-semibold uppercase tracking-wide">
                {t($ => $.form.sectionContact)}
              </h4>
              <div className="flex flex-col gap-4">
                <Field label={t($ => $.wizard.fields.contactPerson)} error={errors.contact_person?.message}>
                  <Input {...register('contact_person')} placeholder={t($ => $.wizard.fields.contactPersonPlaceholder)} />
                </Field>
                <div className="grid grid-cols-2 gap-3">
                  <Field label={t($ => $.wizard.fields.phone)} error={errors.phone?.message}>
                    <Input {...register('phone')} placeholder={t($ => $.wizard.fields.phonePlaceholder)} />
                  </Field>
                  <Field label={t($ => $.wizard.fields.mobile)} error={errors.mobile?.message}>
                    <Input {...register('mobile')} placeholder={t($ => $.wizard.fields.mobilePlaceholder)} />
                  </Field>
                </div>
                <Field label={t($ => $.wizard.fields.email)} error={errors.email?.message}>
                  <Input {...register('email')} type="email" placeholder="supplier@example.com" />
                </Field>
              </div>
            </div>

            <div className="border-border/60 border-t pt-4">
              <h4 className="text-muted-foreground mb-3 text-xs font-semibold uppercase tracking-wide">
                {t($ => $.form.sectionLocation)}
              </h4>
              <div className="flex flex-col gap-3">
                <div className="grid grid-cols-2 gap-3">
                  <Field label={t($ => $.form.country)} error={errors.country?.message}>
                    <Input {...register('country')} placeholder={t($ => $.wizard.fields.countryPlaceholder)} />
                  </Field>
                  <Field label={t($ => $.form.state)} error={errors.state?.message}>
                    <Input {...register('state')} placeholder={t($ => $.wizard.fields.statePlaceholder)} />
                  </Field>
                  <Field label={t($ => $.form.city)} error={errors.city?.message}>
                    <Input {...register('city')} placeholder={t($ => $.wizard.fields.cityPlaceholder)} />
                  </Field>
                  <Field label={t($ => $.form.district)} error={errors.district?.message}>
                    <Input {...register('district')} placeholder={t($ => $.wizard.fields.districtPlaceholder)} />
                  </Field>
                </div>
                <Field label={t($ => $.form.address)} error={errors.address?.message}>
                  <Input {...register('address')} placeholder={t($ => $.wizard.fields.addressPlaceholder)} />
                </Field>
                <Field label={t($ => $.form.googleMapsUrl)} error={errors.google_maps_url?.message}>
                  <Input type="url" {...register('google_maps_url')} placeholder="https://maps.google.com/…" />
                </Field>
              </div>
            </div>

            {/* Opening balance — REALIGNMENT-001 §7. Not captured during supplier creation:
                it is a Finance posting (see supplier-form.tsx for the full rationale) and is
                entered from Supplier 360 so it reaches the certified supplier ledger. */}
            <div className="border-border/60 border-t pt-4">
              <p className="text-muted-foreground text-xs">{t($ => $.form.openingBalanceMovedHint)}</p>
            </div>
          </div>
        )}

        {/* Step 2 — Review & Save */}
        {step === 2 && (
          <div className="flex flex-col gap-4">
            <div className="rounded-lg border bg-muted/30 p-4 text-sm space-y-2">
              <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                <div><span className="text-muted-foreground">{t($ => $.wizard.review.name)}</span><p className="font-medium mt-0.5">{vals.name || '—'}</p></div>
                <div><span className="text-muted-foreground">{t($ => $.wizard.review.code)}</span><p className="font-mono mt-0.5 text-muted-foreground">{t($ => $.wizard.fields.codeAutoShort)}</p></div>
                <div><span className="text-muted-foreground">{t($ => $.wizard.review.contact)}</span><p className="mt-0.5">{vals.contact_person || '—'}</p></div>
                <div><span className="text-muted-foreground">{t($ => $.wizard.review.phone)}</span><p className="mt-0.5">{vals.phone || '—'}</p></div>
                <div><span className="text-muted-foreground">{t($ => $.wizard.review.email)}</span><p className="mt-0.5">{vals.email || '—'}</p></div>
                <div><span className="text-muted-foreground">{t($ => $.wizard.review.country)}</span><p className="mt-0.5">{vals.country || '—'}</p></div>
                <div><span className="text-muted-foreground">{t($ => $.wizard.review.city)}</span><p className="mt-0.5">{vals.city || '—'}</p></div>
                <div><span className="text-muted-foreground">{t($ => $.wizard.review.status)}</span><p className="mt-0.5">{vals.is_active ? t($ => $.wizard.review.active) : t($ => $.wizard.review.inactive)}</p></div>
              </div>
            </div>

            <Field label={t($ => $.wizard.fields.notes)} error={errors.notes?.message}>
              <Textarea
                {...register('notes')}
                placeholder={t($ => $.wizard.fields.notesPlaceholder)}
                rows={3}
              />
            </Field>

            <div className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">
              {t($ => $.wizard.documentsNote)}
            </div>
          </div>
        )}
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          {step > 1 && (
            <Button variant="outline" onClick={goPrev} type="button">
              {t($ => $.wizard.buttons.back)}
            </Button>
          )}
          <Button variant="ghost" onClick={() => handleClose(false)} type="button" className="mr-auto sm:mr-0">
            {t($ => $.wizard.buttons.cancel)}
          </Button>
          {step < 2 ? (
            <Button onClick={goNext} type="button">
              {t($ => $.wizard.buttons.next)}
            </Button>
          ) : (
            <Button
              onClick={handleSubmit}
              type="button"
              disabled={createSupplier.isPending}
            >
              {createSupplier.isPending ? t($ => $.wizard.buttons.creating) : t($ => $.wizard.buttons.create)}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
