import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { EntityDrawer } from '@/components/crud/entity-drawer';
import { useToast } from '@/components/ds';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCreateCrmOpportunity } from '@/features/crm/hooks/use-crm-pipeline';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The pipeline currently being viewed — new deals land on its default first stage. */
  pipelineId: string | null;
  onCreated?: () => void;
};

/**
 * A manual "new deal" entry point on the board itself. Most Opportunities are
 * expected to arrive via Lead conversion (Task 1); this covers the direct
 * case. No customer/lead picker — deliberately minimal, see CRM-01 Task 2
 * report, "Opportunity detail".
 */
export function CrmOpportunityFormDrawer({ open, onOpenChange, pipelineId, onCreated }: Props) {
  const { t } = useTranslation('crm');
  const { toast } = useToast();
  const create = useCreateCrmOpportunity();

  const [name, setName] = useState('');
  const [amount, setAmount] = useState('');
  const [expectedCloseDate, setExpectedCloseDate] = useState('');

  function reset() {
    setName('');
    setAmount('');
    setExpectedCloseDate('');
  }

  return (
    <EntityDrawer open={open} onOpenChange={onOpenChange} title={t(($) => $.pipeline.form.createTitle)}>
      <form
        className="flex flex-col gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          if (!name.trim()) return;

          create.mutate(
            {
              name: name.trim(),
              pipeline_id: pipelineId,
              amount: amount ? Number(amount) : null,
              expected_close_date: expectedCloseDate || null,
            },
            {
              onSuccess: () => {
                toast({ title: t(($) => $.pipeline.form.createdToast) });
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
            {t(($) => $.pipeline.fields.name)}
          </label>
          <Input value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.pipeline.fields.amount)}
          </label>
          <Input type="number" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.pipeline.fields.expectedCloseDate)}
          </label>
          <Input
            type="date"
            value={expectedCloseDate}
            onChange={(e) => setExpectedCloseDate(e.target.value)}
          />
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t(($) => $.drawer.close)}
          </Button>
          <Button type="submit" disabled={!name.trim() || create.isPending}>
            {t(($) => $.pipeline.form.create)}
          </Button>
        </div>
      </form>
    </EntityDrawer>
  );
}
