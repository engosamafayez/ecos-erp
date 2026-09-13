import { useEffect, useRef } from 'react';
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

// Arabic Unicode block (U+0600–U+06FF) → Latin equivalents for slug generation
const ARABIC_MAP: Record<string, string> = {
  'ا': 'a', 'أ': 'a', 'إ': 'i', 'آ': 'aa', 'ء': '',
  'ؤ': 'w', 'ئ': 'y',
  'ب': 'b', 'ت': 't', 'ث': 'th', 'ج': 'j', 'ح': 'h',
  'خ': 'kh', 'د': 'd', 'ذ': 'dh', 'ر': 'r', 'ز': 'z',
  'س': 's', 'ش': 'sh', 'ص': 's', 'ض': 'd', 'ط': 't',
  'ظ': 'z', 'ع': 'a', 'غ': 'gh', 'ف': 'f', 'ق': 'q',
  'ك': 'k', 'ل': 'l', 'م': 'm', 'ن': 'n', 'ه': 'h',
  'و': 'w', 'ي': 'y', 'ى': 'a', 'ة': 'a',
  // Tashkeel (diacritics) — strip entirely
  'ً': '', 'ٌ': '', 'ٍ': '', 'َ': '', 'ُ': '',
  'ِ': '', 'ّ': '', 'ْ': '', 'ٰ': '', 'ـ': '',
};

export function toSlug(value: string): string {
  let text = value.toLowerCase().trim();
  // Transliterate Arabic characters before the ASCII-only filter
  text = text.replace(/[؀-ۿ]/g, (ch) => ARABIC_MAP[ch] ?? '');
  // Strip Latin diacritics (é → e, ü → u, etc.)
  text = text.normalize('NFD').replace(/[̀-ͯ]/g, '');
  return text
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '');
}

export function BrandFormFields({ mode, existingLogoUrl, onImageChange }: BrandFormFieldsProps) {
  const { register, control, setValue } = useFormContext<BrandCreateFormValues & BrandUpdateFormValues>();

  const nameValue = useWatch({ control, name: 'name' });
  const codeValue = useWatch({ control, name: 'code' });

  // Whether the user has taken manual control of the slug field. Once true, the
  // name→slug auto-generation below stops overwriting their value; clearing the
  // field hands control back to auto-generation. In EDIT mode this starts true so
  // an existing slug is NEVER regenerated from a changed name — it may already be
  // externally referenced (mirrors UpdateBrandAction, which preserves the stored
  // slug when none is explicitly supplied).
  const slugManuallyEdited = useRef(mode === 'edit');

  // CREATE only: keep the slug in sync with the full Brand Name until the user
  // customises it. Deriving from the whole current `nameValue` (not one keystroke)
  // is the fix for the previous "Aseel → a" freeze, where a stale comparison
  // treated every post-first-keystroke state as a manual edit and stopped updating.
  // Falls back to the code-derived slug when the name has no Latin-mappable
  // characters (the backend applies the same code fallback as the authority).
  useEffect(() => {
    if (mode !== 'create' || slugManuallyEdited.current) return;
    const next = toSlug(nameValue ?? '') || toSlug(codeValue ?? '');
    setValue('slug', next, { shouldValidate: false });
  }, [mode, nameValue, codeValue, setValue]);

  return (
    <div className="flex flex-col gap-4">
      {mode === 'create' ? (
        <FormField name="company_id" label="Company" required>
          <Controller
            control={control}
            name="company_id"
            render={({ field }) => (
              <CompanySelect value={field.value || null} onChange={field.onChange} />
            )}
          />
        </FormField>
      ) : (
        <FormField
          name="company_id"
          label="Company"
          description="Changing the company initiates an ownership transfer."
        >
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
        <FormField
          name="slug"
          label="Slug"
          description={
            mode === 'create'
              ? 'Auto-generated from the name — optional to customise'
              : 'Leave unchanged to keep the current slug'
          }
        >
          <Input
            placeholder="acme-food-brands"
            {...register('slug', {
              // A keystroke here means the user is customising the slug, so
              // name-based auto-generation must stop overwriting it. Emptying the
              // field hands control back to auto-generation (CREATE), and submits
              // fine either way — a blank slug is generated by the backend.
              onChange: (e) => {
                slugManuallyEdited.current = e.target.value.trim() !== '';
              },
            })}
          />
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
            label="Minimum Margin %"
            description="Managed in Configuration OS — changes here update the policy immediately"
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
