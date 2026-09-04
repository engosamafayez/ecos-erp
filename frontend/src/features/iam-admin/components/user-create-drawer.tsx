import { useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { EntityDrawer, EntityForm, FormField } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCreateUser } from '@/features/iam-admin/hooks/use-users';
import { toCreatePayload, toFormValues, userSchema, type UserFormValues } from './user-form-schema';

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

/** D6: field-level errors surface via the 422 `errors` object; anything else falls back to the top-level message. */
function extractFieldErrors(error: unknown): Record<string, string> {
  if (axios.isAxiosError(error) && error.response?.status === 422) {
    const errors = error.response.data?.errors as Record<string, string[] | string> | undefined;
    if (errors) {
      return Object.fromEntries(
        Object.entries(errors).map(([field, msg]) => [field, Array.isArray(msg) ? msg[0] : msg]),
      );
    }
  }
  return {};
}

export function UserCreateDrawer({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const createUser = useCreateUser();
  const [serverError, setServerError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const form = useForm<UserFormValues>({
    resolver: zodResolver(userSchema),
    defaultValues: toFormValues(),
  });

  // Resets the form/errors each time the drawer opens — adjusted during render (React's
  // documented pattern for this) rather than in a useEffect, which would cost an extra render.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      form.reset(toFormValues());
      setServerError(null);
      setFieldErrors({});
    }
  }

  const handleSubmit = (values: UserFormValues) => {
    setServerError(null);
    setFieldErrors({});
    createUser.mutate(toCreatePayload(values), {
      onSuccess: () => onOpenChange(false),
      onError: (error) => {
        setServerError(extractMessage(error));
        setFieldErrors(extractFieldErrors(error));
      },
    });
  };

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t(($) => $.users.create.title)}
      description={t(($) => $.users.create.description)}
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {tCommon(($) => $.common.cancel)}
          </Button>
          <Button type="submit" form="iam-user-create-form" disabled={createUser.isPending}>
            {createUser.isPending ? t(($) => $.users.create.submitting) : t(($) => $.users.create.submit)}
          </Button>
        </>
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>{t(($) => $.users.create.errorTitle)}</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <EntityForm id="iam-user-create-form" form={form} onSubmit={handleSubmit} className="flex flex-col gap-4">
        <FormField name="name" label={t(($) => $.users.fields.name)} required error={fieldErrors.name}>
          <Input {...form.register('name')} />
        </FormField>
        <FormField name="email" label={t(($) => $.users.fields.email)} required error={fieldErrors.email}>
          <Input type="email" {...form.register('email')} />
        </FormField>
        <FormField name="display_name" label={t(($) => $.users.fields.displayName)} optional>
          <Input {...form.register('display_name')} />
        </FormField>
        <FormField name="username" label={t(($) => $.users.fields.username)} optional>
          <Input {...form.register('username')} />
        </FormField>
        <FormField name="employee_number" label={t(($) => $.users.fields.employeeNumber)} optional>
          <Input {...form.register('employee_number')} />
        </FormField>
        <FormField name="phone" label={t(($) => $.users.fields.phone)} optional>
          <Input {...form.register('phone')} />
        </FormField>
      </EntityForm>
    </EntityDrawer>
  );
}
