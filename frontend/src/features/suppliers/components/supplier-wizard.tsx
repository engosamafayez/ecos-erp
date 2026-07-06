import { useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
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
import { useCreateSupplier } from '@/features/suppliers/hooks/use-suppliers';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: () => void;
};

type Step = 1 | 2 | 3;

const STEPS: { id: Step; label: string }[] = [
  { id: 1, label: 'Basic Info' },
  { id: 2, label: 'Contact' },
  { id: 3, label: 'Review' },
];

function StepIndicator({ current }: { current: Step }) {
  return (
    <div className="flex items-center gap-2 mb-6">
      {STEPS.map((step, i) => {
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
            {i < STEPS.length - 1 && (
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

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Failed to create supplier. Please try again.';
}

export function SupplierWizard({ open, onOpenChange, onCreated }: Props) {
  const [step, setStep] = useState<Step>(1);
  const [serverError, setServerError] = useState<string | null>(null);
  const createSupplier = useCreateSupplier();

  const form = useForm<SupplierFormValues>({
    resolver: zodResolver(supplierSchema),
    defaultValues: toFormValues(),
    mode: 'onTouched',
  });

  const { register, formState: { errors }, trigger, getValues, reset } = form;

  function handleClose(next: boolean) {
    if (!next) {
      reset(toFormValues());
      setStep(1);
      setServerError(null);
    }
    onOpenChange(next);
  }

  async function goNext() {
    const step1Fields: (keyof SupplierFormValues)[] = ['code', 'name', 'is_active'];
    const step2Fields: (keyof SupplierFormValues)[] = ['contact_person', 'phone', 'email', 'mobile', 'country', 'city', 'address'];

    const valid = await trigger(step === 1 ? step1Fields : step2Fields);
    if (valid) setStep((s) => Math.min(s + 1, 3) as Step);
  }

  function goPrev() {
    setStep((s) => Math.max(s - 1, 1) as Step);
  }

  async function handleSubmit() {
    const values = getValues();
    setServerError(null);
    createSupplier.mutate(toPayload(values), {
      onSuccess: () => {
        toast.success('Supplier created successfully.');
        handleClose(false);
        onCreated?.();
      },
      onError: (err) => setServerError(extractMessage(err)),
    });
  }

  const vals = getValues();

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>New Supplier</DialogTitle>
          <DialogDescription>Add a new supplier to your procurement network.</DialogDescription>
        </DialogHeader>

        <StepIndicator current={step} />

        {serverError && (
          <Alert variant="destructive">
            <AlertDescription>{serverError}</AlertDescription>
          </Alert>
        )}

        {/* Step 1 — Basic Information */}
        {step === 1 && (
          <div className="flex flex-col gap-4">
            <Field label="Supplier Name" required error={errors.name?.message}>
              <Input {...register('name')} placeholder="e.g. Al Futtaim Trading" />
            </Field>
            <Field label="Supplier Code" required error={errors.code?.message}>
              <Input {...register('code')} placeholder="e.g. SUP-001" className="font-mono" />
            </Field>
            <Field label="Category" error={undefined}>
              <Input
                disabled
                placeholder="Coming soon — category management in PKG-PROC-006"
                className="text-muted-foreground"
              />
            </Field>
            <Field label="Status" error={undefined}>
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_active"
                  {...register('is_active')}
                  className="size-4 rounded border-input accent-primary"
                />
                <label htmlFor="is_active" className="text-sm">Active supplier</label>
              </div>
            </Field>
          </div>
        )}

        {/* Step 2 — Contact Information */}
        {step === 2 && (
          <div className="flex flex-col gap-4">
            <Field label="Contact Person" error={errors.contact_person?.message}>
              <Input {...register('contact_person')} placeholder="Full name" />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Phone" error={errors.phone?.message}>
                <Input {...register('phone')} placeholder="+971 X XXX XXXX" />
              </Field>
              <Field label="Mobile" error={errors.mobile?.message}>
                <Input {...register('mobile')} placeholder="+971 5X XXX XXXX" />
              </Field>
            </div>
            <Field label="Email" error={errors.email?.message}>
              <Input {...register('email')} type="email" placeholder="supplier@example.com" />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Country" error={errors.country?.message}>
                <Input {...register('country')} placeholder="e.g. UAE" />
              </Field>
              <Field label="City" error={errors.city?.message}>
                <Input {...register('city')} placeholder="e.g. Dubai" />
              </Field>
            </div>
            <Field label="Address" error={errors.address?.message}>
              <Input {...register('address')} placeholder="Street address" />
            </Field>
          </div>
        )}

        {/* Step 3 — Review & Save */}
        {step === 3 && (
          <div className="flex flex-col gap-4">
            <div className="rounded-lg border bg-muted/30 p-4 text-sm space-y-2">
              <div className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
                <div><span className="text-muted-foreground">Name</span><p className="font-medium mt-0.5">{vals.name || '—'}</p></div>
                <div><span className="text-muted-foreground">Code</span><p className="font-mono mt-0.5">{vals.code || '—'}</p></div>
                <div><span className="text-muted-foreground">Contact</span><p className="mt-0.5">{vals.contact_person || '—'}</p></div>
                <div><span className="text-muted-foreground">Phone</span><p className="mt-0.5">{vals.phone || '—'}</p></div>
                <div><span className="text-muted-foreground">Email</span><p className="mt-0.5">{vals.email || '—'}</p></div>
                <div><span className="text-muted-foreground">Country</span><p className="mt-0.5">{vals.country || '—'}</p></div>
                <div><span className="text-muted-foreground">City</span><p className="mt-0.5">{vals.city || '—'}</p></div>
                <div><span className="text-muted-foreground">Status</span><p className="mt-0.5">{vals.is_active ? 'Active' : 'Inactive'}</p></div>
              </div>
            </div>

            <Field label="Notes (optional)" error={errors.notes?.message}>
              <Textarea
                {...register('notes')}
                placeholder="Any notes about this supplier…"
                rows={3}
              />
            </Field>

            <div className="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground">
              Document attachments — available in PKG-PROC-004
            </div>
          </div>
        )}

        <DialogFooter className="gap-2 sm:gap-0">
          {step > 1 && (
            <Button variant="outline" onClick={goPrev} type="button">
              Back
            </Button>
          )}
          <Button variant="ghost" onClick={() => handleClose(false)} type="button" className="mr-auto sm:mr-0">
            Cancel
          </Button>
          {step < 3 ? (
            <Button onClick={goNext} type="button">
              Next
            </Button>
          ) : (
            <Button
              onClick={handleSubmit}
              type="button"
              disabled={createSupplier.isPending}
            >
              {createSupplier.isPending ? 'Creating…' : 'Create Supplier'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
