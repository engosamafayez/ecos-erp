import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';
import { AlertCircle, Loader2, Minus, PackageSearch, Plus, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { CompanySelect } from '@/features/branches/components/company-select';
import { warehousesService } from '@/features/warehouses/services/warehouses-service';
import { productsService } from '@/features/products/services/products-service';
import type { ProductType } from '@/features/products/types/product';
import { toast } from '@/components/ds/use-toast';
import { extractApiErrorMessage } from '@/lib/api-error';
import { useCreatePurchaseMaterial } from '../hooks/use-purchase-materials';
import { EnterpriseDemandPanel } from './enterprise-demand-panel';
import type { PurchaseMaterialLinePayload, PurchaseMaterialPriority } from '../types/purchase-material';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  recordType?: 'material_request' | 'purchase';
  sourceType?: 'material_request' | 'direct' | 'reorder' | 'ai' | 'manual' | null;
};

type LineItem = PurchaseMaterialLinePayload & {
  _name: string;
  _sku: string;
  _type: ProductType;
};

const TOTAL_STEPS = 3;

/** Shared with the selected-lines grouping so a material never changes group between picking
 *  and review (§ Simplify Purchase Request Create UX — the REVIEW list stays precise about the
 *  3 real catalog types even though picking itself is now split into just 2 actions below). */
const PRODUCT_GROUPS: ReadonlyArray<{ type: ProductType; labelKey: 'groupProducts' | 'groupRawMaterials' | 'groupPackagingMaterials' }> = [
  { type: 'finished_good', labelKey: 'groupProducts' },
  { type: 'raw_material', labelKey: 'groupRawMaterials' },
  { type: 'packaging_material', labelKey: 'groupPackagingMaterials' },
];

/** The two explicit picking actions (TASK-...-PURCHASE-REQUESTS-FINAL-019 §2). "Add Product"
 *  covers both finished goods and packaging materials — both are "a product" to a buyer picking
 *  what to purchase, as opposed to "Add Raw Material" — so no purchasable catalog type loses its
 *  own entry point. Each maps to the existing, already-supported `/products` filter (`product_type`
 *  / `product_types`) — no new backend query. */
type PickerKind = 'product' | 'raw_material';
const PICKER_FILTERS: Record<PickerKind, { product_type?: ProductType; product_types?: string }> = {
  product: { product_types: 'finished_good,packaging_material' },
  raw_material: { product_type: 'raw_material' },
};
const BROWSE_LIMIT = 3;
const SEARCH_LIMIT = 20;

export function CreatePurchaseMaterialWizard({ open, onOpenChange, recordType = 'material_request', sourceType }: Props) {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;

  const PRIORITY_OPTIONS: Array<{ value: PurchaseMaterialPriority; label: string }> = [
    { value: 'low', label: t($ => $.wizard.priority.low) },
    { value: 'normal', label: t($ => $.wizard.priority.normal) },
    { value: 'high', label: t($ => $.wizard.priority.high) },
    { value: 'urgent', label: t($ => $.wizard.priority.urgent) },
  ];

  const [step, setStep] = useState(1);

  // Step 1 fields
  const [companyId, setCompanyId] = useState('');
  const [warehouseId, setWarehouseId] = useState('');
  const [priority, setPriority] = useState<PurchaseMaterialPriority>('normal');
  const [requiredDate, setRequiredDate] = useState('');
  const [notes, setNotes] = useState('');

  // Step 2 fields
  const [pickerType, setPickerType] = useState<PickerKind | null>(null);
  const [pickerSearch, setPickerSearch] = useState('');
  const [lines, setLines] = useState<LineItem[]>([]);
  const [focusedProductId, setFocusedProductId] = useState<string | null>(null);

  const create = useCreatePurchaseMaterial();

  const { data: warehousesData, isLoading: wLoading } = useQuery({
    queryKey: ['warehouses-for-pm', companyId],
    queryFn: () => warehousesService.list({ company_id: companyId || undefined, status: 'active', per_page: 100 }),
    staleTime: 60_000,
  });

  const { data: pickerData, isLoading: pickerLoading } = useQuery({
    queryKey: ['products-for-pm-wizard', pickerType, pickerSearch],
    queryFn: () => productsService.list({
      ...(pickerType ? PICKER_FILTERS[pickerType] : {}),
      search: pickerSearch || undefined,
      // Default browse shows at most BROWSE_LIMIT items; searching lifts the cap (§2).
      per_page: pickerSearch ? SEARCH_LIMIT : BROWSE_LIMIT,
    }),
    staleTime: 30_000,
    enabled: step === 2 && pickerType !== null,
  });

  const warehouses = warehousesData?.items ?? [];
  const pickerResults = pickerData?.items ?? [];

  // For demand panel: use the focused product if set, else the last added line
  const panelProductId = focusedProductId ?? lines.at(-1)?.product_id ?? null;
  const panelRequestedQty = lines.find((l) => l.product_id === panelProductId)?.requested_qty;

  function handleClose() {
    setStep(1);
    setCompanyId('');
    setWarehouseId('');
    setPriority('normal');
    setRequiredDate('');
    setNotes('');
    setPickerType(null);
    setPickerSearch('');
    setLines([]);
    setFocusedProductId(null);
    onOpenChange(false);
  }

  function togglePicker(kind: PickerKind) {
    setPickerSearch('');
    setPickerType((prev) => (prev === kind ? null : kind));
  }

  function addProduct(product: { id: string; name: string; sku: string; product_type: ProductType }) {
    if (lines.find((l) => l.product_id === product.id)) {
      setFocusedProductId(product.id);
      return;
    }
    setLines((prev) => [
      ...prev,
      { product_id: product.id, requested_qty: 1, unit_label: null, notes: null, _name: product.name, _sku: product.sku, _type: product.product_type },
    ]);
    setFocusedProductId(product.id);
  }

  function updateQty(productId: string, qty: number) {
    setLines((prev) => prev.map((l) => (l.product_id === productId ? { ...l, requested_qty: Math.max(0.0001, qty) } : l)));
  }

  function removeLine(productId: string) {
    setLines((prev) => prev.filter((l) => l.product_id !== productId));
    if (focusedProductId === productId) setFocusedProductId(null);
  }

  async function handleSubmit() {
    if (!warehouseId || lines.length === 0) return;
    try {
      await create.mutateAsync({
        warehouse_id: warehouseId,
        company_id: companyId || null,
        // Channel removed from the creation UX (§5) — Procurement never needs it; downstream
        // canonical logic already treats a null channel as valid.
        channel_id: null,
        priority,
        required_date: requiredDate || null,
        notes: notes || null,
        record_type: recordType,
        source_type: sourceType ?? null,
        lines: lines.map(({ product_id, requested_qty, unit_label, notes: ln }) => ({
          product_id,
          requested_qty,
          unit_label,
          notes: ln,
        })),
      });
      toast.success(t($ => $.wizard.toast.success));
      handleClose();
    } catch (err) {
      toast.error(extractApiErrorMessage(err));
    }
  }

  const canProceedStep1 = !!warehouseId;
  const canProceedStep2 = lines.length > 0;

  const stepName =
    step === 1
      ? t($ => $.wizard.steps.generalInfo)
      : step === 2
        ? t($ => $.wizard.steps.requestedMaterials)
        : t($ => $.wizard.steps.reviewSubmit);

  const pickerSearchPlaceholder = pickerType === 'raw_material'
    ? t($ => $.wizard.step2.searchRawMaterialsPlaceholder)
    : t($ => $.wizard.step2.searchProductsPlaceholder);

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      {/* §1 — normal, unified scroll instead of a fixed 92vh box with 2-3 separately-scrolling
          nested regions (search results / selected lines / demand panel each had their own
          scrollbar). Full height on mobile (feels like a page, not a cramped popup); a single
          generous cap on desktop. Exactly ONE scroll container below carries the whole active
          step, so "Selected" is always reachable by the same continuous scroll as everything
          else — never trapped in its own pane. */}
      <DialogContent className="sm:max-w-4xl w-full h-full sm:h-auto sm:max-h-[88vh] flex flex-col">
        <DialogHeader>
          <DialogTitle>{t($ => $.wizard.title)}</DialogTitle>
          <DialogDescription>
            {tAny('wizard.stepOf', { step, total: TOTAL_STEPS })} {stepName}
          </DialogDescription>
        </DialogHeader>

        {/* Step progress bar */}
        <div className="flex gap-1 px-1 shrink-0">
          {Array.from({ length: TOTAL_STEPS }, (_, i) => (
            <div
              key={i}
              className={`h-1 flex-1 rounded-full transition-colors ${i + 1 <= step ? 'bg-primary' : 'bg-muted'}`}
            />
          ))}
        </div>

        <div className="flex-1 min-h-0 overflow-y-auto px-1">
          {/* ── Step 1: General Information ─────────────────────────── */}
          {step === 1 && (
            <div className="flex flex-col gap-4 py-1">
              <div className="flex flex-col gap-1.5">
                <label className="text-sm font-medium">{t($ => $.wizard.step1.company)}</label>
                <CompanySelect
                  value={companyId || null}
                  onChange={(v) => { setCompanyId(v ?? ''); setWarehouseId(''); }}
                />
              </div>

              <div className="flex flex-col gap-1.5">
                <label className="text-sm font-medium">
                  {t($ => $.wizard.step1.warehouse)} <span className="text-destructive">*</span>
                </label>
                {wLoading ? (
                  <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Loader2 className="size-3.5 animate-spin" /> {t($ => $.wizard.step1.loadingWarehouses)}
                  </div>
                ) : (
                  <Select value={warehouseId || undefined} onValueChange={setWarehouseId}>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder={t($ => $.wizard.step1.selectWarehouse)} />
                    </SelectTrigger>
                    <SelectContent>
                      {warehouses.map((w) => (
                        <SelectItem key={w.id} value={w.id}>{w.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="flex flex-col gap-1.5">
                  <label className="text-sm font-medium">{t($ => $.wizard.step1.priority)}</label>
                  <Select value={priority} onValueChange={(v) => setPriority(v as PurchaseMaterialPriority)}>
                    <SelectTrigger className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {PRIORITY_OPTIONS.map((p) => (
                        <SelectItem key={p.value} value={p.value}>{p.label}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <label className="text-sm font-medium">{t($ => $.wizard.step1.requiredBy)}</label>
                  <Input type="date" value={requiredDate} onChange={(e) => setRequiredDate(e.target.value)} />
                </div>
              </div>

              <div className="flex flex-col gap-1.5">
                <label className="text-sm font-medium">{t($ => $.wizard.step1.notes)}</label>
                <Textarea
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  rows={3}
                  placeholder={t($ => $.wizard.step1.notesPlaceholder)}
                  className="resize-none"
                />
              </div>
            </div>
          )}

          {/* ── Step 2: Requested Materials + Demand Panel ──────────── */}
          {step === 2 && (
            <div className="flex flex-col sm:flex-row gap-4 py-1">
              {/* Left: two explicit picking actions + selected lines */}
              <div className="flex flex-col gap-3 flex-1 min-w-0">
                {/* §2 — Add Product / Add Raw Material replace one mixed catalogue browser.
                    Each opens its own canonical selector, scoped server-side by product type. */}
                <div className="flex gap-2">
                  <Button
                    type="button"
                    size="sm"
                    variant={pickerType === 'product' ? 'default' : 'outline'}
                    onClick={() => togglePicker('product')}
                  >
                    <Plus className="size-3.5" /> {t($ => $.wizard.step2.addProduct)}
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    variant={pickerType === 'raw_material' ? 'default' : 'outline'}
                    onClick={() => togglePicker('raw_material')}
                  >
                    <Plus className="size-3.5" /> {t($ => $.wizard.step2.addRawMaterial)}
                  </Button>
                </div>

                {pickerType && (
                  <div className="flex flex-col gap-2 rounded-md border bg-muted/20 p-3">
                    <div className="flex items-center gap-2">
                      <div className="relative flex-1">
                        <PackageSearch className="absolute left-3 top-1/2 -translate-y-1/2 size-3.5 text-muted-foreground" />
                        <Input
                          className="pl-9 h-8 bg-background"
                          placeholder={pickerSearchPlaceholder}
                          value={pickerSearch}
                          onChange={(e) => setPickerSearch(e.target.value)}
                          autoFocus
                        />
                      </div>
                      <button
                        type="button"
                        onClick={() => setPickerType(null)}
                        className="text-muted-foreground hover:text-foreground transition-colors"
                        aria-label={t($ => $.wizard.step2.closePicker)}
                      >
                        <X className="size-4" />
                      </button>
                    </div>

                    <div className="border rounded-md bg-background overflow-hidden">
                      {pickerLoading ? (
                        <div className="flex items-center justify-center gap-2 py-6 text-sm text-muted-foreground">
                          <Loader2 className="size-4 animate-spin" /> {t($ => $.wizard.step2.searching)}
                        </div>
                      ) : pickerResults.length === 0 ? (
                        <div className="flex items-center justify-center py-6 text-sm text-muted-foreground">
                          {t($ => $.wizard.step2.noProducts)}
                        </div>
                      ) : (
                        <table className="w-full text-sm">
                          <tbody>
                            {pickerResults.map((p) => {
                              const added = lines.some((l) => l.product_id === p.id);
                              return (
                                <tr key={p.id} className="border-b last:border-0 hover:bg-muted/30 transition-colors">
                                  <td className="px-3 py-2">
                                    <p className="font-medium leading-tight">{p.name}</p>
                                    <p className="text-xs text-muted-foreground">{p.sku}</p>
                                  </td>
                                  <td className="px-3 py-2 text-end">
                                    <Button
                                      type="button"
                                      size="sm"
                                      variant={added ? 'outline' : 'default'}
                                      onClick={() => (added ? removeLine(p.id) : addProduct({ id: p.id, name: p.name, sku: p.sku, product_type: p.product_type }))}
                                    >
                                      {added ? <Minus className="size-3.5" /> : <Plus className="size-3.5" />}
                                      {added ? t($ => $.wizard.step2.remove) : t($ => $.wizard.step2.add)}
                                    </Button>
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      )}
                    </div>

                    {!pickerSearch && !pickerLoading && pickerResults.length > 0 && (
                      <p className="text-[11px] text-muted-foreground">
                        {t($ => $.wizard.step2.browseHint)}
                      </p>
                    )}
                  </div>
                )}

                {/* Selected lines — grouped by real catalog type (§3) so a working list of
                    Products / Raw Materials / Packaging Materials never reads as one blended list,
                    regardless of which of the two picking actions added each one. */}
                {lines.length > 0 && (
                  <div className="flex flex-col gap-2">
                    <p className="text-sm font-medium text-muted-foreground">
                      {tAny('wizard.step2.selected', { count: lines.length })}
                    </p>
                    <div className="border rounded-md overflow-hidden">
                      <table className="w-full text-sm">
                        <thead className="bg-muted/40">
                          <tr>
                            <th className="px-3 py-1.5 text-start font-medium text-xs text-muted-foreground">{t($ => $.wizard.step2.material)}</th>
                            <th className="px-3 py-1.5 text-center font-medium text-xs text-muted-foreground w-24">{t($ => $.wizard.step2.qty)}</th>
                            <th className="px-3 py-1.5 w-8" />
                          </tr>
                        </thead>
                        {PRODUCT_GROUPS.map(({ type, labelKey }) => {
                          const groupLines = lines.filter((l) => l._type === type);
                          if (groupLines.length === 0) return null;
                          return (
                            <tbody key={type} className="border-t">
                              <tr>
                                <td colSpan={3} className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground bg-muted/20">
                                  {t(($) => $.wizard.step2[labelKey])}
                                </td>
                              </tr>
                              {groupLines.map((line) => (
                                <tr
                                  key={line.product_id}
                                  className={`border-t cursor-pointer transition-colors ${focusedProductId === line.product_id ? 'bg-primary/5' : 'hover:bg-muted/20'}`}
                                  onClick={() => setFocusedProductId(line.product_id)}
                                >
                                  <td className="px-3 py-1.5">
                                    <p className="font-medium leading-tight text-sm">{line._name}</p>
                                    <p className="text-xs text-muted-foreground">{line._sku}</p>
                                  </td>
                                  <td className="px-3 py-1.5">
                                    <Input
                                      type="number"
                                      min={0.0001}
                                      step={0.01}
                                      value={line.requested_qty}
                                      onChange={(e) => updateQty(line.product_id, parseFloat(e.target.value) || 1)}
                                      onClick={(e) => e.stopPropagation()}
                                      className="no-spinner h-7 text-center w-full"
                                    />
                                  </td>
                                  <td className="px-3 py-1.5 text-center">
                                    <button
                                      type="button"
                                      onClick={(e) => { e.stopPropagation(); removeLine(line.product_id); }}
                                      className="text-muted-foreground hover:text-destructive transition-colors"
                                    >
                                      <Minus className="size-3.5" />
                                    </button>
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          );
                        })}
                      </table>
                    </div>
                  </div>
                )}

                {lines.length === 0 && (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground border border-dashed rounded-md p-4">
                    <AlertCircle className="size-4 shrink-0" />
                    {t($ => $.wizard.step2.addAtLeastOne)}
                  </div>
                )}
              </div>

              {/* Right: Enterprise Demand Panel — no scroll container of its own; it flows as
                  part of the single outer scroll region above. */}
              <div className="w-full sm:w-64 shrink-0 sm:border-l sm:pl-4">
                <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">
                  {t($ => $.wizard.step2.demandIntelligence)}
                </p>
                <EnterpriseDemandPanel
                  productId={panelProductId}
                  warehouseId={warehouseId}
                  requestedQty={panelRequestedQty}
                  requiredDate={requiredDate || undefined}
                />
              </div>
            </div>
          )}

          {/* ── Step 3: Review ──────────────────────────────────────── */}
          {step === 3 && (
            <div className="flex flex-col gap-4 py-1">
              <div className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                  <p className="text-xs text-muted-foreground">{t($ => $.wizard.step3.warehouse)}</p>
                  <p className="font-medium">{warehouses.find((w) => w.id === warehouseId)?.name ?? warehouseId}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">{t($ => $.wizard.step3.priority)}</p>
                  <p className="font-medium capitalize">{priority}</p>
                </div>
                {requiredDate && (
                  <div>
                    <p className="text-xs text-muted-foreground">{t($ => $.wizard.step3.requiredBy)}</p>
                    <p className="font-medium">
                      {new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(requiredDate))}
                    </p>
                  </div>
                )}
                {notes && (
                  <div className="col-span-2">
                    <p className="text-xs text-muted-foreground">{t($ => $.wizard.step3.notes)}</p>
                    <p>{notes}</p>
                  </div>
                )}
              </div>

              <div className="border rounded-md overflow-hidden">
                <table className="w-full text-sm">
                  <thead className="bg-muted/40">
                    <tr>
                      <th className="px-3 py-2 text-start font-medium text-xs text-muted-foreground">{t($ => $.wizard.step3.material)}</th>
                      <th className="px-3 py-2 text-end font-medium text-xs text-muted-foreground">{t($ => $.wizard.step3.requestedQty)}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {lines.map((line) => (
                      <tr key={line.product_id} className="border-t">
                        <td className="px-3 py-2">
                          <p className="font-medium">{line._name}</p>
                          <p className="text-xs text-muted-foreground">{line._sku}</p>
                        </td>
                        <td className="px-3 py-2 text-end font-mono">{line.requested_qty.toLocaleString()}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </div>

        <DialogFooter className="gap-2 pt-2 shrink-0">
          {step > 1 && (
            <Button type="button" variant="outline" onClick={() => setStep((s) => s - 1)}>
              {t($ => $.wizard.buttons.previous)}
            </Button>
          )}
          <Button type="button" variant="ghost" onClick={handleClose}>
            {t($ => $.wizard.buttons.cancel)}
          </Button>
          {step < TOTAL_STEPS ? (
            <Button
              type="button"
              disabled={step === 1 ? !canProceedStep1 : !canProceedStep2}
              onClick={() => setStep((s) => s + 1)}
            >
              {t($ => $.wizard.buttons.next)}
            </Button>
          ) : (
            <Button
              type="button"
              disabled={create.isPending}
              onClick={() => { void handleSubmit(); }}
            >
              {create.isPending ? (
                <>
                  <Loader2 className="size-4 animate-spin mr-2" />
                  {t($ => $.wizard.buttons.creating)}
                </>
              ) : t($ => $.wizard.buttons.create)}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
