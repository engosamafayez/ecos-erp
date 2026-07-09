import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCreateSegment, useUpdateSegment } from '../hooks/use-audience-segments';
import type { AudienceSegment, SegmentType } from '../types/automation';

interface Props {
  open: boolean;
  onClose: () => void;
  segment?: AudienceSegment;
}

interface FormValues {
  name: string;
  description: string;
  segment_type: SegmentType;
  entity_type: string;
  is_dynamic: boolean;
}

const SEGMENT_TYPES: { value: SegmentType; label: string }[] = [
  { value: 'demographic',   label: 'Demographic' },
  { value: 'geographic',    label: 'Geographic' },
  { value: 'behavioral',    label: 'Behavioral' },
  { value: 'transactional', label: 'Transactional' },
  { value: 'marketing',     label: 'Marketing' },
  { value: 'business',      label: 'Business' },
  { value: 'operational',   label: 'Operational' },
  { value: 'custom',        label: 'Custom' },
];

export function SegmentDrawer({ open, onClose, segment }: Props) {
  const isEditing = !!segment;
  const create    = useCreateSegment();
  const update    = useUpdateSegment(segment?.id ?? '');

  const { register, handleSubmit, reset, setValue, watch, formState: { isSubmitting } } = useForm<FormValues>({
    defaultValues: {
      name:         '',
      description:  '',
      segment_type: 'behavioral',
      entity_type:  'customer',
      is_dynamic:   true,
    },
  });

  const segmentType = watch('segment_type');

  useEffect(() => {
    if (segment) {
      reset({
        name:         segment.name,
        description:  segment.description ?? '',
        segment_type: segment.segment_type,
        entity_type:  segment.entity_type,
        is_dynamic:   segment.is_dynamic,
      });
    } else {
      reset({ name: '', description: '', segment_type: 'behavioral', entity_type: 'customer', is_dynamic: true });
    }
  }, [segment, reset]);

  async function onSubmit(values: FormValues) {
    if (isEditing) {
      await update.mutateAsync({ name: values.name, description: values.description || undefined });
    } else {
      await create.mutateAsync({
        name:         values.name,
        description:  values.description || undefined,
        segment_type: values.segment_type,
        entity_type:  values.entity_type,
        is_dynamic:   values.is_dynamic,
        rules:        { conditions: [] },
      });
    }
    onClose();
  }

  return (
    <Sheet open={open} onOpenChange={v => !v && onClose()}>
      <SheetContent className="w-[420px]">
        <SheetHeader>
          <SheetTitle>{isEditing ? 'Edit Segment' : 'New Audience Segment'}</SheetTitle>
        </SheetHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4 mt-6">
          <div className="space-y-1.5">
            <Label>Segment Name *</Label>
            <Input {...register('name', { required: true })} placeholder="e.g. VIP Customers" />
          </div>

          <div className="space-y-1.5">
            <Label>Description</Label>
            <Textarea {...register('description')} placeholder="What defines this segment?" rows={2} />
          </div>

          {!isEditing && (
            <>
              <div className="space-y-1.5">
                <Label>Segment Type *</Label>
                <Select
                  value={segmentType}
                  onValueChange={v => setValue('segment_type', v as SegmentType)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {SEGMENT_TYPES.map(t => (
                      <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1.5">
                <Label>Entity Type</Label>
                <Select
                  value={watch('entity_type')}
                  onValueChange={v => setValue('entity_type', v)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="customer">Customer</SelectItem>
                    <SelectItem value="lead">Lead</SelectItem>
                    <SelectItem value="order">Order</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_dynamic"
                  {...register('is_dynamic')}
                  className="h-4 w-4"
                />
                <Label htmlFor="is_dynamic">Dynamic (auto-recalculates)</Label>
              </div>
            </>
          )}

          <div className="flex gap-2 pt-2">
            <Button type="submit" className="flex-1" disabled={isSubmitting}>
              {isSubmitting ? 'Saving...' : isEditing ? 'Save Changes' : 'Create Segment'}
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

