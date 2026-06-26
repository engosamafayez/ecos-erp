import { useEffect, useState } from 'react';
import axios from 'axios';
import { zodResolver } from '@hookform/resolvers/zod';
import { useForm } from 'react-hook-form';
import {
  AlertCircle, Calendar, Edit, Globe, Hash, Package, Tag, Wifi, WifiOff, X,
} from 'lucide-react';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { EntityForm } from '@/components/crud';
import { StatusBadge } from '@/components/crud/status-badge';
import { Tabs } from '@/components/ds/tabs';
import { ChannelCell } from '@/features/products/components/badges/channel-badge';
import { SyncBadge } from '@/features/products/components/badges/sync-badge';
import { ProductFormFields } from '@/features/products/components/product-form';
import {
  productSchema,
  toFormValues,
  toPayload,
  type ProductFormValues,
} from '@/features/products/components/product-form-schema';
import { useCreateProduct, useUpdateProduct } from '@/features/products/hooks/use-products';
import type { Product, ProductType } from '@/features/products/types/product';
import { cn } from '@/lib/utils';

// ── Types ─────────────────────────────────────────────────────────────────────

export type DrawerMode = 'view' | 'edit';

type ProductDetailDrawerProps = {
  product: Product | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When set, the drawer opens in this mode. Defaults to 'view' if product exists, 'edit' if new. */
  initialMode?: DrawerMode;
  defaultType?: ProductType;
  onEdit?: (product: Product) => void;
};

// ── View helpers ──────────────────────────────────────────────────────────────

function DetailRow({ label, children, className }: { label: string; children: React.ReactNode; className?: string }) {
  return (
    <div className={cn('flex flex-col gap-0.5', className)}>
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children ?? <span className="text-muted-foreground">—</span>}</dd>
    </div>
  );
}

function DetailGrid({ children }: { children: React.ReactNode }) {
  return <dl className="grid grid-cols-2 gap-x-4 gap-y-4">{children}</dl>;
}

function formatDateTime(d: string | null): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(d));
}

function formatPrice(p: number | null): string {
  if (p == null) return '—';
  return p.toFixed(2);
}

// ── View mode tabs ────────────────────────────────────────────────────────────

function GeneralTab({ product }: { product: Product }) {
  return (
    <div className="flex flex-col gap-6 p-4">
      {product.image_url ? (
        <img src={product.image_url} alt={product.name} className="aspect-square w-full max-h-40 rounded-lg object-cover border" />
      ) : (
        <div className="flex h-32 items-center justify-center rounded-lg border bg-muted">
          <Package className="size-10 text-muted-foreground" />
        </div>
      )}
      <DetailGrid>
        <DetailRow label="SKU"><span className="font-mono">{product.sku}</span></DetailRow>
        <DetailRow label="Barcode">{product.barcode ? <span className="font-mono">{product.barcode}</span> : null}</DetailRow>
        <DetailRow label="Category">{product.category?.name}</DetailRow>
        <DetailRow label="Unit">{product.unit?.name}</DetailRow>
        <DetailRow label="Type">{product.product_type === 'finished_good' ? 'Finished Good' : 'Raw Material'}</DetailRow>
        <DetailRow label="Status"><StatusBadge status={product.is_active ? 'active' : 'inactive'} /></DetailRow>
      </DetailGrid>
      {product.short_description ? (
        <><Separator /><DetailRow label="Short Description"><p className="text-sm leading-relaxed">{product.short_description}</p></DetailRow></>
      ) : null}
      {product.description ? (
        <><Separator /><DetailRow label="Description"><p className="text-sm leading-relaxed text-muted-foreground">{product.description}</p></DetailRow></>
      ) : null}
    </div>
  );
}

function PricingTab({ product }: { product: Product }) {
  const hasDiscount = product.regular_price != null && product.sale_price != null && product.sale_price < product.regular_price;
  const discountPct = hasDiscount ? Math.round(((product.regular_price! - product.sale_price!) / product.regular_price!) * 100) : null;
  return (
    <div className="p-4">
      <div className="rounded-lg border bg-card p-4 flex gap-6 flex-wrap">
        <div className="flex flex-col">
          <span className="text-xs text-muted-foreground mb-1">Regular Price</span>
          <span className="text-2xl font-semibold tabular-nums">{formatPrice(product.regular_price)}</span>
        </div>
        {product.sale_price != null ? (
          <div className="flex flex-col">
            <span className="text-xs text-muted-foreground mb-1">Sale Price</span>
            <span className="text-2xl font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{formatPrice(product.sale_price)}</span>
          </div>
        ) : null}
        {discountPct !== null ? (
          <div className="flex flex-col">
            <span className="text-xs text-muted-foreground mb-1">Discount</span>
            <span className="text-2xl font-semibold tabular-nums text-amber-600 dark:text-amber-400">{discountPct}%</span>
          </div>
        ) : null}
      </div>
    </div>
  );
}

function InventoryTab({ product }: { product: Product }) {
  return (
    <div className="flex flex-col gap-4 p-4">
      <DetailGrid>
        <DetailRow label="Stock Status">
          {product.stock_status ? (
            <span className="capitalize">{product.stock_status.replace('ofstock', ' of stock').replace('onbackorder', 'on backorder')}</span>
          ) : null}
        </DetailRow>
      </DetailGrid>
      <Separator />
      <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
        <p className="flex items-center gap-2">
          <Package className="size-4 shrink-0" />
          Inventory quantities are managed in the <strong className="text-foreground">Inventory</strong> module.
        </p>
      </div>
    </div>
  );
}

function RecipeTab({ product }: { product: Product }) {
  if (product.product_type !== 'finished_good') {
    return (
      <div className="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
        <Tag className="size-10 mb-3" />
        <p className="text-sm">Raw materials do not have a bill of materials (recipe).</p>
      </div>
    );
  }
  return (
    <div className="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
      <Hash className="size-10 mb-3" />
      <p className="font-medium text-foreground">Bill of Materials</p>
      <p className="mt-1 text-sm">This product's recipe is managed in the Manufacturing module.</p>
    </div>
  );
}

function WooCommerceTab({ product }: { product: Product }) {
  const channels = product.channels ?? [];
  if (channels.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center p-8 text-center text-muted-foreground">
        <Globe className="size-10 mb-3" />
        <p className="text-sm">This product is not mapped to any sales channels.</p>
      </div>
    );
  }
  return (
    <div className="flex flex-col gap-4 p-4">
      <DetailGrid>
        <DetailRow label="Sync Status"><SyncBadge status={product.sync_status} /></DetailRow>
        {product.woo_sku ? <DetailRow label="WooCommerce SKU"><span className="font-mono text-xs">{product.woo_sku}</span></DetailRow> : null}
      </DetailGrid>
      <Separator />
      <div className="flex flex-col gap-2">
        <span className="text-xs font-medium text-muted-foreground">Channels</span>
        <ul className="flex flex-col gap-2">
          {channels.map((ch) => (
            <li key={ch.id} className="flex items-center justify-between rounded-md border bg-card p-3">
              <div className="flex items-center gap-2.5">
                <Globe className="size-4 text-muted-foreground" />
                <div>
                  <p className="text-sm font-medium">{ch.name}</p>
                  <p className="text-xs text-muted-foreground capitalize">{ch.platform}</p>
                </div>
              </div>
              <div className="flex flex-col items-end gap-0.5">
                {ch.is_synced ? (
                  <span className="flex items-center gap-1 text-xs text-emerald-600"><Wifi className="size-3" />Synced</span>
                ) : (
                  <span className="flex items-center gap-1 text-xs text-muted-foreground"><WifiOff className="size-3" />Not synced</span>
                )}
                {ch.last_synced_at ? <span className="text-[10px] text-muted-foreground">{formatDateTime(ch.last_synced_at)}</span> : null}
              </div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

function HistoryTab({ product }: { product: Product }) {
  return (
    <div className="p-4">
      <DetailGrid>
        <DetailRow label="Created">
          <span className="flex items-center gap-1.5"><Calendar className="size-3.5 text-muted-foreground" />{formatDateTime(product.created_at)}</span>
        </DetailRow>
        <DetailRow label="Last Updated">
          <span className="flex items-center gap-1.5"><Calendar className="size-3.5 text-muted-foreground" />{formatDateTime(product.updated_at)}</span>
        </DetailRow>
        <DetailRow label="Product ID" className="col-span-2">
          <span className="font-mono text-xs text-muted-foreground">{product.id}</span>
        </DetailRow>
      </DetailGrid>
    </div>
  );
}

function viewTabs(product: Product) {
  return [
    { key: 'general',     label: 'General',     content: <GeneralTab product={product} /> },
    { key: 'pricing',     label: 'Pricing',     content: <PricingTab product={product} /> },
    { key: 'inventory',   label: 'Inventory',   content: <InventoryTab product={product} /> },
    { key: 'recipe',      label: 'Recipe',      content: <RecipeTab product={product} /> },
    { key: 'woocommerce', label: 'WooCommerce', content: <WooCommerceTab product={product} /> },
    { key: 'history',     label: 'History',     content: <HistoryTab product={product} /> },
  ];
}

// ── Edit mode form ────────────────────────────────────────────────────────────

const FORM_ID = 'product-drawer-form';

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

// ── Main component ────────────────────────────────────────────────────────────

/**
 * Unified product drawer — view mode ↔ edit mode in the same Sheet.
 *
 * Part 2: Single drawer, mode switching (no close/reopen).
 * Part 3: Responsive width — 48% of viewport, min 520px, max 900px.
 */
export function ProductDetailDrawer({
  product,
  open,
  onOpenChange,
  initialMode,
  defaultType = 'finished_good',
}: ProductDetailDrawerProps) {
  const isNew = product === null;
  const [mode, setMode] = useState<DrawerMode>(initialMode ?? (isNew ? 'edit' : 'view'));
  const [activeTab, setActiveTab] = useState('general');
  const [serverError, setServerError] = useState<string | null>(null);

  const createProduct = useCreateProduct();
  const updateProduct = useUpdateProduct();
  const isPending = createProduct.isPending || updateProduct.isPending;

  const form = useForm<ProductFormValues>({
    resolver: zodResolver(productSchema),
    defaultValues: toFormValues(product, defaultType),
  });

  // Re-init when drawer opens or product changes
  useEffect(() => {
    if (open) {
      setMode(initialMode ?? (product === null ? 'edit' : 'view'));
      setActiveTab('general');
      setServerError(null);
      form.reset(toFormValues(product, defaultType));
    }
  }, [open, product, initialMode, defaultType, form]);

  const handleClose = () => {
    setServerError(null);
    onOpenChange(false);
  };

  const switchToEdit = () => {
    setServerError(null);
    form.reset(toFormValues(product, defaultType));
    setMode('edit');
  };

  const cancelEdit = () => {
    setServerError(null);
    if (isNew) {
      handleClose();
    } else {
      setMode('view');
    }
  };

  const handleSubmit = (values: ProductFormValues) => {
    setServerError(null);
    const payload = toPayload(values);
    const handlers = {
      onSuccess: () => {
        if (isNew) {
          handleClose();
        } else {
          setMode('view');
        }
      },
      onError: (error: unknown) => setServerError(extractMessage(error)),
    };

    if (!isNew && product) {
      updateProduct.mutate({ id: product.id, payload }, handlers);
    } else {
      createProduct.mutate(payload, handlers);
    }
  };

  if (!open) return null;

  const tabs = product ? viewTabs(product) : [];

  // Determine header content
  const headerTitle = mode === 'edit'
    ? (isNew ? 'New Product' : 'Edit Product')
    : (product?.name ?? '');

  return (
    <Sheet open={open} onOpenChange={handleClose}>
      {/* Part 3 — Responsive width: 48%, min 520px, max 900px */}
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 p-0 sm:max-w-none"
        style={{ width: '48%', minWidth: 520, maxWidth: 900 }}
      >
        {/* ── Header ── */}
        <SheetHeader className="border-b px-4 py-3">
          <div className="flex items-center gap-3">
            {/* Thumbnail (view mode only) */}
            {mode === 'view' && product ? (
              product.image_url ? (
                <img src={product.image_url} alt={product.name} className="size-10 shrink-0 rounded-md object-cover border" />
              ) : (
                <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted border">
                  <span className="text-[10px] font-bold uppercase text-muted-foreground">{product.name.slice(0, 2)}</span>
                </div>
              )
            ) : null}

            {/* Title */}
            <div className="flex-1 min-w-0">
              <SheetTitle className="truncate text-base">{headerTitle}</SheetTitle>
              {mode === 'view' && product ? (
                <div className="flex items-center gap-2 mt-0.5">
                  <span className="font-mono text-xs text-muted-foreground">{product.sku}</span>
                  <ChannelCell channels={product.channels} />
                </div>
              ) : null}
            </div>

            {/* Header actions */}
            <div className="flex shrink-0 items-center gap-1.5">
              {mode === 'view' && (
                <Button size="sm" variant="outline" onClick={switchToEdit}>
                  <Edit className="size-3.5" />
                  Edit
                </Button>
              )}
              <SheetClose asChild>
                <Button size="icon" variant="ghost" className="size-8">
                  <X className="size-4" />
                </Button>
              </SheetClose>
            </div>
          </div>
        </SheetHeader>

        {/* ── Body ── */}
        {mode === 'view' && product ? (
          <div className="flex-1 overflow-hidden">
            <Tabs
              tabs={tabs}
              activeKey={activeTab}
              onTabChange={setActiveTab}
              className="h-full"
              contentClassName="overflow-y-auto"
            />
          </div>
        ) : (
          /* ── Edit / Create form ── */
          <div className="flex flex-1 flex-col overflow-hidden">
            <div className="flex-1 overflow-y-auto p-4">
              {serverError ? (
                <Alert variant="destructive" className="mb-4">
                  <AlertCircle className="size-4" />
                  <AlertTitle>Unable to save</AlertTitle>
                  <AlertDescription>{serverError}</AlertDescription>
                </Alert>
              ) : null}
              <EntityForm form={form} id={FORM_ID} onSubmit={handleSubmit}>
                <ProductFormFields />
              </EntityForm>
            </div>

            {/* Sticky footer */}
            <div className="flex items-center justify-end gap-2 border-t bg-background p-4">
              <Button type="button" variant="outline" onClick={cancelEdit} disabled={isPending}>
                {isNew ? 'Cancel' : 'Back to view'}
              </Button>
              <Button type="submit" form={FORM_ID} disabled={isPending}>
                {isPending
                  ? 'Saving…'
                  : isNew
                    ? 'Create product'
                    : 'Save changes'}
              </Button>
            </div>
          </div>
        )}
      </SheetContent>
    </Sheet>
  );
}
