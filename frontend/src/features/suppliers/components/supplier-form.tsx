import { useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';

import { FormField } from '@/components/crud';
import { Input } from '@/components/ui/input';
import type { SupplierFormValues } from '@/features/suppliers/components/supplier-form-schema';
import { SupplierCategorySelect } from '@/features/suppliers/components/supplier-category-select';
import { SupplierRawMaterialsSelect } from '@/features/suppliers/components/supplier-raw-materials-select';
import { SupplierProductCategoriesSelect } from '@/features/suppliers/components/supplier-product-categories-select';
import type { Supplier } from '@/features/suppliers/types/supplier';

type SupplierFormFieldsProps = {
  /** The Supplier being edited (undefined on create) — used only to preload
   *  Supply Capability chip labels before the user has searched for them. */
  supplier?: Supplier | null;
};

export function SupplierFormFields({ supplier }: SupplierFormFieldsProps = {}) {
  const { t } = useTranslation('suppliers');
  const { register, watch, setValue } = useFormContext<SupplierFormValues>();
  const code = watch('code');
  const categoryId = watch('supplier_category_id');
  const rawMaterialIds = watch('raw_material_ids');
  const productCategoryIds = watch('product_category_ids');

  return (
    <div className="flex flex-col gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField name="code" label={t($ => $.form.code.label)}>
          {/* Backend-owned (SupplierCodeGeneratorService) — never editable here; an
              ordinary edit must never regenerate it (TASK-...-MASTER-DATA-002 §6). */}
          <Input
            value={code || t($ => $.form.code.autoPlaceholder)}
            disabled
            className="font-mono text-muted-foreground"
          />
        </FormField>
        <FormField name="name" label={t($ => $.form.name.label)} required>
          <Input placeholder={t($ => $.form.name.placeholder)} {...register('name')} />
        </FormField>
        <FormField name="supplier_category_id" label={t($ => $.wizard.fields.category)}>
          <SupplierCategorySelect
            value={categoryId ?? null}
            onChange={(v) => setValue('supplier_category_id', v)}
          />
        </FormField>
        <FormField name="contact_person" label={t($ => $.form.contactPerson)}>
          <Input {...register('contact_person')} />
        </FormField>
        <FormField name="email" label={t($ => $.form.email.label)}>
          <Input type="email" placeholder={t($ => $.form.email.placeholder)} {...register('email')} />
        </FormField>
        <FormField name="phone" label={t($ => $.form.phone)}>
          <Input {...register('phone')} />
        </FormField>
        <FormField name="mobile" label={t($ => $.form.mobile)}>
          <Input {...register('mobile')} />
        </FormField>
      </div>

      {/* Supply Capabilities (TASK-...-SUPPLY-CAPABILITIES-003) */}
      <div className="border-border/60 border-t pt-4">
        <h4 className="text-muted-foreground mb-3 text-xs font-semibold uppercase tracking-wide">
          {t($ => $.capabilities.sectionTitle)}
        </h4>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField name="raw_material_ids" label={t($ => $.capabilities.rawMaterials.label)}>
            <SupplierRawMaterialsSelect
              value={rawMaterialIds}
              onChange={(ids) => setValue('raw_material_ids', ids)}
              preloaded={supplier?.raw_materials}
            />
          </FormField>
          <FormField name="product_category_ids" label={t($ => $.capabilities.productCategories.label)}>
            <SupplierProductCategoriesSelect
              value={productCategoryIds}
              onChange={(ids) => setValue('product_category_ids', ids)}
              preloaded={supplier?.product_categories}
            />
          </FormField>
        </div>
      </div>

      {/* Location (Part 1) */}
      <div className="border-border/60 border-t pt-4">
        <h4 className="text-muted-foreground mb-3 text-xs font-semibold uppercase tracking-wide">
          {t($ => $.form.sectionLocation)}
        </h4>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField name="country" label={t($ => $.form.country)}>
            <Input {...register('country')} />
          </FormField>
          <FormField name="state" label={t($ => $.form.state)}>
            <Input {...register('state')} />
          </FormField>
          <FormField name="city" label={t($ => $.form.city)}>
            <Input {...register('city')} />
          </FormField>
          <FormField name="district" label={t($ => $.form.district)}>
            <Input {...register('district')} />
          </FormField>
          <div className="sm:col-span-2">
            <FormField name="address" label={t($ => $.form.address)}>
              <Input {...register('address')} />
            </FormField>
          </div>
          <div className="sm:col-span-2">
            <FormField name="google_maps_url" label={t($ => $.form.googleMapsUrl)}>
              <Input type="url" placeholder="https://maps.google.com/…" {...register('google_maps_url')} />
            </FormField>
          </div>
        </div>
      </div>

      {/* Opening balance — REALIGNMENT-001 §7 / §18.
          These inputs are GONE from supplier CRUD. They were the real cause of the "my edit
          didn't save" report: the form posted opening_balance_amount/type, the request validated
          them, and SupplierDTO silently dropped them — so the user got a success toast and no
          change. They are not re-added here, because writing them onto the supplier row would
          create a SECOND opening balance that double-counts against the certified ledger
          (TASK-PROC-SUPPLIER-OPENING-BALANCE-001). Opening balance is a Finance posting and is
          entered from Supplier 360, which routes it through SupplierOpeningBalanceService. */}
      <div className="border-border/60 border-t pt-4">
        <h4 className="text-muted-foreground mb-1 text-xs font-semibold uppercase tracking-wide">
          {t($ => $.form.sectionFinancial)}
        </h4>
        <p className="text-muted-foreground text-xs">{t($ => $.form.openingBalanceMovedHint)}</p>
      </div>

      <div className="border-border/60 border-t pt-4">
        <FormField name="notes" label={t($ => $.form.notes.label)}>
          <Input placeholder={t($ => $.form.notes.placeholder)} {...register('notes')} />
        </FormField>
        <label className="mt-4 flex items-center gap-2 text-sm">
          <input type="checkbox" className="border-input size-4 rounded" {...register('is_active')} />
          {t($ => $.form.active)}
        </label>
      </div>
    </div>
  );
}
