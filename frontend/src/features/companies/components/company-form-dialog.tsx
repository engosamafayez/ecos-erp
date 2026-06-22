import { useState } from 'react';
import axios from 'axios';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { CompanyForm, type CompanyFormValues } from '@/features/companies/components/company-form';
import { useCreateCompany, useUpdateCompany } from '@/features/companies/hooks/use-companies';
import type { Company, CompanyPayload } from '@/features/companies/types/company';

type CompanyFormDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When provided the dialog edits; otherwise it creates. */
  company?: Company | null;
};

const FORM_ID = 'company-form';

function toFormValues(company?: Company | null): CompanyFormValues {
  if (!company) {
    return {
      code: '',
      name: '',
      legal_name: '',
      tax_number: '',
      commercial_registration: '',
      email: '',
      phone: '',
      mobile: '',
      website: '',
      currency: '',
      timezone: '',
      country: '',
      city: '',
      address: '',
      postal_code: '',
      is_active: true,
    };
  }

  return {
    code: company.code,
    name: company.name,
    legal_name: company.legal_name ?? '',
    tax_number: company.tax_number ?? '',
    commercial_registration: company.commercial_registration ?? '',
    email: company.email ?? '',
    phone: company.phone ?? '',
    mobile: company.mobile ?? '',
    website: company.website ?? '',
    currency: company.currency ?? '',
    timezone: company.timezone ?? '',
    country: company.country ?? '',
    city: company.city ?? '',
    address: company.address ?? '',
    postal_code: company.postal_code ?? '',
    is_active: company.is_active,
  };
}

function toPayload(values: CompanyFormValues): CompanyPayload {
  return { ...values };
}

/**
 * Create / edit company dialog. A single reusable component serves both modes.
 */
export function CompanyFormDialog({ open, onOpenChange, company }: CompanyFormDialogProps) {
  const isEdit = Boolean(company);
  const createCompany = useCreateCompany();
  const updateCompany = useUpdateCompany();
  const [serverError, setServerError] = useState<string | null>(null);

  const isPending = createCompany.isPending || updateCompany.isPending;

  const handleSubmit = (values: CompanyFormValues) => {
    setServerError(null);
    const payload = toPayload(values);

    const handlers = {
      onSuccess: () => onOpenChange(false),
      onError: (error: unknown) => {
        const message =
          axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
            ? error.response.data.message
            : 'Something went wrong. Please try again.';
        setServerError(message);
      },
    };

    if (isEdit && company) {
      updateCompany.mutate({ id: company.id, payload }, handlers);
    } else {
      createCompany.mutate(payload, handlers);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Edit Company' : 'Create Company'}</DialogTitle>
          <DialogDescription>
            {isEdit
              ? 'Update the company details below.'
              : 'Add a new company to your organization.'}
          </DialogDescription>
        </DialogHeader>

        {serverError ? (
          <Alert variant="destructive">
            <AlertTitle>Unable to save</AlertTitle>
            <AlertDescription>{serverError}</AlertDescription>
          </Alert>
        ) : null}

        <CompanyForm
          key={company?.id ?? 'new'}
          formId={FORM_ID}
          defaultValues={toFormValues(company)}
          onSubmit={handleSubmit}
        />

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isPending}>
            {isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create company'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
