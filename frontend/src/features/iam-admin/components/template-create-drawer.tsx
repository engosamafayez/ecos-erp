import { useEffect, useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { EntityDrawer } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { FormField } from '@/components/ui/forms/form-field';
import { useCreateRoleTemplate } from '@/features/iam-admin/hooks/use-role-templates';
import type { CreateRoleTemplatePayload } from '@/features/iam-admin/types/role-template';

import { TemplatePermissionsEditor } from './template-permissions-editor';

const EMPTY: CreateRoleTemplatePayload = {
  key: '',
  name: '',
  description: '',
  category: 'custom',
  definition: { permissions: [] },
};

/**
 * D2/D11: no company_id field anywhere here — every custom template is server-derived to the
 * actor's own company, exactly like User creation.
 */
export function TemplateCreateDrawer({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const createTemplate = useCreateRoleTemplate();
  const [values, setValues] = useState<CreateRoleTemplatePayload>(EMPTY);
  const [serverError, setServerError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setValues(EMPTY);
      setServerError(null);
    }
  }, [open]);

  function handleSubmit() {
    setServerError(null);
    createTemplate.mutate(values, {
      onSuccess: () => onOpenChange(false),
      onError: (error) =>
        setServerError(
          axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
            ? error.response.data.message
            : t(($) => $.roleTemplates.form.genericError),
        ),
    });
  }

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t(($) => $.roleTemplates.create.title)}
      description={t(($) => $.roleTemplates.create.description)}
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {tCommon(($) => $.common.cancel)}
          </Button>
          <Button
            type="button"
            onClick={handleSubmit}
            disabled={createTemplate.isPending || !values.key || !values.name}
          >
            {createTemplate.isPending ? t(($) => $.roleTemplates.create.submitting) : t(($) => $.roleTemplates.create.submit)}
          </Button>
        </>
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>{t(($) => $.roleTemplates.form.genericError)}</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <div className="flex flex-col gap-4">
        <FormField name="key" label={t(($) => $.roleTemplates.fields.key)} required hint={t(($) => $.roleTemplates.fields.keyHint)}>
          <Input value={values.key} onChange={(e) => setValues((v) => ({ ...v, key: e.target.value }))} />
        </FormField>
        <FormField name="name" label={t(($) => $.roleTemplates.fields.name)} required>
          <Input value={values.name} onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))} />
        </FormField>
        <FormField name="category" label={t(($) => $.roleTemplates.fields.category)} required>
          <Input value={values.category} onChange={(e) => setValues((v) => ({ ...v, category: e.target.value }))} />
        </FormField>
        <FormField name="description" label={t(($) => $.roleTemplates.fields.description)} optional>
          <Textarea
            value={values.description ?? ''}
            onChange={(e) => setValues((v) => ({ ...v, description: e.target.value }))}
          />
        </FormField>
        <FormField name="permissions" label={t(($) => $.roleTemplates.fields.permissions)}>
          <TemplatePermissionsEditor
            value={values.definition.permissions ?? []}
            onChange={(permissions) => setValues((v) => ({ ...v, definition: { ...v.definition, permissions } }))}
          />
        </FormField>
      </div>
    </EntityDrawer>
  );
}
