import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { EntityDrawer, EntityForm } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CustomerFormFields } from '@/features/customers/components/customer-form';
import {
  customerSchema,
  toFormValues,
  toPayload,
  type CustomerFormValues,
} from '@/features/customers/components/customer-form-schema';
import { useCreateCustomer, useUpdateCustomer } from '@/features/customers/hooks/use-customers';
import type { Customer } from '@/features/customers/types/customer';

const FORM_ID = 'customer-form';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  customer?: Customer | null;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

export function CustomerFormDrawer({ open, onOpenChange, customer }: Props) {
  const { t } = useTranslation('customers');
  const { t: tCommon } = useTranslation('common');
  const isEdit = Boolean(customer);
  const createCustomer = useCreateCustomer();
  const updateCustomer = useUpdateCustomer();
  const [serverError, setServerError] = useState<string | null>(null);

  const form = useForm<CustomerFormValues>({
    resolver: zodResolver(customerSchema),
    defaultValues: toFormValues(customer),
  });

  useEffect(() => {
    if (open) form.reset(toFormValues(customer));
  }, [open, customer, form]);

  const isPending = createCustomer.isPending || updateCustomer.isPending;

  const handleOpenChange = (next: boolean) => {
    if (!next) setServerError(null);
    onOpenChange(next);
  };

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
        <CustomerFormFields />
      </EntityForm>
    </EntityDrawer>
  );
}
