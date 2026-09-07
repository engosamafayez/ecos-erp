import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/forms/form-field';
import { useCloneRoleMutation } from '@/features/iam-admin/hooks/use-roles';

/**
 * Clone Role (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11/§15).
 *
 * The sanctioned way to get an editable copy of a protected role — a system role, or one
 * compiled from an immutable ECOS system template. The clone always lands as an editable
 * company-scoped custom role; the source is never modified.
 */
export function RoleCloneDialog({
  sourceId,
  sourceName,
  open,
  onOpenChange,
}: {
  sourceId: string;
  sourceName: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const clone = useCloneRoleMutation(sourceId);
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);

  const [prevOpen, setPrevOpen] = useState(open);
  const [prevSourceName, setPrevSourceName] = useState(sourceName);
  if (open !== prevOpen || sourceName !== prevSourceName) {
    setPrevOpen(open);
    setPrevSourceName(sourceName);
    if (open) {
      setName(t(($) => $.roles.clone.defaultName, { name: sourceName }));
      setError(null);
    }
  }

  function handleSubmit() {
    setError(null);
    clone.mutate(name, {
      onSuccess: () => onOpenChange(false),
      onError: (err) =>
        setError(
          axios.isAxiosError(err) && typeof err.response?.data?.message === 'string'
            ? err.response.data.message
            : t(($) => $.roles.form.genericError),
        ),
    });
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t(($) => $.roles.clone.title, { name: sourceName })}</DialogTitle>
        </DialogHeader>
        {error ? (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        ) : null}
        <FormField name="name" label={t(($) => $.roles.fields.name)} required>
          <Input value={name} onChange={(e) => setName(e.target.value)} />
        </FormField>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {tCommon(($) => $.common.cancel)}
          </Button>
          <Button type="button" onClick={handleSubmit} disabled={!name.trim() || clone.isPending}>
            {clone.isPending ? tCommon(($) => $.actions.working) : t(($) => $.roles.clone.submit)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
