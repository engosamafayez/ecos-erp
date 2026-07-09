import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCreateWorkflow, useUpdateWorkflow } from '../hooks/use-automation-workflows';
import type { AutomationWorkflow, WorkflowTriggerType } from '../types/automation';

interface Props {
  open: boolean;
  onClose: () => void;
  workflow?: AutomationWorkflow;
}

interface FormValues {
  name: string;
  description: string;
  trigger_type: WorkflowTriggerType;
  event_type: string;
  tags: string;
}

const TRIGGER_TYPES: { value: WorkflowTriggerType; label: string }[] = [
  { value: 'business_event', label: 'Business Event' },
  { value: 'schedule',       label: 'Schedule (Cron)' },
  { value: 'date_based',     label: 'Date-Based (Birthday, etc.)' },
  { value: 'webhook',        label: 'Webhook' },
  { value: 'api',            label: 'API Call' },
  { value: 'manual',         label: 'Manual Trigger' },
];

export function WorkflowDrawer({ open, onClose, workflow }: Props) {
  const isEditing = !!workflow;
  const create    = useCreateWorkflow();
  const update    = useUpdateWorkflow(workflow?.id ?? '');

  const { register, handleSubmit, reset, setValue, watch, formState: { isSubmitting } } = useForm<FormValues>({
    defaultValues: {
      name:         '',
      description:  '',
      trigger_type: 'business_event',
      event_type:   '',
      tags:         '',
    },
  });

  const triggerType = watch('trigger_type');

  useEffect(() => {
    if (workflow) {
      reset({
        name:         workflow.name,
        description:  workflow.description ?? '',
        trigger_type: workflow.trigger_type,
        event_type:   (workflow.event_subscriptions?.[0]?.event_type) ?? '',
        tags:         (workflow.tags ?? []).join(', '),
      });
    } else {
      reset({ name: '', description: '', trigger_type: 'business_event', event_type: '', tags: '' });
    }
  }, [workflow, reset]);

  async function onSubmit(values: FormValues) {
    const tags = values.tags ? values.tags.split(',').map(t => t.trim()).filter(Boolean) : undefined;

    if (isEditing) {
      await update.mutateAsync({ name: values.name, description: values.description || undefined, tags });
    } else {
      await create.mutateAsync({
        name:         values.name,
        description:  values.description || undefined,
        trigger_type: values.trigger_type,
        event_type:   values.event_type || undefined,
        tags,
      });
    }
    onClose();
  }

  return (
    <Sheet open={open} onOpenChange={v => !v && onClose()}>
      <SheetContent className="w-[420px]">
        <SheetHeader>
          <SheetTitle>{isEditing ? 'Edit Workflow' : 'New Workflow'}</SheetTitle>
        </SheetHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4 mt-6">
          <div className="space-y-1.5">
            <Label>Workflow Name *</Label>
            <Input {...register('name', { required: true })} placeholder="e.g. Abandoned Cart Recovery" />
          </div>

          <div className="space-y-1.5">
            <Label>Description</Label>
            <Textarea {...register('description')} placeholder="What does this workflow do?" rows={2} />
          </div>

          {!isEditing && (
            <>
              <div className="space-y-1.5">
                <Label>Trigger Type *</Label>
                <Select
                  value={triggerType}
                  onValueChange={v => setValue('trigger_type', v as WorkflowTriggerType)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TRIGGER_TYPES.map(t => (
                      <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {triggerType === 'business_event' && (
                <div className="space-y-1.5">
                  <Label>Business Event Type</Label>
                  <Input {...register('event_type')} placeholder="e.g. order.placed, lead.created" />
                  <p className="text-xs text-muted-foreground">
                    Subscribe to a BAE event that triggers this workflow.
                  </p>
                </div>
              )}
            </>
          )}

          <div className="space-y-1.5">
            <Label>Tags</Label>
            <Input {...register('tags')} placeholder="e.g. crm, retention, vip (comma separated)" />
          </div>

          <div className="flex gap-2 pt-2">
            <Button type="submit" className="flex-1" disabled={isSubmitting}>
              {isSubmitting ? 'Saving...' : isEditing ? 'Save Changes' : 'Create Workflow'}
            </Button>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  );
}

