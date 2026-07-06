import { useEffect } from 'react';
import { Controller, useFormContext, useWatch } from 'react-hook-form';

import { FormField } from '@/components/crud';
import { ImageUploadField } from '@/components/ui/image-upload-field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { CompanySelect } from '@/features/branches/components/company-select';
import type { BrandCreateFormValues, BrandUpdateFormValues } from './brand-form-schema';

type BrandFormFieldsProps = {
  mode: 'create' | 'edit';
  existingLogoUrl?: string | null;
  onImageChange?: (file: File | null) => void;
};

function toSlug(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-');
}

export function BrandFormFields({ mode, existingLogoUrl, onImageChange }: BrandFormFieldsProps) {
  const { register, control, setValue, getValues } = useFormContext<BrandCreateFormValues & BrandUpdateFormValues>();

  const nameValue = useWatch({ control, name: 'name' });

  // Auto-populate slug from name while slug hasn't been manually customised
  useEffect(() => {
    if (!nameValue) return;
    const auto = toSlug(nameValue);
    // Only auto-fill if slug is empty or matches the previous auto-slug
    const current = getValues('slug') ?? '';
    if (!current || current === toSlug(getValues('name') ?? '')) {
      setValue('slug', auto, { shouldValidate: false });
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [nameValue]);

  return (
    <div className="flex flex-col gap-4">
      {mode === 'create' && (
        <FormField name="company_id" label="Company" required>
          <Controller
            control={control}
            name="company_id"
            render={({ field }) => (
              <CompanySelect value={field.value || null} onChange={field.onChange} />
            )}
          />
        </FormField>
      )}

      <FormField name="name" label="Brand Name" required>
        <Input placeholder="e.g. Acme Food Brands" {...register('name')} />
      </FormField>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label="Code" description="Leave blank to auto-generate (BRD-000001)">
          <Input placeholder="BRD-000001" {...register('code')} />
        </FormField>
        <FormField name="slug" label="Slug" description="Auto-derived from name — edit to customise">
          <Input placeholder="acme-food-brands" {...register('slug')} />
        </FormField>
      </div>

      <FormField name="logo" label="Brand Logo">
        <ImageUploadField existingUrl={existingLogoUrl ?? null} onChange={onImageChange ?? (() => {})} />
      </FormField>

      <FormField name="description" label="Description">
        <Textarea
          placeholder="Brief description of this brand…"
          rows={3}
          {...register('description')}
        />
      </FormField>

      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
        Active
      </label>

      <div className="border-t pt-4">
        <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide mb-3">Pricing Defaults</p>
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField
            name="default_target_margin"
            label="Target Margin %"
            description="Applied to all brand products unless overridden"
          >
            <Input
              type="number" min="0" max="99.99" step="0.01"
              placeholder="e.g. 30"
              {...register('default_target_margin')}
            />
          </FormField>
          <FormField
            name="default_markup"
            label="Markup %"
            description="Derived automatically — set margin instead"
          >
            <Input
              type="number" min="0" step="0.01"
              placeholder="e.g. 42.86"
              {...register('default_markup')}
            />
          </FormField>
          <FormField
            name="default_discount_pct"
            label="Default Discount %"
            description="Sale price = regular × (1 − discount)"
          >
            <Input
              type="number" min="0" max="99.99" step="0.01"
              placeholder="e.g. 10"
              {...register('default_discount_pct')}
            />
          </FormField>
        </div>
      </div>
    </div>
  );
}
