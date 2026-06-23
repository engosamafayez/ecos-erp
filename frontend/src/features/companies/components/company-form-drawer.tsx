import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

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
  company?: Company | null;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

export function CompanyFormDrawer({ open, onOpenChange, company }: CompanyFormDrawerProps) {
  const { t } = useTranslation('companies');
  const { t: tCommon } = useTranslation('common');
  const isEdit = Boolean(company);
  const createCompany = useCreateCompany();
  const updateCompany = useUpdateCompany();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<CompanyFormValues>({
    resolver: zodResolver(companySchema),
    defaultValues: toFormValues(company),
  });

  useEffect(() => {
    if (open) {
      form.reset(toFormValues(company));
    }
  }, [open, company, form]);

  const isPending = createCompany.isPending || updateCompany.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) setServerError(null);
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
      title={isEdit ? t('drawer.editTitle') : t('drawer.createTitle')}
      description={isEdit ? t('drawer.editSubtitle') : t('drawer.createSubtitle')}
      footer={
        <>
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
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>{t('drawer.errorTitle')}</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit}>
        <CompanyFormFields />
      </EntityForm>
    </EntityDrawer>
  );
}
