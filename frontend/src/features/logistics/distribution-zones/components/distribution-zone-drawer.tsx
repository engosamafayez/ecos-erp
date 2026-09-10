import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronLeft, ChevronRight, Loader2, Network } from 'lucide-react';
import axios from 'axios';

import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { Button }   from '@/components/ui/button';
import { Input }    from '@/components/ui/input';
import { Label }    from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch }   from '@/components/ui/switch';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge }    from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { cn }       from '@/lib/utils';
import { useToast } from '@/components/ds/use-toast';

import {
  useAreas,
  useCreateDistributionZone,
  useDistributionZone,
  useNextZoneCode,
  useUpdateDistributionZone,
} from '../hooks/use-distribution-zones';
import type { DistributionZone } from '../types/distribution-zone';
import { AreaSelector } from './area-selector';

// ── Zone Color Picker ─────────────────────────────────────────────────────────

const ZONE_COLORS: { label: string; hex: string }[] = [
  { label: 'Red',     hex: '#ef4444' },
  { label: 'Orange',  hex: '#f97316' },
  { label: 'Amber',   hex: '#f59e0b' },
  { label: 'Emerald', hex: '#10b981' },
  { label: 'Cyan',    hex: '#06b6d4' },
  { label: 'Blue',    hex: '#3b82f6' },
  { label: 'Indigo',  hex: '#6366f1' },
  { label: 'Purple',  hex: '#a855f7' },
  { label: 'Pink',    hex: '#ec4899' },
  { label: 'Slate',   hex: '#64748b' },
];

function ColorPicker({
  value,
  onChange,
  disabled,
}: {
  value: string | null;
  onChange: (hex: string | null) => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation('logistics');

  return (
    <div className="flex flex-wrap items-center gap-2">
      <button
        type="button"
        onClick={() => onChange(null)}
        disabled={disabled}
        title={t(($) => $.distributionZones.drawer.colorPicker.noColor)}
        className={`h-6 w-6 rounded-full border-2 bg-background transition-all disabled:pointer-events-none
          ${value === null ? 'border-foreground ring-2 ring-foreground ring-offset-1' : 'border-border hover:border-muted-foreground'}`}
      />
      {ZONE_COLORS.map((c) => (
        <button
          key={c.hex}
          type="button"
          onClick={() => onChange(c.hex)}
          disabled={disabled}
          title={c.label}
          style={{ backgroundColor: c.hex }}
          className={`h-6 w-6 rounded-full border-2 transition-all disabled:pointer-events-none
            ${value === c.hex
              ? 'border-foreground ring-2 ring-foreground ring-offset-1'
              : 'border-transparent hover:border-white/70 hover:scale-110'}`}
        />
      ))}
      {value && !ZONE_COLORS.some((c) => c.hex === value) && (
        <div
          style={{ backgroundColor: value }}
          className="h-6 w-6 rounded-full border-2 border-foreground ring-2 ring-foreground ring-offset-1"
          title={t(($) => $.distributionZones.drawer.colorPicker.custom, { value })}
        />
      )}
    </div>
  );
}

// ── Wizard Step Header ────────────────────────────────────────────────────────

type StepHeaderProps = { step: 1 | 2 };

function WizardStepHeader({ step }: StepHeaderProps) {
  const { t } = useTranslation('logistics');

  return (
    <div className="mb-5 flex shrink-0 items-center gap-0">
      {/* Step 1 */}
      <div className="flex items-center gap-2 shrink-0">
        <div
          className={cn(
            'flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold transition-colors',
            step === 1
              ? 'bg-primary text-primary-foreground'
              : 'bg-primary/15 text-primary',
          )}
        >
          ①
        </div>
        <span
          className={cn(
            'text-sm font-medium transition-colors',
            step === 1 ? 'text-foreground' : 'text-muted-foreground',
          )}
        >
          {t(($) => $.distributionZones.drawer.wizard.step1)}
        </span>
      </div>

      {/* Connector */}
      <div className="mx-3 h-px flex-1 bg-border" />

      {/* Step 2 */}
      <div className="flex items-center gap-2 shrink-0">
        <div
          className={cn(
            'flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold transition-colors',
            step === 2
              ? 'bg-primary text-primary-foreground'
              : 'border border-border bg-background text-muted-foreground',
          )}
        >
          ②
        </div>
        <span
          className={cn(
            'text-sm font-medium transition-colors',
            step === 2 ? 'text-foreground' : 'text-muted-foreground',
          )}
        >
          {t(($) => $.distributionZones.drawer.wizard.step2)}
        </span>
        {/* Selected count badge shown when areas are picked */}
        {step === 1 && false && null /* placeholder, shown via Badge below */}
      </div>
    </div>
  );
}

// ── General Skeleton ──────────────────────────────────────────────────────────

function GeneralSkeleton() {
  return (
    <div className="space-y-4 py-1">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-1.5">
          <Skeleton className="h-4 w-20" />
          <Skeleton className="h-9 w-full" />
        </div>
        <div className="flex items-end pb-0.5">
          <Skeleton className="h-6 w-24" />
        </div>
      </div>
      {Array.from({ length: 4 }).map((_, i) => (
        <div key={i} className="space-y-1.5">
          <Skeleton className="h-4 w-28" />
          <Skeleton className={i === 3 ? 'h-20 w-full' : 'h-9 w-full'} />
        </div>
      ))}
    </div>
  );
}

// ── Props / Form State ────────────────────────────────────────────────────────

type Props = {
  open:         boolean;
  onOpenChange: (open: boolean) => void;
  editZone?:    DistributionZone | null;
};

type FormState = {
  name_ar:     string;
  name_en:     string;
  description: string;
  color:       string | null;
  is_active:   boolean;
  area_ids:    number[];
  force_move:  boolean;
};

const EMPTY: FormState = {
  name_ar:     '',
  name_en:     '',
  description: '',
  color:       null,
  is_active:   true,
  area_ids:    [],
  force_move:  false,
};

// ── Drawer ────────────────────────────────────────────────────────────────────

export function DistributionZoneDrawer({ open, onOpenChange, editZone }: Props) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const isEdit    = editZone != null;

  const [form,        setForm]        = useState<FormState>(EMPTY);
  const [errors,      setErrors]      = useState<Record<string, string[]>>({});
  const [serverError, setServerError] = useState<string | null>(null);
  const [step,        setStep]        = useState<1 | 2>(1);

  // Auto-generated code preview (create mode only)
  const { data: nextCode, isLoading: loadingCode } = useNextZoneCode(open && !isEdit);

  // Full zone detail when editing
  const { data: zoneDetail, isLoading: loadingDetail } = useDistributionZone(
    isEdit && open ? editZone.id : null,
  );

  // All areas including those assigned to other zones (for SmartMove amber badges)
  const areasParams = isEdit && open
    ? { zone_id: editZone.id, include_all: true as const }
    : open ? { include_all: true as const } : undefined;

  const { data: areasData, isLoading: loadingAreas } = useAreas(open ? areasParams : undefined);

  // Compute stats for AreaSelector empty state
  const { totalAreasCount, assignedAreasCount } = useMemo(() => {
    const allCities = (areasData?.data ?? []).flatMap((g) => g.cities);
    const assignedToOther = allCities.filter(
      (c) => c.distribution_zone_id !== null &&
             c.distribution_zone_id !== (isEdit ? editZone?.id : null),
    ).length;
    return { totalAreasCount: allCities.length, assignedAreasCount: assignedToOther };
  }, [areasData, isEdit, editZone]);

  const create    = useCreateDistributionZone();
  const update    = useUpdateDistributionZone();
  const isPending = create.isPending || update.isPending;

  const initializedRef = useRef(false);

  useEffect(() => {
    if (!open) {
      setForm(EMPTY);
      setErrors({});
      setServerError(null);
      setStep(1);
      initializedRef.current = false;
      return;
    }
    if (isEdit && zoneDetail && !initializedRef.current) {
      initializedRef.current = true;
      setForm({
        name_ar:     zoneDetail.name_ar,
        name_en:     zoneDetail.name_en ?? '',
        description: zoneDetail.description ?? '',
        color:       zoneDetail.color ?? null,
        is_active:   zoneDetail.is_active,
        area_ids:    zoneDetail.areas?.map((a) => a.id) ?? [],
        force_move:  false,
      });
    }
    if (!isEdit && !initializedRef.current) {
      initializedRef.current = true;
    }
  }, [open, isEdit, zoneDetail]);

  function set<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      if (!(key in prev)) return prev;
      const e = { ...prev };
      delete e[key as string];
      return e;
    });
  }

  function validateStep1(): boolean {
    const e: Record<string, string[]> = {};
    if (!form.name_ar.trim()) e.name_ar = [t(($) => $.distributionZones.drawer.nameArRequired)];
    setErrors(e);
    return Object.keys(e).length === 0;
  }

  function validateStep2(): boolean {
    const e: Record<string, string[]> = {};
    if (form.area_ids.length === 0) e.area_ids = [t(($) => $.distributionZones.drawer.areaRequired)];
    setErrors(e);
    return Object.keys(e).length === 0;
  }

  function handleNext() {
    if (!validateStep1()) return;
    setStep(2);
  }

  async function handleSubmit() {
    if (!validateStep2()) return;
    setServerError(null);

    const payload = {
      name_ar:     form.name_ar.trim(),
      name_en:     form.name_en.trim() || null,
      description: form.description.trim() || null,
      color:       form.color || null,
      is_active:   form.is_active,
      area_ids:    form.area_ids,
      force_move:  form.force_move || undefined,
    };

    try {
      if (isEdit) {
        await update.mutateAsync({ id: editZone.id, payload });
        toast({ title: t(($) => $.distributionZones.toast.updated) });
      } else {
        await create.mutateAsync(payload);
        toast({ title: t(($) => $.distributionZones.toast.created) });
      }
      onOpenChange(false);
    } catch (err) {
      if (axios.isAxiosError(err)) {
        const data = err.response?.data as { message?: string; errors?: Record<string, string[]> };
        if (data?.errors) setErrors(data.errors);
        setServerError(data?.message ?? t(($) => $.distributionZones.toast.saveError));
      } else {
        setServerError(t(($) => $.distributionZones.toast.unexpectedError));
      }
    }
  }

  const title       = isEdit
    ? t(($) => $.distributionZones.drawer.editTitle, {
        name: editZone?.name_ar ?? t(($) => $.distributionZones.drawer.zoneFallback),
      })
    : t(($) => $.distributionZones.drawer.newTitle);
  const description = isEdit
    ? t(($) => $.distributionZones.drawer.editDescription)
    : t(($) => $.distributionZones.drawer.newDescription);

  const showGeneralSkeleton = isEdit && loadingDetail;

  const zoneCodeDisplay = isEdit
    ? editZone?.code
    : loadingCode
      ? '…'
      : (nextCode ?? '—');

  return (
    <PageDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={title}
      description={description}
      size="2xl"
      footer={
        <>
          {step === 1 ? (
            <>
              <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isPending}>
                {t(($) => $.distributionZones.drawer.cancel)}
              </Button>
              <Button
                onClick={handleNext}
                disabled={isPending || showGeneralSkeleton}
              >
                {t(($) => $.distributionZones.drawer.next)}
                <ChevronRight className="ms-1 size-4 rtl:rotate-180" />
              </Button>
            </>
          ) : (
            <>
              <Button
                variant="outline"
                onClick={() => setStep(1)}
                disabled={isPending}
              >
                <ChevronLeft className="me-1 size-4 rtl:rotate-180" />
                {t(($) => $.distributionZones.drawer.back)}
              </Button>
              <Button onClick={handleSubmit} disabled={isPending || loadingAreas}>
                {isPending && <Loader2 className="me-2 size-4 animate-spin" />}
                {isEdit ? t(($) => $.distributionZones.drawer.saveChanges) : t(($) => $.distributionZones.drawer.createZone)}
              </Button>
            </>
          )}
        </>
      }
    >
      <div className="flex h-full flex-col">
        {/* Wizard step header */}
        <WizardStepHeader step={step} />

        {/* Server error */}
        {serverError && (
          <Alert variant="destructive" className="mb-4 shrink-0">
            <AlertDescription>{serverError}</AlertDescription>
          </Alert>
        )}

        {/* ── Step 1: General Information ─────────────────────────────────── */}
        {step === 1 && (
          <div className="flex-1 overflow-y-auto space-y-4">
            {showGeneralSkeleton ? (
              <GeneralSkeleton />
            ) : (
              <>
                {/* Zone Code (read-only) + Active toggle */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <Label>{t(($) => $.distributionZones.drawer.zoneCode)}</Label>
                    <div className="flex h-9 items-center rounded-md border bg-muted px-3 font-mono text-sm tracking-widest text-muted-foreground">
                      {zoneCodeDisplay}
                    </div>
                    {!isEdit && (
                      <p className="text-xs text-muted-foreground">{t(($) => $.distributionZones.drawer.autoGenerated)}</p>
                    )}
                  </div>

                  <div className="flex items-end pb-0.5">
                    <div className="flex items-center gap-3">
                      <Switch
                        id="dz-active"
                        checked={form.is_active}
                        onCheckedChange={(v) => set('is_active', v)}
                        disabled={isPending}
                      />
                      <Label htmlFor="dz-active" className="cursor-pointer">
                        {form.is_active ? t(($) => $.distributionZones.drawer.active) : t(($) => $.distributionZones.drawer.inactive)}
                      </Label>
                    </div>
                  </div>
                </div>

                {/* Arabic Name */}
                <div className="space-y-1.5">
                  <Label htmlFor="dz-name-ar">
                    {t(($) => $.distributionZones.drawer.nameAr)} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="dz-name-ar"
                    value={form.name_ar}
                    onChange={(e) => set('name_ar', e.target.value)}
                    placeholder={t(($) => $.distributionZones.drawer.nameArPlaceholder)}
                    dir="rtl"
                    maxLength={100}
                    disabled={isPending}
                  />
                  {errors.name_ar && (
                    <p className="text-xs text-destructive">{errors.name_ar[0]}</p>
                  )}
                </div>

                {/* English Name */}
                <div className="space-y-1.5">
                  <Label htmlFor="dz-name-en">{t(($) => $.distributionZones.drawer.nameEn)}</Label>
                  <Input
                    id="dz-name-en"
                    value={form.name_en}
                    onChange={(e) => set('name_en', e.target.value)}
                    placeholder={t(($) => $.distributionZones.drawer.nameEnPlaceholder)}
                    maxLength={100}
                    disabled={isPending}
                  />
                </div>

                {/* Description */}
                <div className="space-y-1.5">
                  <Label htmlFor="dz-desc">{t(($) => $.distributionZones.drawer.description)}</Label>
                  <Textarea
                    id="dz-desc"
                    value={form.description}
                    onChange={(e) => set('description', e.target.value)}
                    placeholder={t(($) => $.distributionZones.drawer.descriptionPlaceholder)}
                    rows={3}
                    maxLength={1000}
                    disabled={isPending}
                  />
                </div>

                {/* Color */}
                <div className="space-y-1.5">
                  <Label>
                    {t(($) => $.distributionZones.drawer.zoneColor)}
                    <span className="ms-1.5 text-xs font-normal text-muted-foreground">
                      {t(($) => $.distributionZones.drawer.optional)}
                    </span>
                  </Label>
                  <ColorPicker
                    value={form.color}
                    onChange={(hex) => set('color', hex)}
                    disabled={isPending}
                  />
                  {form.color && (
                    <p className="text-xs text-muted-foreground">
                      {t(($) => $.distributionZones.drawer.selectedColorLabel)}{' '}
                      <span className="font-mono">{form.color}</span>
                    </p>
                  )}
                </div>
              </>
            )}
          </div>
        )}

        {/* ── Step 2: Area Assignment ──────────────────────────────────────── */}
        {step === 2 && (
          <div className="flex flex-1 flex-col overflow-y-auto">
            {errors.area_ids && (
              <Alert variant="destructive" className="mb-3 shrink-0">
                <AlertDescription>{errors.area_ids[0]}</AlertDescription>
              </Alert>
            )}

            {form.area_ids.length === 0 && !loadingAreas && (
              <div className="mb-3 flex shrink-0 items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-400">
                <Network className="mt-0.5 size-3.5 shrink-0" />
                <span>
                  {t(($) => $.distributionZones.drawer.noAreasAssigned)}
                </span>
              </div>
            )}

            {form.area_ids.length > 0 && (
              <div className="mb-3 flex shrink-0 items-center gap-2">
                <Badge variant="secondary">
                  {t(($) => $.distributionZones.drawer.areasSelected, { count: form.area_ids.length })}
                </Badge>
                <p className="text-xs text-muted-foreground">
                  {t(($) => $.distributionZones.drawer.amberHintSelected)}
                </p>
              </div>
            )}

            {!form.area_ids.length && !loadingAreas && (
              <p className="mb-3 shrink-0 text-xs text-muted-foreground">
                {t(($) => $.distributionZones.drawer.amberHintEmpty)}
              </p>
            )}

            <AreaSelector
              groups={areasData?.data ?? []}
              assignedIds={form.area_ids}
              currentZoneId={isEdit ? editZone?.id : null}
              currentZoneName={form.name_ar || t(($) => $.distributionZones.drawer.thisZoneFallback)}
              isLoading={loadingAreas}
              disabled={isPending}
              totalAreasCount={totalAreasCount}
              assignedAreasCount={assignedAreasCount}
              onChange={(ids, forceMoved) => {
                set('area_ids', ids);
                if (forceMoved) set('force_move', true);
              }}
            />
          </div>
        )}
      </div>
    </PageDrawer>
  );
}
