import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { EntityDrawer } from '@/components/crud/entity-drawer';
import { useToast } from '@/components/ds';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCreateCrmLead } from '@/features/crm/hooks/use-crm-leads';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: () => void;
};

/** Create-only — a Lead's identity is fixed at creation; status/conversion are separate actions. */
export function CrmLeadFormDrawer({ open, onOpenChange, onCreated }: Props) {
  const { t } = useTranslation('crm');
  const { toast } = useToast();
  const create = useCreateCrmLead();

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [companyName, setCompanyName] = useState('');
  const [source, setSource] = useState('');

  function reset() {
    setName('');
    setPhone('');
    setEmail('');
    setCompanyName('');
    setSource('');
  }

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t(($) => $.leads.form.createTitle)}
    >
      <form
        className="flex flex-col gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          if (!name.trim()) return;

          create.mutate(
            {
              name: name.trim(),
              phone: phone || null,
              email: email || null,
              company_name: companyName || null,
              source: source || null,
            },
            {
              onSuccess: () => {
                toast({ title: t(($) => $.leads.form.createdToast) });
                reset();
                onOpenChange(false);
                onCreated?.();
              },
              onError: () => toast({ title: t(($) => $.form.failedToast), variant: 'destructive' }),
            },
          );
        }}
      >
        <div className="flex flex-col gap-1">
          <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.leads.fields.name)}
          </label>
          <Input value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.leads.fields.phone)}
          </label>
          <Input value={phone} onChange={(e) => setPhone(e.target.value)} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.leads.fields.email)}
          </label>
          <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.leads.fields.company)}
          </label>
          <Input value={companyName} onChange={(e) => setCompanyName(e.target.value)} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.leads.fields.source)}
          </label>
          <Input value={source} onChange={(e) => setSource(e.target.value)} />
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t(($) => $.drawer.close)}
          </Button>
          <Button type="submit" disabled={!name.trim() || create.isPending}>
            {t(($) => $.leads.form.create)}
          </Button>
        </div>
      </form>
    </EntityDrawer>
  );
}
