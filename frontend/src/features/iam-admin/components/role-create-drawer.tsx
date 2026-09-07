import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { EntityDrawer } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { FormField } from '@/components/ui/forms/form-field';
import { useCreateRoleMutation } from '@/features/iam-admin/hooks/use-roles';
import type { CreateRolePayload } from '@/features/iam-admin/types/role';

import { PermissionMatrix } from './permission-matrix';

const EMPTY: CreateRolePayload = { name: '', description: '', permissions: [] };

/**
 * Create Role (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11).
 *
 * No `slug`/`key` field — the backend derives a stable identifier from the name
 * (RoleAuthoringService::uniqueKey()), matching §9's "never require an admin to manually
 * type raw entity IDs" in spirit: a role's machine key is exactly the kind of thing an
 * administrator should never have to invent. No `company_id` either — server-derived,
 * matching CreateUserRequest's D2 precedent.
 *
 * Writing this role's grants goes through RoleAuthoringService → the one canonical
 * RoleTemplateCompiler; see roles-service.ts.
 */
export function RoleCreateDrawer({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const createRole = useCreateRoleMutation();
  const [values, setValues] = useState<CreateRolePayload>(EMPTY);
  const [serverError, setServerError] = useState<string | null>(null);

  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setValues(EMPTY);
      setServerError(null);
    }
  }

  function handleSubmit() {
    setServerError(null);
    createRole.mutate(values, {
      onSuccess: () => onOpenChange(false),
      onError: (error) =>
        setServerError(
          axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
            ? error.response.data.message
            : t(($) => $.roles.form.genericError),
        ),
    });
  }

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t(($) => $.roles.create.title)}
      description={t(($) => $.roles.create.description)}
      footer={
        <>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {tCommon(($) => $.common.cancel)}
          </Button>
          <Button type="button" onClick={handleSubmit} disabled={createRole.isPending || !values.name.trim()}>
            {createRole.isPending ? t(($) => $.roles.create.submitting) : t(($) => $.roles.create.submit)}
          </Button>
        </>
      }
    >
      {serverError ? (
        <Alert variant="destructive" className="mb-4">
          <AlertTitle>{t(($) => $.roles.form.genericError)}</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <div className="flex flex-col gap-4">
        <FormField name="name" label={t(($) => $.roles.fields.name)} required>
          <Input value={values.name} onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))} />
        </FormField>
        <FormField name="description" label={t(($) => $.roles.fields.description)} optional>
          <Textarea
            value={values.description ?? ''}
            onChange={(e) => setValues((v) => ({ ...v, description: e.target.value }))}
          />
        </FormField>

        <div>
          <p className="mb-1.5 text-sm font-medium">{t(($) => $.roles.fields.permissions)}</p>
          <PermissionMatrix
            value={values.permissions ?? []}
            onChange={(permissions) => setValues((v) => ({ ...v, permissions }))}
          />
        </div>
      </div>
    </EntityDrawer>
  );
}
