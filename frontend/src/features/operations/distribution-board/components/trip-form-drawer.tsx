import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetFooter,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useCreateTrip, useUpdateTrip } from '../hooks/use-distribution-board';
import { TRIP_TYPE_LABELS, type BoardZone, type DistributionTrip, type TripType } from '../types/distribution-board';

const schema = z.object({
  name:                 z.string().min(1, 'Name is required').max(100),
  type:                 z.enum(['company_vehicle', 'personal_vehicle', 'external_carrier']),
  distribution_zone_id: z.number().nullable(),
  capacity:             z.number().min(1).max(500),
  notes:                z.string().max(1000).optional(),
});

type FormData = z.infer<typeof schema>;

interface TripFormDrawerProps {
  open: boolean;
  onClose: () => void;
  waveId: string;
  zones: BoardZone[];
  /** When provided, we're editing an existing trip. */
  trip?: DistributionTrip | null;
  /** Pre-select a zone when creating. */
  defaultZoneId?: number | null;
}

export function TripFormDrawer({
  open,
  onClose,
  waveId,
  zones,
  trip,
  defaultZoneId,
}: TripFormDrawerProps) {
  const isEditing   = !!trip;
  const createTrip  = useCreateTrip();
  const updateTrip  = useUpdateTrip();

  const { register, handleSubmit, setValue, watch, reset, formState: { errors } } = useForm<FormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      name:                 trip?.name ?? '',
      type:                 trip?.type ?? 'company_vehicle',
      distribution_zone_id: trip?.distribution_zone_id ?? defaultZoneId ?? null,
      capacity:             trip?.capacity ?? 60,
      notes:                trip?.notes ?? '',
    },
  });

  useEffect(() => {
    if (open) {
      reset({
        name:                 trip?.name ?? '',
        type:                 trip?.type ?? 'company_vehicle',
        distribution_zone_id: trip?.distribution_zone_id ?? defaultZoneId ?? null,
        capacity:             trip?.capacity ?? 60,
        notes:                trip?.notes ?? '',
      });
    }
  }, [open, trip, defaultZoneId, reset]);

  function onSubmit(data: FormData) {
    if (isEditing) {
      updateTrip.mutate(
        { id: trip.id, payload: data },
        { onSuccess: onClose },
      );
    } else {
      createTrip.mutate(
        { preparation_wave_id: waveId, ...data },
        { onSuccess: onClose },
      );
    }
  }

  const isPending = createTrip.isPending || updateTrip.isPending;
  const typeValue = watch('type');

  return (
    <Sheet open={open} onOpenChange={(v) => !v && onClose()}>
      <SheetContent side="right" className="w-96">
        <SheetHeader>
          <SheetTitle>{isEditing ? 'تعديل الرحلة' : 'إنشاء رحلة'}</SheetTitle>
        </SheetHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="mt-6 space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="name">اسم الرحلة</Label>
            <Input id="name" {...register('name')} placeholder="مثال: فان القاهرة الشرقية أ" />
            {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
          </div>

          <div className="space-y-1.5">
            <Label>نوع الرحلة</Label>
            <Select
              value={typeValue}
              onValueChange={(v) => setValue('type', v as TripType)}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {(Object.entries(TRIP_TYPE_LABELS) as [TripType, string][]).map(([val, label]) => (
                  <SelectItem key={val} value={val}>{label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label>المنطقة</Label>
            <Select
              value={watch('distribution_zone_id')?.toString() ?? '__null__'}
              onValueChange={(v) => setValue('distribution_zone_id', v === '__null__' ? null : Number(v))}
            >
              <SelectTrigger>
                <SelectValue placeholder="اختر منطقة…" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__null__">— بدون منطقة —</SelectItem>
                {zones.map((z) => (
                  <SelectItem key={z.zone_id} value={z.zone_id.toString()}>
                    {z.name_en} ({z.unassigned_orders} غير مُعيَّن)
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="capacity">الطاقة الاستيعابية (طلبات)</Label>
            <Input
              id="capacity"
              type="number"
              min="1"
              max="500"
              {...register('capacity', { valueAsNumber: true })}
            />
            {errors.capacity && <p className="text-xs text-destructive">{errors.capacity.message}</p>}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="notes">ملاحظات</Label>
            <Textarea
              id="notes"
              {...register('notes')}
              rows={3}
              placeholder="ملاحظات اختيارية…"
            />
          </div>

          <SheetFooter className="pt-4 gap-2">
            <Button type="button" variant="outline" onClick={onClose} disabled={isPending}>
              إلغاء
            </Button>
            <Button type="submit" disabled={isPending}>
              {isPending ? 'جارٍ الحفظ…' : isEditing ? 'حفظ التغييرات' : 'إنشاء رحلة'}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}
