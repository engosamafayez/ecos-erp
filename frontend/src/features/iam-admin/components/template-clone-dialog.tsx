import { useEffect, useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/forms/form-field';
import { useCloneRoleTemplate } from '@/features/iam-admin/hooks/use-role-templates';

/** D11: cloning always produces a custom, tenant-scoped template — no company_id field here either. */
export function TemplateCloneDialog({
  sourceKey,
  sourceName,
  open,
  onOpenChange,
}: {
  sourceKey: string;
  sourceName: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const clone = useCloneRoleTemplate(sourceKey);
  const [newKey, setNewKey] = useState('');
  const [newName, setNewName] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setNewKey('');
      setNewName(`${sourceName} (Copy)`);
      setError(null);
    }
  }, [open, sourceName]);

  function handleSubmit() {
    setError(null);
    clone.mutate(
      { new_key: newKey, new_name: newName || undefined },
      {
        onSuccess: () => onOpenChange(false),
        onError: (err) =>
          setError(
            axios.isAxiosError(err) && typeof err.response?.data?.message === 'string'
              ? err.response.data.message
              : t(($) => $.roleTemplates.form.genericError),
          ),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t(($) => $.roleTemplates.clone.title, { name: sourceName })}</DialogTitle>
        </DialogHeader>
        {error ? (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        ) : null}
        <div className="flex flex-col gap-3">
          <FormField name="new_key" label={t(($) => $.roleTemplates.fields.key)} required>
            <Input value={newKey} onChange={(e) => setNewKey(e.target.value)} />
          </FormField>
          <FormField name="new_name" label={t(($) => $.roleTemplates.fields.name)} optional>
            <Input value={newName} onChange={(e) => setNewName(e.target.value)} />
          </FormField>
        </div>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {tCommon(($) => $.common.cancel)}
          </Button>
          <Button type="button" onClick={handleSubmit} disabled={!newKey || clone.isPending}>
            {clone.isPending ? tCommon(($) => $.actions.working) : t(($) => $.roleTemplates.clone.submit)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
