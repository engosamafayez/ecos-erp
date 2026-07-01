import { useEffect, useMemo, useState } from 'react';
import { Controller, FormProvider, useFieldArray, useForm } from 'react-hook-form';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { zodResolver } from '@hookform/resolvers/zod';
import axios from 'axios';
import {
  AlertCircle,
  BookOpen,
  Eye,
  Pencil,
  Plus,
  Search,
  Trash2,
  TriangleAlert,
} from 'lucide-react';

import { Combobox, FormField, PageHeader } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { CompanySelect } from '@/features/branches/components/company-select';
import { ChannelSelect } from '@/features/channels/components/channel-select';
import { useCreateRecipe, useRecipeQuery, useUpdateRecipe } from '@/features/recipes/hooks/use-recipes';
import {
  recipeFormSchema,
  type RecipeFormValues,
} from '@/features/recipes/schemas/recipe-form-schema';
import type { Recipe } from '@/features/recipes/types/recipe';
import { RawMaterialDetailDrawer } from '@/features/products/components/raw-material-detail-drawer';
import type { Product } from '@/features/products/types/product';
import { useProductsQuery } from '@/features/products/hooks/use-products';
import { ROUTES } from '@/router/routes';

const FORM_ID = 'recipe-form';

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

function fmt(value: number, decimals = 2): string {
  return value.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
}

function LabelValue({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
        {label}
      </span>
      <span className="text-sm">{value ?? '—'}</span>
    </div>
  );
}

function WorkspaceCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">{title}</CardTitle>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

// ─── Material Picker Dialog ───────────────────────────────────────────────────

type MaterialPickerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  materials: Product[];
  isLoading: boolean;
  alreadySelected: string[];
  onSelect: (material: Product) => void;
};

function MaterialPicker({
  open,
  onOpenChange,
  materials,
  isLoading,
  alreadySelected,
  onSelect,
}: MaterialPickerProps) {
  const [search, setSearch] = useState('');

  const filtered = useMemo(() => {
    const q = search.toLowerCase();
    return materials.filter(
      (m) => m.name.toLowerCase().includes(q) || m.sku.toLowerCase().includes(q),
    );
  }, [materials, search]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Add Material</DialogTitle>
        </DialogHeader>

        <div className="relative">
          <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
          <Input
            className="pl-8"
            placeholder="Search by name or SKU…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            autoFocus
          />
        </div>

        <div className="max-h-80 overflow-y-auto divide-y">
          {isLoading ? (
            <p className="text-muted-foreground py-6 text-center text-sm">Loading materials…</p>
          ) : filtered.length === 0 ? (
            <p className="text-muted-foreground py-6 text-center text-sm">No materials found</p>
          ) : (
            filtered.map((m) => {
              const added = alreadySelected.includes(m.id);
              return (
                <button
                  key={m.id}
                  type="button"
                  onClick={() => {
                    if (!added) {
                      onSelect(m);
                      onOpenChange(false);
                      setSearch('');
                    }
                  }}
                  disabled={added}
                  className="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-accent disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  {m.image_url ? (
                    <img
                      src={m.image_url}
                      alt={m.name}
                      className="size-8 rounded object-cover flex-shrink-0"
                    />
                  ) : (
                    <div className="size-8 rounded bg-muted flex items-center justify-center flex-shrink-0">
                      <BookOpen className="size-4 text-muted-foreground" />
                    </div>
                  )}
                  <div className="flex-1 min-w-0">
                    <p className="truncate text-sm font-medium">{m.name}</p>
                    <p className="text-muted-foreground text-xs">{m.sku}</p>
                  </div>
                  {added && (
                    <Badge variant="secondary" className="text-xs">Added</Badge>
                  )}
                </button>
              );
            })
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}

// ─── View Workspace ───────────────────────────────────────────────────────────

function ViewWorkspace({ recipe }: { recipe: Recipe }) {
  const navigate = useNavigate();
  const [drawerMaterial, setDrawerMaterial] = useState<Product | null>(null);

  const { data: rawMaterialsData } = useProductsQuery({
    product_type: 'raw_material',
    status: 'all',
    per_page: 999,
  });
  const rmMap = useMemo(() => {
    const map = new Map<string, Product>();
    (rawMaterialsData?.items ?? []).forEach((p) => map.set(p.id, p));
    return map;
  }, [rawMaterialsData]);

  const lineCosts = recipe.lines.map((line) => {
    const product = rmMap.get(line.raw_material_id);
    const unitCost = product?.regular_price ?? 0;
    return { ...line, unitCost, lineTotal: line.quantity * unitCost };
  });
  const totalCost = lineCosts.reduce((sum, l) => sum + l.lineTotal, 0);

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={recipe.bom_number}
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Recipes', to: ROUTES.recipes },
          { label: recipe.bom_number },
        ]}
        actions={
          <Button onClick={() => navigate(`${ROUTES.recipes}/${recipe.id}/edit`)}>
            <Pencil className="size-4" />
            Edit Recipe
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_320px]">
        <div className="flex flex-col gap-6">
          <WorkspaceCard title="Details">
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
              <LabelValue label="Recipe ID" value={<span className="font-mono">{recipe.bom_number}</span>} />
              <LabelValue label="Finished Good" value={recipe.product?.name} />
              {recipe.notes ? (
                <div className="col-span-full">
                  <LabelValue label="Notes" value={recipe.notes} />
                </div>
              ) : null}
            </div>
          </WorkspaceCard>

          <WorkspaceCard title="Materials">
            {recipe.lines.length === 0 ? (
              <p className="text-muted-foreground text-sm">No materials defined.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-muted-foreground border-b text-left">
                      <th className="w-8 pb-2 pe-3 font-medium">#</th>
                      <th className="pb-2 pe-3 font-medium">Material</th>
                      <th className="w-20 pb-2 pe-3 font-medium">Unit</th>
                      <th className="w-24 pb-2 pe-3 text-end font-medium">Qty</th>
                      <th className="w-24 pb-2 pe-3 text-end font-medium">Waste%</th>
                      <th className="w-28 pb-2 pe-3 text-end font-medium">Unit Cost</th>
                      <th className="w-28 pb-2 pe-3 text-end font-medium">Total Cost</th>
                      <th className="w-20 pb-2 text-end font-medium">Cost%</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {lineCosts.map((line, index) => {
                      const costPct = totalCost > 0 ? (line.lineTotal / totalCost) * 100 : 0;
                      return (
                        <tr key={line.id}>
                          <td className="py-2 pe-3 text-muted-foreground">{index + 1}</td>
                          <td className="py-2 pe-3">
                            <div className="flex flex-col">
                              <span className="font-medium">{line.raw_material?.name ?? '—'}</span>
                              <span className="text-muted-foreground text-xs">{line.raw_material?.sku}</span>
                            </div>
                          </td>
                          <td className="py-2 pe-3 text-muted-foreground">
                            {line.raw_material?.unit?.symbol ?? '—'}
                          </td>
                          <td className="py-2 pe-3 text-end">{fmt(line.quantity, 4)}</td>
                          <td className="py-2 pe-3 text-end">{fmt(line.waste_percentage)}%</td>
                          <td className="py-2 pe-3 text-end">{fmt(line.unitCost)}</td>
                          <td className="py-2 pe-3 text-end">{fmt(line.lineTotal)}</td>
                          <td className="py-2 text-end text-muted-foreground">{fmt(costPct)}%</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </WorkspaceCard>
        </div>

        <div className="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
          <WorkspaceCard title="Summary">
            <div className="flex flex-col gap-3">
              <LabelValue
                label="Recipe ID"
                value={<span className="font-mono">{recipe.bom_number}</span>}
              />
              <LabelValue label="Finished Good" value={recipe.product?.name} />
              <Separator />
              <LabelValue label="Total Materials" value={recipe.lines.length} />
              <LabelValue
                label="Estimated Cost"
                value={<span className="font-semibold">{fmt(totalCost)}</span>}
              />
            </div>
          </WorkspaceCard>
        </div>
      </div>

      <RawMaterialDetailDrawer
        material={drawerMaterial}
        open={drawerMaterial !== null}
        onOpenChange={(open) => { if (!open) setDrawerMaterial(null); }}
      />
    </div>
  );
}

// ─── Form Workspace ───────────────────────────────────────────────────────────

type LineError = {
  raw_material_id?: { message?: string };
  quantity?: { message?: string };
  waste_percentage?: { message?: string };
};

function FormWorkspace({ recipe, mode }: { recipe: Recipe | null; mode: 'create' | 'edit' }) {
  const navigate = useNavigate();
  const [serverError, setServerError] = useState<string | null>(null);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [drawerMaterial, setDrawerMaterial] = useState<Product | null>(null);

  // Company/Channel selectors — local UI state for filtering products only
  const [companyId, setCompanyId] = useState<string | null>(null);
  const [channelId, setChannelId] = useState<string | null>(null);

  const createRecipe = useCreateRecipe();
  const updateRecipe = useUpdateRecipe(recipe?.id ?? '');

  // Finished goods filtered by optional channel
  const { data: finishedGoods, isLoading: loadingFG } = useProductsQuery({
    product_type: 'finished_good',
    status: 'all',
    per_page: 999,
    channel_id: channelId ?? undefined,
  });

  // Raw materials (all) — needed for picker and cost computation
  const { data: rawMaterialsData, isLoading: loadingRM } = useProductsQuery({
    product_type: 'raw_material',
    status: 'all',
    per_page: 999,
  });

  const fgOptions = (finishedGoods?.items ?? []).map((p) => ({ value: p.id, label: p.name }));
  const rawMaterials = rawMaterialsData?.items ?? [];

  const rmMap = useMemo(() => {
    const map = new Map<string, Product>();
    rawMaterials.forEach((p) => map.set(p.id, p));
    return map;
  }, [rawMaterials]);

  const form = useForm<RecipeFormValues>({
    resolver: zodResolver(recipeFormSchema),
    defaultValues: recipe
      ? {
          product_id: recipe.product_id,
          notes: recipe.notes ?? '',
          lines: recipe.lines.map((l) => ({
            raw_material_id: l.raw_material_id,
            quantity: l.quantity,
            waste_percentage: l.waste_percentage,
          })),
        }
      : {
          product_id: '',
          notes: '',
          lines: [],
        },
  });

  const { fields, append, remove } = useFieldArray({ control: form.control, name: 'lines' });
  const lineErrors = form.formState.errors.lines as LineError[] | undefined;
  const watchedLines = form.watch('lines');
  const watchedProductId = form.watch('product_id');

  // Compute costs from watched form values
  const lineCosts = useMemo(
    () =>
      watchedLines.map((line) => {
        const product = rmMap.get(line.raw_material_id);
        const unitCost = product?.regular_price ?? 0;
        return { unitCost, lineTotal: (line.quantity || 0) * unitCost };
      }),
    [watchedLines, rmMap],
  );
  const totalCost = lineCosts.reduce((sum, l) => sum + l.lineTotal, 0);

  const selectedMaterialIds = watchedLines.map((l) => l.raw_material_id).filter(Boolean);

  const handleAddMaterial = (material: Product) => {
    append({ raw_material_id: material.id, quantity: 1, waste_percentage: 0 });
  };

  const handleSubmit = (values: RecipeFormValues) => {
    setServerError(null);
    const payload = {
      product_id: values.product_id,
      version: '1.0',
      is_active: true,
      notes: values.notes || null,
      lines: values.lines.map((l) => ({
        raw_material_id: l.raw_material_id,
        quantity: l.quantity,
        waste_percentage: l.waste_percentage ?? 0,
      })),
    };

    if (mode === 'create') {
      createRecipe.mutate(payload, {
        onSuccess: (created) => navigate(`${ROUTES.recipes}/${created.id}`),
        onError: (error) => setServerError(extractMessage(error)),
      });
    } else {
      updateRecipe.mutate(payload, {
        onSuccess: () => navigate(`${ROUTES.recipes}/${recipe!.id}`),
        onError: (error) => setServerError(extractMessage(error)),
      });
    }
  };

  const isPending = createRecipe.isPending || updateRecipe.isPending;
  const title = mode === 'create' ? 'New Recipe' : 'Edit Recipe';

  return (
    <div className="flex flex-col gap-6 pb-24">
      <PageHeader
        title={title}
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Recipes', to: ROUTES.recipes },
          { label: title },
        ]}
      />

      {serverError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>Error</AlertTitle>
          <AlertDescription>{serverError}</AlertDescription>
        </Alert>
      ) : null}

      <FormProvider {...form}>
        <form id={FORM_ID} onSubmit={form.handleSubmit(handleSubmit)} noValidate>
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_300px]">
            <div className="flex flex-col gap-6">
              {/* Product selection */}
              <WorkspaceCard title="Finished Good">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                  <div>
                    <label className="text-sm font-medium mb-1.5 block text-muted-foreground">
                      Company
                    </label>
                    <CompanySelect
                      value={companyId}
                      onChange={(v) => {
                        setCompanyId(v);
                        setChannelId(null);
                        form.setValue('product_id', '');
                      }}
                      placeholder="All companies"
                    />
                  </div>
                  <div>
                    <label className="text-sm font-medium mb-1.5 block text-muted-foreground">
                      Sales Channel
                    </label>
                    <ChannelSelect
                      value={channelId}
                      onChange={(v) => {
                        setChannelId(v);
                        form.setValue('product_id', '');
                      }}
                      placeholder="All channels"
                    />
                  </div>
                  <div>
                    <FormField name="product_id" label="Finished Good" required>
                      <Controller
                        control={form.control}
                        name="product_id"
                        render={({ field }) => (
                          <Combobox
                            options={fgOptions}
                            value={field.value || null}
                            onChange={field.onChange}
                            placeholder="Select product…"
                            loading={loadingFG}
                          />
                        )}
                      />
                    </FormField>
                  </div>
                </div>

                {!watchedProductId && (
                  <div className="mt-3 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                    <TriangleAlert className="size-4 flex-shrink-0" />
                    Select a finished good before adding materials.
                  </div>
                )}
              </WorkspaceCard>

              {/* Materials table */}
              <WorkspaceCard title="Materials">
                <div className="flex flex-col gap-4">
                  {typeof form.formState.errors.lines?.message === 'string' && (
                    <Alert variant="destructive">
                      <AlertCircle className="size-4" />
                      <AlertDescription>{form.formState.errors.lines.message}</AlertDescription>
                    </Alert>
                  )}

                  {fields.length === 0 ? (
                    <div className="rounded-lg border-2 border-dashed py-10 text-center">
                      <p className="text-muted-foreground text-sm">No materials added yet.</p>
                      <p className="text-muted-foreground mt-1 text-xs">
                        Click "Add Material" to pick raw materials for this recipe.
                      </p>
                    </div>
                  ) : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead>
                          <tr className="text-muted-foreground border-b text-left">
                            <th className="w-8 pb-2 pe-2 font-medium">#</th>
                            <th className="pb-2 pe-3 font-medium">Material</th>
                            <th className="w-16 pb-2 pe-3 font-medium">Unit</th>
                            <th className="w-28 pb-2 pe-3 font-medium">Qty</th>
                            <th className="w-24 pb-2 pe-3 font-medium">Waste%</th>
                            <th className="w-28 pb-2 pe-3 text-end font-medium">Unit Cost</th>
                            <th className="w-28 pb-2 pe-3 text-end font-medium">Total</th>
                            <th className="w-16 pb-2 pe-3 text-end font-medium">Cost%</th>
                            <th className="w-16 pb-2" />
                          </tr>
                        </thead>
                        <tbody className="divide-y">
                          {fields.map((field, index) => {
                            const errs = lineErrors?.[index];
                            const line = watchedLines[index];
                            const product = rmMap.get(line?.raw_material_id ?? '');
                            const { unitCost, lineTotal } = lineCosts[index] ?? { unitCost: 0, lineTotal: 0 };
                            const costPct = totalCost > 0 ? (lineTotal / totalCost) * 100 : 0;

                            return (
                              <tr key={field.id}>
                                <td className="py-2 pe-2 text-muted-foreground text-xs">
                                  {index + 1}
                                </td>
                                <td className="py-2 pe-3">
                                  <div className="flex items-center gap-2">
                                    {product?.image_url ? (
                                      <img
                                        src={product.image_url}
                                        alt={product.name}
                                        className="size-7 rounded object-cover flex-shrink-0"
                                      />
                                    ) : (
                                      <div className="size-7 rounded bg-muted flex items-center justify-center flex-shrink-0">
                                        <BookOpen className="size-3.5 text-muted-foreground" />
                                      </div>
                                    )}
                                    <div className="flex flex-col min-w-0">
                                      <span className="truncate font-medium">
                                        {product?.name ?? line?.raw_material_id ?? '—'}
                                      </span>
                                      <span className="text-muted-foreground text-xs">
                                        {product?.sku}
                                      </span>
                                    </div>
                                  </div>
                                  {errs?.raw_material_id?.message ? (
                                    <p className="text-destructive mt-1 text-xs">
                                      {errs.raw_material_id.message}
                                    </p>
                                  ) : null}
                                </td>
                                <td className="py-2 pe-3 text-muted-foreground text-xs">
                                  {product?.unit?.symbol ?? '—'}
                                </td>
                                <td className="py-2 pe-3">
                                  <Input
                                    type="number"
                                    min="0.0001"
                                    step="0.0001"
                                    className="h-8 w-24"
                                    {...form.register(`lines.${index}.quantity`, {
                                      valueAsNumber: true,
                                    })}
                                  />
                                  {errs?.quantity?.message ? (
                                    <p className="text-destructive mt-1 text-xs">
                                      {errs.quantity.message}
                                    </p>
                                  ) : null}
                                </td>
                                <td className="py-2 pe-3">
                                  <Input
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    className="h-8 w-20"
                                    {...form.register(`lines.${index}.waste_percentage`, {
                                      valueAsNumber: true,
                                    })}
                                  />
                                </td>
                                <td className="py-2 pe-3 text-end tabular-nums">
                                  {fmt(unitCost)}
                                </td>
                                <td className="py-2 pe-3 text-end font-medium tabular-nums">
                                  {fmt(lineTotal)}
                                </td>
                                <td className="py-2 pe-3 text-end text-muted-foreground tabular-nums text-xs">
                                  {fmt(costPct)}%
                                </td>
                                <td className="py-2">
                                  <div className="flex items-center gap-1">
                                    {product ? (
                                      <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-7"
                                        onClick={() => setDrawerMaterial(product)}
                                        title="View material details"
                                      >
                                        <Eye className="size-3.5" />
                                      </Button>
                                    ) : null}
                                    <Button
                                      type="button"
                                      variant="ghost"
                                      size="icon"
                                      className="text-destructive size-7"
                                      onClick={() => remove(index)}
                                    >
                                      <Trash2 className="size-3.5" />
                                    </Button>
                                  </div>
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  )}

                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setPickerOpen(true)}
                    disabled={!watchedProductId}
                  >
                    <Plus className="size-4" />
                    Add Material
                  </Button>
                </div>
              </WorkspaceCard>

              {/* Notes */}
              <WorkspaceCard title="Notes">
                <FormField name="notes" label="">
                  <Textarea
                    placeholder="Optional recipe notes, instructions, or comments…"
                    rows={3}
                    {...form.register('notes')}
                  />
                </FormField>
              </WorkspaceCard>
            </div>

            {/* Sticky right panel */}
            <div className="flex flex-col gap-4 lg:sticky lg:top-6 lg:self-start">
              <WorkspaceCard title="Cost Summary">
                <div className="flex flex-col gap-3">
                  {recipe ? (
                    <LabelValue
                      label="Recipe ID"
                      value={<span className="font-mono text-xs">{recipe.bom_number}</span>}
                    />
                  ) : null}
                  <LabelValue label="Total Materials" value={fields.length} />
                  <Separator />
                  <div className="flex flex-col gap-1.5">
                    {lineCosts.map((lc, i) => {
                      const rmId = watchedLines[i]?.raw_material_id;
                      const product = rmMap.get(rmId ?? '');
                      if (!product) return null;
                      const costPct = totalCost > 0 ? (lc.lineTotal / totalCost) * 100 : 0;
                      return (
                        <div key={i} className="flex items-center justify-between text-xs gap-2">
                          <span className="truncate text-muted-foreground">{product.name}</span>
                          <span className="tabular-nums flex-shrink-0">
                            {fmt(costPct)}%
                          </span>
                        </div>
                      );
                    })}
                  </div>
                  {fields.length > 0 && <Separator />}
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium">Total Cost</span>
                    <span className="text-sm font-semibold tabular-nums">{fmt(totalCost)}</span>
                  </div>
                </div>
              </WorkspaceCard>
            </div>
          </div>
        </form>
      </FormProvider>

      {/* Sticky footer */}
      <div className="fixed bottom-0 left-0 right-0 z-20 border-t bg-background/95 backdrop-blur px-6 py-3">
        <div className="flex items-center justify-end gap-3 max-w-screen-xl mx-auto">
          <Button
            type="button"
            variant="outline"
            onClick={() => navigate(recipe ? `${ROUTES.recipes}/${recipe.id}` : ROUTES.recipes)}
          >
            Cancel
          </Button>
          <Button type="submit" form={FORM_ID} disabled={isPending}>
            {isPending
              ? mode === 'create'
                ? 'Creating…'
                : 'Saving…'
              : mode === 'create'
                ? 'Create Recipe'
                : 'Save Changes'}
          </Button>
        </div>
      </div>

      <MaterialPicker
        open={pickerOpen}
        onOpenChange={setPickerOpen}
        materials={rawMaterials}
        isLoading={loadingRM}
        alreadySelected={selectedMaterialIds}
        onSelect={handleAddMaterial}
      />

      <RawMaterialDetailDrawer
        material={drawerMaterial}
        open={drawerMaterial !== null}
        onOpenChange={(open) => { if (!open) setDrawerMaterial(null); }}
      />
    </div>
  );
}

// ─── Page Shell ──────────────────────────────────────────────────────────────

export function RecipeWorkspacePage() {
  const { id = '' } = useParams<{ id?: string }>();
  const { pathname } = useLocation();

  const mode = !id ? 'create' : pathname.endsWith('/edit') ? 'edit' : 'view';

  const { data: recipe, isLoading, isError } = useRecipeQuery(id);

  if (id && isLoading) {
    return (
      <div className="flex h-48 items-center justify-center text-sm text-muted-foreground">
        Loading recipe…
      </div>
    );
  }

  if (id && (isError || (recipe === undefined && !isLoading))) {
    return (
      <div className="flex h-48 flex-col items-center justify-center gap-1">
        <p className="font-medium">Recipe not found</p>
        <p className="text-muted-foreground text-sm">
          This recipe may have been deleted or the ID is invalid.
        </p>
      </div>
    );
  }

  if (mode === 'view' && recipe) {
    return <ViewWorkspace recipe={recipe} />;
  }

  return <FormWorkspace recipe={recipe ?? null} mode={mode as 'create' | 'edit'} />;
}
