import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';

import { EntityDrawer, EntityForm } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CompanyFormFields } from '@/features/companies/components/company-form';
import {
  companySchema,
  toFormValues,
  toPayload,
  type CompanyFormValues,
} from '@/features/companies/components/company-form-schema';
import { useCreateCompany, useUpdateCompany } from '@/features/companies/hooks/use-companies';
import type { Company } from '@/features/companies/types/company';

const FORM_ID = 'company-form';

type CompanyFormDrawerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When provided the drawer edits; otherwise it creates. */
  company?: Company | null;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

/**
 * Create / edit company slide-over. A single reusable component serves both
 * modes, built on the shared EntityDrawer + EntityForm.
 */
export function CompanyFormDrawer({ open, onOpenChange, company }: CompanyFormDrawerProps) {
  const isEdit = Boolean(company);
  const createCompany = useCreateCompany();
  const updateCompany = useUpdateCompany();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<CompanyFormValues>({
    resolver: zodResolver(companySchema),
    defaultValues: toFormValues(company),
  });

  // Sync the form to the target company whenever the drawer opens (RHF reset is
  // an external-system update, not React state).
  useEffect(() => {
    if (open) {
      form.reset(toFormValues(company));
    }
  }, [open, company, form]);

  const isPending = createCompany.isPending || updateCompany.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setServerError(null);
    }
    onOpenChange(next);
  };

  const handleSubmit = (values: CompanyFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    const handlers = {
      onSuccess: () => handleOpenChange(false),
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (isEdit && company) {
      updateCompany.mutate({ id: company.id, payload }, handlers);
    } else {
      createCompany.mutate(payload, handlers);
    }
  };

  return (
    <EntityDrawer
      open={open}
      onOpenChange={handleOpenChange}
      title={isEdit ? 'Edit Company' : 'Create Company'}
      description={
        isEdit ? 'Update the company details below.' : 'Add a new company to your organization.'
      }
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
            Cancel
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isPending}>
            {isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create company'}
          </Button>
        </>
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>Unable to save</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit}>
        <CompanyFormFields />
      </EntityForm>
    </EntityDrawer>
  );
}
