import { useEffect, useRef, useState } from 'react';
import { Controller, useFieldArray, useForm, type UseFormReturn } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import axios from 'axios';
import {
  ChevronDown,
  ChevronRight,
  FileText,
  Plus,
  Star,
  Trash2,
  X,
} from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';

import { EntityDrawer, EntityForm, FormField } from '@/components/crud';
import { Combobox } from '@/components/crud/combobox';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { UnitSelect } from '@/features/products/components/unit-select';
import { RawMaterialCategorySelect } from '@/features/raw-materials/components/raw-material-category-select';
import { useSuppliersQuery } from '@/features/suppliers/hooks/use-suppliers';
import { useWarehousesQuery } from '@/features/warehouses/hooks/use-warehouses';
import { useCreateCategory } from '@/features/categories/hooks/use-categories';
import {
  useCreateRawMaterial,
  useNextMaterialSku,
  useUpdateRawMaterial,
} from '@/features/raw-materials/hooks/use-raw-materials';
import type { MaterialType, RawMaterial, RawMaterialPayload, SupplierRow } from '@/features/raw-materials/types';
import { useCompany } from '@/features/organization/context/company-context';
import { formatMoney } from '@/lib/format';
import { ImageUploadField } from '@/components/ui/image-upload-field';
import { uploadMaterialImage } from '@/lib/media-upload';
import { cn } from '@/lib/utils';

// ─── Schema ───────────────────────────────────────────────────────────────────

const supplierRowSchema = z.object({
  supplier_id:        z.string().min(1, 'Select a supplier'),
  supplier_sku:       z.string().optional(),
  lead_time_days:     z.number().int().min(0).nullable().optional(),
  minimum_order_qty:  z.number().min(0).nullable().optional(),
  last_purchase_cost: z.number().min(0).nullable().optional(),
  is_active:          z.boolean(),
  is_default:         z.boolean(),
});

const schema = z.object({
  // S1 Basic
  material_type: z.enum(['raw_material', 'packaging_material']),
  name:        z.string().min(1, 'Name is required'),
  sku:         z.string().min(1, 'SKU is required'),
  category_id: z.string().min(1, 'Category is required'),
  unit_id:     z.string().min(1, 'Unit of measure is required'),
  description: z.string().optional(),
  // S2 Inventory
  allow_negative_stock:   z.boolean(),
  minimum_stock:          z.number().min(0).nullable().optional(),
  reorder_point:          z.number().min(0).nullable().optional(),
  preferred_warehouse_id: z.string().optional(),
  // S3 Suppliers
  suppliers: z.array(supplierRowSchema),
  // S4 Cost
  manual_cost: z.number().min(0).nullable().optional(),
  // S5 Purchasing
  purchasing_supplier_id:       z.string().optional(),
  purchasing_lead_time_days:    z.number().int().min(0).nullable().optional(),
  purchasing_minimum_order_qty: z.number().min(0).nullable().optional(),
  // S7 Notes
  internal_notes: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

const FORM_ID = 'rm-enterprise-form';

const numVal = (v: unknown) =>
  v === '' || v == null ? null : Number(v);

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmtPrice(n: number | null | undefined, currency = 'EGP', locale = 'en-US') {
  if (n == null) return '—';
  return formatMoney(n, currency, locale);
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
  } catch {
    return '—';
  }
}

function costSourceLabel(source: string | null | undefined): string {
  if (source === 'manual') return 'Manual';
  if (source === 'purchase') return 'Purchase Invoice';
  return '—';
}

function extractError(err: unknown): string {
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as Record<string, unknown> | undefined;
    if (typeof data?.message === 'string') return data.message;
    const errors = data?.errors as Record<string, string[]> | undefined;
    if (errors) return Object.values(errors).flat().join(' ');
  }
  return 'An error occurred. Please try again.';
}

function toFormValues(m?: RawMaterial | null): FormValues {
  return {
    material_type: (m?.product_type as MaterialType) ?? 'raw_material',
    name:        m?.name        ?? '',
    sku:         m?.sku         ?? '',
    category_id: m?.category_id ?? '',
    unit_id:     m?.unit_id     ?? '',
    description: m?.description ?? '',

    allow_negative_stock:   m?.allow_negative_stock  ?? false,
    minimum_stock:          m?.minimum_stock          ?? null,
    reorder_point:          m?.reorder_point          ?? null,
    preferred_warehouse_id: m?.preferred_warehouse_id ?? '',

    suppliers: (m?.suppliers ?? []).map((s) => ({
      supplier_id:        s.supplier_id,
      supplier_sku:       s.supplier_sku       ?? '',
      lead_time_days:     s.lead_time_days     ?? null,
      minimum_order_qty:  s.minimum_order_qty  ?? null,
      last_purchase_cost: s.last_purchase_cost ?? null,
      is_active:          s.is_active,
      is_default:         s.is_default,
    })),

    manual_cost: m?.manual_cost ?? null,

    purchasing_supplier_id:       m?.purchasing_supplier_id       ?? '',
    purchasing_lead_time_days:    m?.purchasing_lead_time_days    ?? null,
    purchasing_minimum_order_qty: m?.purchasing_minimum_order_qty ?? null,

    internal_notes: m?.internal_notes ?? '',
  };
}

// ─── SectionBlock ─────────────────────────────────────────────────────────────

function SectionBlock({
  title,
  count,
  defaultOpen = false,
  children,
}: {
  title:        string;
  count?:       number;
  defaultOpen?: boolean;
  children:     React.ReactNode;
}) {
  const [open, setOpen] = useState(defaultOpen);
  return (
    <div className="rounded-lg border overflow-hidden">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="w-full flex items-center justify-between px-4 py-3 bg-muted/40 hover:bg-muted/60 transition-colors"
      >
        <div className="flex items-center gap-2">
          <span className="text-sm font-semibold">{title}</span>
          {count != null && (
            <Badge variant="secondary" className="text-xs h-5 px-1.5">{count}</Badge>
          )}
        </div>
        {open
          ? <ChevronDown className="size-4 text-muted-foreground" />
          : <ChevronRight className="size-4 text-muted-foreground" />}
      </button>
      {open && <div className="p-4 space-y-4 border-t">{children}</div>}
    </div>
  );
}

// ─── AttachmentSection ────────────────────────────────────────────────────────

type AttachmentEntry = {
  id:   string;
  name: string;
  file: File;
  type: 'technical_sheet' | 'safety_sheet' | 'image';
};

const ATTACH_TYPES = [
  { type: 'technical_sheet' as const, label: 'Technical Sheet', accept: '.pdf',    multiple: false },
  { type: 'safety_sheet'    as const, label: 'Safety Sheet',    accept: '.pdf',    multiple: false },
  { type: 'image'           as const, label: 'Image',           accept: 'image/*', multiple: true  },
];

const TYPE_LABEL: Record<AttachmentEntry['type'], string> = {
  technical_sheet: 'Technical Sheet',
  safety_sheet:    'Safety Sheet',
  image:           'Image',
};

function AttachmentSection({
  entries,
  onChange,
}: {
  entries:  AttachmentEntry[];
  onChange: (entries: AttachmentEntry[]) => void;
}) {
  const refs = useRef<Record<string, HTMLInputElement | null>>({});

  function addFiles(type: AttachmentEntry['type'], files: FileList | null) {
    if (!files?.length) return;
    const next = Array.from(files).map((f) => ({
      id: Math.random().toString(36).slice(2),
      name: f.name,
      file: f,
      type,
    }));
    onChange([...entries, ...next]);
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        {ATTACH_TYPES.map(({ type, label, accept, multiple }) => (
          <div key={type}>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={() => refs.current[type]?.click()}
            >
              <FileText className="size-3.5" />
              Add {label}
            </Button>
            <input
              ref={(el) => { refs.current[type] = el; }}
              type="file"
              accept={accept}
              multiple={multiple}
              className="sr-only"
              onChange={(e) => { addFiles(type, e.target.files); e.target.value = ''; }}
            />
          </div>
        ))}
      </div>

      {entries.length > 0 ? (
        <div className="rounded-md border divide-y overflow-hidden">
          {entries.map((entry) => (
            <div key={entry.id} className="flex items-center gap-2 px-3 py-2">
              <FileText className="size-4 text-muted-foreground shrink-0" />
              <span className="text-sm flex-1 truncate min-w-0">{entry.name}</span>
              <Badge variant="outline" className="text-xs shrink-0">{TYPE_LABEL[entry.type]}</Badge>
              <button
                type="button"
                onClick={() => onChange(entries.filter((e) => e.id !== entry.id))}
                className="shrink-0 text-muted-foreground hover:text-destructive transition-colors"
              >
                <X className="size-4" />
              </button>
            </div>
          ))}
        </div>
      ) : (
        <p className="text-sm text-muted-foreground">No attachments yet.</p>
      )}
    </div>
  );
}

// ─── CreateCategoryDialog ─────────────────────────────────────────────────────

function CreateCategoryDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open:        boolean;
  onOpenChange:(open: boolean) => void;
  onCreated:   (id: string) => void;
}) {
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [err,  setErr]  = useState('');
  const createCategory  = useCreateCategory();
  const qc              = useQueryClient();

  useEffect(() => {
    if (open) { setName(''); setCode(''); setErr(''); }
  }, [open]);

  useEffect(() => {
    setCode(
      name
        .toUpperCase()
        .replace(/[^A-Z0-9\s]/g, '')
        .trim()
        .replace(/\s+/g, '-')
        .slice(0, 20),
    );
  }, [name]);

  async function handleCreate() {
    if (!name.trim() || !code.trim()) {
      setErr('Name and code are required.');
      return;
    }
    try {
      const cat = await createCategory.mutateAsync({
        name:       name.trim(),
        code:       code.trim(),
        sort_order: 0,
        is_active:  true,
        category_scope: 'material',
      });
      qc.invalidateQueries({ queryKey: ['rm-category-options'] });
      onCreated(cat.id);
      onOpenChange(false);
    } catch (e) {
      setErr(extractError(e));
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>New Raw Material Category</DialogTitle>
        </DialogHeader>

        <div className="space-y-3 py-2">
          {err && (
            <Alert variant="destructive">
              <AlertDescription>{err}</AlertDescription>
            </Alert>
          )}
          <div className="space-y-1.5">
            <Label>Name</Label>
            <Input
              placeholder="e.g. Wheat Flour & Starch"
              value={name}
              onChange={(e) => setName(e.target.value)}
              autoFocus
            />
          </div>
          <div className="space-y-1.5">
            <Label>Code</Label>
            <Input
              placeholder="FLOUR-STARCHES"
              value={code}
              onChange={(e) => setCode(e.target.value.toUpperCase())}
              className="font-mono"
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
          <Button onClick={handleCreate} disabled={createCategory.isPending}>
            {createCategory.isPending ? 'Creating…' : 'Create Category'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ─── SupplierTableSection ─────────────────────────────────────────────────────

function SupplierTableSection({
  form,
  supplierOptions,
}: {
  form:            UseFormReturn<FormValues>;
  supplierOptions: Array<{ value: string; label: string }>;
}) {
  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'suppliers' });

  useEffect(() => {
    if (fields.length === 1) {
      form.setValue('suppliers.0.is_default', true);
    }
  }, [fields.length, form]);

  function setDefault(index: number) {
    fields.forEach((_, i) => form.setValue(`suppliers.${i}.is_default`, i === index));
  }

  function addRow() {
    append({
      supplier_id:        '',
      minimum_order_qty:  null,
      last_purchase_cost: null,
      is_active:          true,
      is_default:         fields.length === 0,
    });
  }

  const suppliersError =
    (form.formState.errors.suppliers as { message?: string } | undefined)?.message;

  return (
    <div className="space-y-3">
      {fields.length === 0 ? (
        <p className="text-sm text-muted-foreground">No suppliers yet.</p>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <table className="w-full text-sm min-w-[460px]">
            <thead className="bg-muted/50 border-b">
              <tr>
                <th className="px-3 py-2 text-start text-xs font-medium text-muted-foreground uppercase tracking-wide w-14">Default</th>
                <th className="px-3 py-2 text-start text-xs font-medium text-muted-foreground uppercase tracking-wide">Supplier</th>
                <th className="px-3 py-2 text-start text-xs font-medium text-muted-foreground uppercase tracking-wide w-24">Min Qty</th>
                <th className="px-3 py-2 text-start text-xs font-medium text-muted-foreground uppercase tracking-wide w-28">Last Cost</th>
                <th className="px-3 py-2 text-center text-xs font-medium text-muted-foreground uppercase tracking-wide w-16">Active</th>
                <th className="w-9 px-2 py-2" />
              </tr>
            </thead>
            <tbody className="divide-y">
              {fields.map((field, i) => {
                const isDefault = form.watch(`suppliers.${i}.is_default`);
                const isActive  = form.watch(`suppliers.${i}.is_active`);

                return (
                  <tr key={field.id} className={cn('transition-colors', !isActive && 'opacity-60')}>
                    <td className="px-3 py-2 text-center">
                      <button
                        type="button"
                        title="Set as default supplier"
                        onClick={() => setDefault(i)}
                        className={cn(
                          'inline-flex items-center justify-center size-7 rounded-md transition-colors',
                          isDefault ? 'text-amber-500' : 'text-muted-foreground hover:text-amber-400',
                        )}
                      >
                        <Star className={cn('size-4', isDefault && 'fill-current')} />
                      </button>
                    </td>

                    <td className="px-3 py-2">
                      <Controller
                        control={form.control}
                        name={`suppliers.${i}.supplier_id`}
                        render={({ field: f, fieldState }) => (
                          <div>
                            <Combobox
                              options={supplierOptions}
                              value={f.value || null}
                              onChange={f.onChange}
                              placeholder="Select…"
                            />
                            {fieldState.error?.message && (
                              <p className="text-destructive text-xs mt-0.5">{fieldState.error.message}</p>
                            )}
                          </div>
                        )}
                      />
                    </td>

                    <td className="px-3 py-2">
                      <Input
                        type="number" min="0" className="h-8 text-sm" placeholder="0"
                        {...form.register(`suppliers.${i}.minimum_order_qty`, { setValueAs: numVal })}
                      />
                    </td>

                    <td className="px-3 py-2">
                      <Input
                        type="number" step="0.01" min="0" className="h-8 text-sm" placeholder="0.00"
                        {...form.register(`suppliers.${i}.last_purchase_cost`, { setValueAs: numVal })}
                      />
                    </td>

                    <td className="px-3 py-2 text-center">
                      <Controller
                        control={form.control}
                        name={`suppliers.${i}.is_active`}
                        render={({ field: f }) => (
                          <Switch checked={f.value} onCheckedChange={f.onChange} />
                        )}
                      />
                    </td>

                    <td className="px-2 py-2">
                      <button
                        type="button"
                        onClick={() => remove(i)}
                        className="text-muted-foreground hover:text-destructive transition-colors"
                        title="Delete"
                      >
                        <Trash2 className="size-4" />
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {suppliersError && <p className="text-destructive text-sm">{suppliersError}</p>}

      <Button type="button" variant="outline" size="sm" className="gap-1.5" onClick={addRow}>
        <Plus className="size-4" />
        Add Supplier
      </Button>
    </div>
  );
}

// ─── Main Drawer ──────────────────────────────────────────────────────────────

type Props = {
  open:         boolean;
  onOpenChange: (open: boolean) => void;
  material?:    RawMaterial | null;
};

export function RawMaterialFormDrawer({ open, onOpenChange, material }: Props) {
  const { currency, locale } = useCompany();
  const isEdit    = Boolean(material);
  const create    = useCreateRawMaterial();
  const update    = useUpdateRawMaterial();
  const isPending = create.isPending || update.isPending;
  const [err, setErr] = useState<string | null>(null);

  const [imageFile,          setImageFile]          = useState<File | null>(null);
  const [attachments,        setAttachments]        = useState<AttachmentEntry[]>([]);
  const [createCategoryOpen, setCreateCategoryOpen] = useState(false);

  const { data: suppliersResult  } = useSuppliersQuery({ status: 'active', per_page: 200 });
  const { data: warehousesResult } = useWarehousesQuery({ per_page: 200 });
  const { data: nextSku }          = useNextMaterialSku();

  const supplierOptions  = (suppliersResult?.items  ?? []).map((s) => ({ value: s.id, label: s.name }));
  const warehouseOptions = (warehousesResult?.items ?? []).map((w) => ({ value: w.id, label: w.name }));

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: toFormValues(material),
  });

  useEffect(() => {
    if (open) {
      const defaults = toFormValues(material);
      // Auto-populate SKU for new materials when field is still blank
      if (!material && nextSku && !defaults.sku) {
        defaults.sku = nextSku;
      }
      form.reset(defaults);
      setImageFile(null);
      setAttachments([]);
      setErr(null);
    }
  }, [open, material, form, nextSku]);

  function validateSuppliers(suppliers: SupplierRow[]): string | null {
    if (suppliers.length === 0) return null;
    const n = suppliers.filter((s) => s.is_default).length;
    if (n === 0) return 'At least one supplier must be set as default (click ★).';
    if (n > 1)   return 'Only one supplier can be set as default.';
    return null;
  }

  async function onSubmit(values: FormValues) {
    const supErr = validateSuppliers(values.suppliers);
    if (supErr) { setErr(supErr); return; }
    setErr(null);

    let imageUrl: string | null = material?.image_url ?? null;
    if (imageFile) {
      try {
        const context = values.material_type === 'packaging_material' ? 'packaging-materials' : 'raw-materials';
        const uploaded = await uploadMaterialImage(imageFile, context);
        imageUrl = uploaded.path;
      } catch {
        setErr('Image upload failed. Please try again.');
        return;
      }
    }

    const payload: RawMaterialPayload = {
      sku:          values.sku,
      name:         values.name,
      category_id:  values.category_id,
      unit_id:      values.unit_id,
      product_type: values.material_type,
      cost_source:  'purchase',
      description:  values.description || undefined,
      sale_price:   null,
      image_url:    imageUrl,

      allow_negative_stock:   values.allow_negative_stock,
      minimum_stock:          values.minimum_stock          ?? null,
      reorder_point:          values.reorder_point          ?? null,
      preferred_warehouse_id: values.preferred_warehouse_id || null,

      suppliers: values.suppliers.map((s) => ({
        supplier_id:        s.supplier_id,
        supplier_sku:       s.supplier_sku       || null,
        lead_time_days:     s.lead_time_days     ?? null,
        minimum_order_qty:  s.minimum_order_qty  ?? null,
        last_purchase_cost: s.last_purchase_cost ?? null,
        is_active:          s.is_active,
        is_default:         s.is_default,
      })),

      manual_cost: values.manual_cost ?? null,

      purchasing_supplier_id:       values.purchasing_supplier_id       || null,
      purchasing_lead_time_days:    values.purchasing_lead_time_days    ?? null,
      purchasing_minimum_order_qty: values.purchasing_minimum_order_qty ?? null,

      internal_notes: values.internal_notes || null,
    };

    try {
      if (isEdit && material) {
        await update.mutateAsync({ id: material.id, payload });
      } else {
        await create.mutateAsync(payload);
      }
      onOpenChange(false);
    } catch (e) {
      setErr(extractError(e));
    }
  }

  const supplierCount = form.watch('suppliers').length;

  return (
    <>
      <EntityDrawer
        open={open}
        onOpenChange={onOpenChange}
        title={isEdit ? 'Edit Raw Material' : 'New Raw Material'}
        description={
          isEdit
            ? 'Update procurement, inventory, and cost details.'
            : 'Add a new raw material for manufacturing and procurement.'
        }
        className="sm:max-w-2xl"
        footer={
          <>
            <Button variant="outline" type="button" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" form={FORM_ID} disabled={isPending}>
              {isPending
                ? (isEdit ? 'Saving…' : 'Creating…')
                : (isEdit ? 'Save Changes' : 'Create Raw Material')}
            </Button>
          </>
        }
      >
        <EntityForm form={form} onSubmit={onSubmit} id={FORM_ID} className="flex flex-col gap-3">

          {err && (
            <Alert variant="destructive">
              <AlertDescription>{err}</AlertDescription>
            </Alert>
          )}

          {/* ── Section 1: Basic Information ─────────────────────────────────── */}
          <SectionBlock title="Basic Information" defaultOpen>
            <ImageUploadField existingUrl={material?.image_url} onChange={setImageFile} />

            <Separator />

            <FormField name="material_type" label="Material Type" required>
              <Controller
                control={form.control}
                name="material_type"
                render={({ field }) => (
                  <div className="flex gap-4">
                    {([
                      { value: 'raw_material',       label: 'Raw Material' },
                      { value: 'packaging_material', label: 'Packaging Material' },
                    ] as const).map(({ value, label }) => (
                      <label key={value} className="flex items-center gap-2 cursor-pointer select-none">
                        <input
                          type="radio"
                          value={value}
                          checked={field.value === value}
                          onChange={() => field.onChange(value)}
                          className="accent-primary"
                        />
                        <span className="text-sm">{label}</span>
                      </label>
                    ))}
                  </div>
                )}
              />
            </FormField>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <FormField name="name" label="Material Name" required>
                  <Input placeholder="e.g. Wheat Flour Type 55" {...form.register('name')} />
                </FormField>
              </div>

              <FormField name="sku" label="SKU Code" required>
                <Input placeholder="RM-000001" {...form.register('sku')} />
              </FormField>

              <FormField name="category_id" label="Category" required>
                <div className="flex items-center gap-2">
                  <div className="flex-1 min-w-0">
                    <Controller
                      control={form.control}
                      name="category_id"
                      render={({ field }) => (
                        <RawMaterialCategorySelect
                          value={field.value || null}
                          onChange={field.onChange}
                        />
                      )}
                    />
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="shrink-0 h-9 w-9 p-0"
                    title="Create new category"
                    onClick={() => setCreateCategoryOpen(true)}
                  >
                    <Plus className="size-4" />
                  </Button>
                </div>
              </FormField>

              <FormField name="unit_id" label="Unit of Measure" required>
                <Controller
                  control={form.control}
                  name="unit_id"
                  render={({ field }) => (
                    <UnitSelect value={field.value || null} onChange={field.onChange} />
                  )}
                />
              </FormField>

              <div className="sm:col-span-2">
                <FormField name="description" label="Description">
                  <Textarea
                    rows={3}
                    placeholder="Internal description for procurement and manufacturing…"
                    {...form.register('description')}
                  />
                </FormField>
              </div>
            </div>
          </SectionBlock>

          {/* ── Section 2: Inventory ──────────────────────────────────────────── */}
          <SectionBlock title="Inventory">
            <div className="flex items-center justify-between rounded-md border px-3 py-2.5">
              <div>
                <p className="text-sm font-medium">Allow Negative Stock</p>
                <p className="text-xs text-muted-foreground mt-0.5">
                  Allow stock to go below zero (useful for pre-ordering raw materials).
                </p>
              </div>
              <Controller
                control={form.control}
                name="allow_negative_stock"
                render={({ field }) => (
                  <Switch checked={field.value} onCheckedChange={field.onChange} />
                )}
              />
            </div>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField name="minimum_stock" label="Minimum Stock">
                <Input
                  type="number" min="0" step="0.01" placeholder="0"
                  {...form.register('minimum_stock', { setValueAs: numVal })}
                />
              </FormField>

              <FormField
                name="reorder_point"
                label="Reorder Point"
                description="When available quantity reaches this value, ECOS recommends creating a purchase order."
              >
                <Input
                  type="number" min="0" step="0.01" placeholder="0"
                  {...form.register('reorder_point', { setValueAs: numVal })}
                />
              </FormField>

              <div className="sm:col-span-2">
                <FormField name="preferred_warehouse_id" label="Preferred Warehouse">
                  <Controller
                    control={form.control}
                    name="preferred_warehouse_id"
                    render={({ field }) => (
                      <Combobox
                        options={warehouseOptions}
                        value={field.value || null}
                        onChange={field.onChange}
                        placeholder="Select warehouse…"
                      />
                    )}
                  />
                </FormField>
              </div>
            </div>
          </SectionBlock>

          {/* ── Section 3: Suppliers ──────────────────────────────────────────── */}
          <SectionBlock title="Suppliers" count={supplierCount}>
            <SupplierTableSection form={form} supplierOptions={supplierOptions} />
          </SectionBlock>

          {/* ── Section 4: Material Cost ──────────────────────────────────────── */}
          <SectionBlock title="Material Cost">
            <div className="rounded-md border bg-muted/30 px-3 py-3">
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Current Cost</p>
              <p className="mt-1 text-lg font-semibold">{fmtPrice(material?.material_cost, currency, locale)}</p>
              <div className="flex flex-wrap items-center gap-x-2 mt-1 text-xs text-muted-foreground">
                <span>Last updated: {fmtDate(material?.updated_at)}</span>
                <span aria-hidden>·</span>
                <span>Source: {costSourceLabel(material?.cost_source)}</span>
              </div>
            </div>

            <Separator />

            <FormField
              name="manual_cost"
              label="Manual Cost Override"
              description="Override the material cost manually. Cascades to recipe cost ← final product cost ← pricing review automatically on save."
            >
              <div className="relative max-w-48">
                <Input
                  type="number" step="0.01" min="0" placeholder="0.00" className="pr-12"
                  {...form.register('manual_cost', { setValueAs: numVal })}
                />
                <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground pointer-events-none">{currency}</span>
              </div>
            </FormField>
          </SectionBlock>

          {/* ── Section 5: Purchasing ─────────────────────────────────────────── */}
          <SectionBlock title="Purchasing">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <FormField name="purchasing_supplier_id" label="Preferred Supplier">
                  <Controller
                    control={form.control}
                    name="purchasing_supplier_id"
                    render={({ field }) => (
                      <Combobox
                        options={supplierOptions}
                        value={field.value || null}
                        onChange={field.onChange}
                        placeholder="Select preferred supplier…"
                      />
                    )}
                  />
                </FormField>
              </div>

              <FormField name="purchasing_lead_time_days" label="Lead Time (Days)">
                <Input
                  type="number" min="0" placeholder="0"
                  {...form.register('purchasing_lead_time_days', { setValueAs: numVal })}
                />
              </FormField>

              <FormField name="purchasing_minimum_order_qty" label="Minimum Order Qty">
                <Input
                  type="number" min="0" step="0.01" placeholder="0"
                  {...form.register('purchasing_minimum_order_qty', { setValueAs: numVal })}
                />
              </FormField>

            </div>
          </SectionBlock>

          {/* ── Section 6: Attachments ────────────────────────────────────────── */}
          <SectionBlock title="Attachments" count={attachments.length || undefined}>
            <AttachmentSection entries={attachments} onChange={setAttachments} />
          </SectionBlock>

          {/* ── Section 7: Notes ──────────────────────────────────────────────── */}
          <SectionBlock title="Notes">
            <FormField
              name="internal_notes"
              label="Internal Notes"
              description="Not synced to any sales channel or commerce platform."
            >
              <Textarea
                rows={4}
                placeholder="Quality notes, handling requirements, certifications, sourcing comments…"
                {...form.register('internal_notes')}
              />
            </FormField>
          </SectionBlock>

        </EntityForm>
      </EntityDrawer>

      <CreateCategoryDialog
        open={createCategoryOpen}
        onOpenChange={setCreateCategoryOpen}
        onCreated={(id) => form.setValue('category_id', id)}
      />
    </>
  );
}
