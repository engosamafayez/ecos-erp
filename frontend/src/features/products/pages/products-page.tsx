import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import {
  AlignJustify, Check, ChevronDown, Columns3,
  Download, Plus, RefreshCcw, Search, SlidersHorizontal, Tags, Upload, XCircle,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';

import { PageHeader, Pagination } from '@/components/crud';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem,
  DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useToast } from '@/components/ds/use-toast';
import type { DrawerMode } from '@/features/products/components/product-detail-drawer';
import { ProductDetailDrawer } from '@/features/products/components/product-detail-drawer';
import { ProductFilterBar, DEFAULT_FILTERS } from '@/features/products/components/product-filter-bar';
import type { ProductFilters } from '@/features/products/components/product-filter-bar';
import {
  ProductQuickStats, type StatFilter, type ProductStatsData,
} from '@/features/products/components/product-quick-stats';
import { ProductTable } from '@/features/products/components/product-table';
import type { RowDensity } from '@/features/products/components/product-table';
import type { ColKey } from '@/features/products/hooks/use-column-prefs';
import { TOGGLEABLE_COLUMNS, useColumnPrefs } from '@/features/products/hooks/use-column-prefs';
import { useDeleteProduct, useProductsQuery, useToggleProductStatus } from '@/features/products/hooks/use-products';
import { productsService } from '@/features/products/services/products-service';
import type { Product, ProductSortField, SortDirection } from '@/features/products/types/product';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 20;
const DENSITY_KEY = 'ecos_products_density';
const STATS_PAGE = 1;

// ── Stats ─────────────────────────────────────────────────────────────────────

function applyStatFilter(stat: StatFilter | null): Partial<ProductFilters> {
  if (!stat) return DEFAULT_FILTERS;
  const base = { ...DEFAULT_FILTERS };
  switch (stat.type) {
    case 'status':       return { ...base, status: stat.value };
    case 'is_published': return { ...base, is_published: stat.value };
    case 'low_stock':    return { ...base, low_stock: stat.value };
    case 'not_synced':   return { ...base, not_synced: stat.value };
    case 'product_type': return { ...base, product_type: stat.value };
    default:             return base;
  }
}

function useProductStats(): ProductStatsData {
  const base = { product_type: 'finished_good' as const, per_page: STATS_PAGE, page: 1 };
  const { data: totalData }     = useQuery({ queryKey: ['ps', 'total'],     queryFn: () => productsService.list(base),                            staleTime: 30_000 });
  const { data: publishedData } = useQuery({ queryKey: ['ps', 'published'], queryFn: () => productsService.list({ ...base, is_published: true }), staleTime: 30_000 });
  const { data: lowStockData }  = useQuery({ queryKey: ['ps', 'lowStock'],  queryFn: () => productsService.list({ ...base, low_stock: true }),    staleTime: 30_000 });
  const { data: inactiveData }  = useQuery({ queryKey: ['ps', 'inactive'],  queryFn: () => productsService.list({ ...base, status: 'inactive' }), staleTime: 30_000 });
  const { data: notSyncedData } = useQuery({ queryKey: ['ps', 'notSynced'], queryFn: () => productsService.list({ ...base, not_synced: true }),   staleTime: 30_000 });
  return {
    total:     totalData?.meta.total     ?? 0,
    published: publishedData?.meta.total ?? 0,
    lowStock:  lowStockData?.meta.total  ?? 0,
    notSynced: notSyncedData?.meta.total ?? 0,
    inactive:  inactiveData?.meta.total  ?? 0,
  };
}

function readDensity(): RowDensity {
  try {
    const v = localStorage.getItem(DENSITY_KEY);
    if (v === 'compact' || v === 'comfortable') return v;
  } catch { /* ignore */ }
  return 'comfortable';
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function ProductsPage() {
  const { t } = useTranslation('products');
  const { t: tCommon } = useTranslation('common');
  const { toast } = useToast();
  const location = useLocation();
  const navigate = useNavigate();

  // ── Column prefs (Part 4) ─────────────────────────────────────────────
  const { visible, setVisible, widths, setWidth, resetPrefs } = useColumnPrefs();

  // ── Row density (Part 7) ──────────────────────────────────────────────
  const [density, setDensityState] = useState<RowDensity>(readDensity);
  const toggleDensity = () => {
    setDensityState((d) => {
      const next: RowDensity = d === 'comfortable' ? 'compact' : 'comfortable';
      localStorage.setItem(DENSITY_KEY, next);
      return next;
    });
  };

  // ── Search ────────────────────────────────────────────────────────────
  const [search, setSearch]       = useState('');
  const [searchKey, setSearchKey] = useState(0);
  const searchRef = useRef<HTMLInputElement>(null);

  // ── Filters ───────────────────────────────────────────────────────────
  const [filters, setFilters]   = useState<ProductFilters>(DEFAULT_FILTERS);
  const [activeStat, setActiveStat] = useState<StatFilter | null>(null);
  const [page, setPage]         = useState(1);
  const [sort, setSort]         = useState<{ field: ProductSortField; direction: SortDirection }>({
    field: 'updated_at', direction: 'desc',
  });

  // ── Selection ─────────────────────────────────────────────────────────
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());

  // ── Arrow-key row focus (UI-002) ──────────────────────────────────────
  const [focusedRowIndex, setFocusedRowIndex] = useState<number | null>(null);

  // ── Drawer (Part 2 — unified drawer) ─────────────────────────────────
  const [drawerProduct, setDrawerProduct] = useState<Product | null>(null);
  const [drawerOpen, setDrawerOpen]       = useState(false);
  const [drawerMode, setDrawerMode]       = useState<DrawerMode>('view');

  function openView(product: Product) {
    setDrawerProduct(product);
    setDrawerMode('view');
    setDrawerOpen(true);
  }
  function openEdit(product: Product) {
    setDrawerProduct(product);
    setDrawerMode('edit');
    setDrawerOpen(true);
  }
  function openCreate() {
    setDrawerProduct(null);
    setDrawerMode('edit');
    setDrawerOpen(true);
  }

  // ── Stats ─────────────────────────────────────────────────────────────
  const stats = useProductStats();

  // ── Query ─────────────────────────────────────────────────────────────
  const params = useMemo(() => ({
    search: search || undefined,
    product_type: 'finished_good' as const,
    category_id:  filters.category_id  ?? undefined,
    warehouse_id: filters.warehouse_id ?? undefined,
    channel_id:   filters.channel_id   ?? undefined,
    status:       filters.status !== 'all' ? filters.status : undefined,
    is_published: filters.is_published  ?? undefined,
    low_stock:    filters.low_stock     || undefined,
    out_of_stock: filters.out_of_stock  || undefined,
    has_images:   filters.has_images    ?? undefined,
    not_synced:   filters.not_synced    || undefined,
    page,
    per_page:  PER_PAGE,
    sort_by:   sort.field,
    sort_dir:  sort.direction,
  }), [search, filters, page, sort]);

  const { data, isLoading, isError, isFetching, refetch } = useProductsQuery(params);
  const deleteProduct = useDeleteProduct();
  const toggleStatus = useToggleProductStatus();
  const products = data?.items ?? [];
  const meta = data?.meta;

  const hasFilters =
    Boolean(search) ||
    filters.category_id !== null || filters.warehouse_id !== null || filters.channel_id !== null ||
    filters.status !== 'all' || filters.product_type !== null || filters.is_published !== null ||
    filters.low_stock || filters.out_of_stock || filters.has_images !== null || filters.not_synced;

  // Reset row focus when the list contents change (search/filter/page)
  useEffect(() => { setFocusedRowIndex(null); }, [search, filters, page]);

  // ── Handlers ──────────────────────────────────────────────────────────
  const handleSearch = (value: string) => { setSearch(value); setPage(1); };

  const handleFilterChange = (delta: Partial<ProductFilters>) => {
    setFilters((p) => ({ ...p, ...delta }));
    setPage(1);
  };

  const clearFilters = () => {
    setSearch('');
    setSearchKey((k) => k + 1);
    setFilters(DEFAULT_FILTERS);
    setActiveStat(null);
    setPage(1);
  };

  const handleStatFilter = (stat: StatFilter | null) => {
    setActiveStat(stat);
    setFilters(applyStatFilter(stat) as ProductFilters);
    setPage(1);
    setSearch('');
    setSearchKey((k) => k + 1);
  };

  const handleSortChange = (field: ProductSortField) => {
    setSort((p) => p.field === field
      ? { field, direction: p.direction === 'asc' ? 'desc' : 'asc' }
      : { field, direction: 'asc' });
    setPage(1);
  };

  const handleSelectRow = (id: string, checked: boolean) => {
    setSelectedIds((p) => { const n = new Set(p); if (checked) n.add(id); else n.delete(id); return n; });
  };
  const handleSelectAll = (checked: boolean) => setSelectedIds(checked ? new Set(products.map((p) => p.id)) : new Set());

  const bulkAction = (label: string) => {
    if (!selectedIds.size) return;
    toast({ type: 'info', title: label, description: `${selectedIds.size} product(s) queued.` });
    setSelectedIds(new Set());
  };

  const handleImport = () => {
    toast({ type: 'info', title: 'Import', description: 'Import feature coming soon.' });
  };

  // ── UI-003: open create drawer when navigated here with { openCreate: true } ──
  useEffect(() => {
    if ((location.state as Record<string, unknown> | null)?.openCreate) {
      openCreate();
      navigate(location.pathname, { replace: true, state: {} });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ── UI-002: Keyboard shortcuts + arrow navigation ─────────────────────
  // Use refs to read current state inside the stable event listener.
  const stateRef = useRef({ products, drawerOpen, focusedRowIndex });
  useEffect(() => {
    stateRef.current = { products, drawerOpen, focusedRowIndex };
  }, [products, drawerOpen, focusedRowIndex]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      const inInput = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
      const { products: prods, drawerOpen: isOpen, focusedRowIndex: fi } = stateRef.current;

      // Ctrl+K or / → focus search
      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !inInput)) {
        e.preventDefault();
        searchRef.current?.focus();
        searchRef.current?.select();
        return;
      }

      // Ctrl+N → new product
      if (e.key === 'n' && (e.ctrlKey || e.metaKey) && !inInput) {
        e.preventDefault();
        openCreate();
        return;
      }

      // Esc → close drawer first, else clear search / focus
      if (e.key === 'Escape' && !inInput) {
        if (isOpen) { setDrawerOpen(false); return; }
        clearFilters();
        setFocusedRowIndex(null);
        return;
      }

      // Arrow Down → move focus down the table
      if (e.key === 'ArrowDown' && !inInput && prods.length > 0) {
        e.preventDefault();
        setFocusedRowIndex(fi === null ? 0 : Math.min(fi + 1, prods.length - 1));
        return;
      }

      // Arrow Up → move focus up the table
      if (e.key === 'ArrowUp' && !inInput && prods.length > 0) {
        e.preventDefault();
        setFocusedRowIndex(fi === null ? 0 : Math.max(fi - 1, 0));
        return;
      }

      // Enter → open focused row
      if (e.key === 'Enter' && !inInput && fi !== null) {
        e.preventDefault();
        const product = prods[fi];
        if (product) openView(product);
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="flex flex-col gap-5">
      {/* ── Page Header ── */}
      <PageHeader
        title={t('finishedGoods.title')}
        subtitle={t('finishedGoods.subtitle')}
        breadcrumbs={[{ label: tCommon('home'), to: ROUTES.dashboard }, { label: t('finishedGoods.title') }]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={handleImport}>
              <Upload className="size-3.5" />
              {t('actions.import')}
            </Button>
            <Button size="sm" onClick={openCreate}>
              <Plus className="size-3.5" />
              {t('actions.new')}
            </Button>
          </div>
        }
      />

      {/* ── Quick Stats ── */}
      <ProductQuickStats stats={stats} activeFilter={activeStat} onFilterChange={handleStatFilter} />

      {/* ── Toolbar ── */}
      <div className="flex flex-wrap items-center gap-2">
        {/* Search (Part 5 — / and Ctrl+K focus this) */}
        <div className="relative flex-1 min-w-48 max-w-sm">
          <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <input
            key={searchKey}
            ref={searchRef}
            type="search"
            placeholder={`${t('search')} · / or Ctrl+K`}
            defaultValue={search}
            onKeyDown={(e) => {
              if (e.key === 'Enter') handleSearch(e.currentTarget.value);
              if (e.key === 'Escape') { clearFilters(); e.currentTarget.blur(); }
            }}
            onBlur={(e) => handleSearch(e.currentTarget.value)}
            className="h-9 w-full rounded-md border border-input bg-background ps-8 pe-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          />
        </div>

        {/* Right-side controls */}
        <div className="ms-auto flex items-center gap-1.5">
          {/* Bulk actions */}
          {selectedIds.size > 0 ? (
            <>
              <span className="text-sm text-muted-foreground">{selectedIds.size} selected</span>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="sm">
                    <Tags className="size-3.5" />
                    Bulk Actions
                    <ChevronDown className="size-3.5" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-48">
                  <DropdownMenuItem onClick={() => bulkAction('Activate')}>Activate</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => bulkAction('Deactivate')}>Deactivate</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => bulkAction('Publish to channels')}>Publish to channels</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => bulkAction('Assign category')}>Assign category</DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onClick={() => bulkAction('Export selected')}>
                    <Download className="size-3.5" />Export selected
                  </DropdownMenuItem>
                  <DropdownMenuItem variant="destructive" onClick={() => bulkAction('Archive')}>
                    <XCircle className="size-3.5" />Archive selected
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            </>
          ) : null}

          {/* Export all */}
          <Button variant="ghost" size="sm" onClick={() => bulkAction('Export all')}>
            <Download className="size-3.5" />
            {t('actions.export')}
          </Button>

          {/* Column picker (Part 4) */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" aria-label="Column preferences">
                <Columns3 className="size-3.5" />
                Columns
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuLabel>Show / Hide Columns</DropdownMenuLabel>
              <DropdownMenuSeparator />
              {TOGGLEABLE_COLUMNS.map((col) => (
                <DropdownMenuCheckboxItem
                  key={col.key}
                  checked={visible[col.key as ColKey]}
                  onCheckedChange={(v) => setVisible(col.key as ColKey, v)}
                >
                  {col.label}
                </DropdownMenuCheckboxItem>
              ))}
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={resetPrefs}>
                <RefreshCcw className="size-3.5" />
                Reset to defaults
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>

          {/* Row density toggle (Part 7) */}
          <Button
            variant="outline"
            size="sm"
            onClick={toggleDensity}
            title={density === 'comfortable' ? 'Switch to compact' : 'Switch to comfortable'}
          >
            {density === 'comfortable'
              ? <SlidersHorizontal className="size-3.5" />
              : <AlignJustify className="size-3.5" />}
            <span className="hidden sm:inline">
              {density === 'comfortable' ? 'Compact' : 'Comfortable'}
            </span>
            {density === 'compact'
              ? <Check className="size-3 text-primary" />
              : null}
          </Button>

          {/* Refresh */}
          <Button variant="ghost" size="icon" className="size-9" onClick={() => void refetch()} disabled={isFetching}>
            <RefreshCcw className={`size-3.5 ${isFetching ? 'animate-spin' : ''}`} />
          </Button>
        </div>
      </div>

      {/* ── Filters ── */}
      <ProductFilterBar filters={filters} onChange={handleFilterChange} onClear={clearFilters} />

      {/* ── Table (Parts 4 + 7) ── */}
      <ProductTable
        products={products}
        isLoading={isLoading}
        isError={isError}
        sort={sort}
        onSortChange={handleSortChange}
        selectedIds={selectedIds}
        onSelectRow={handleSelectRow}
        onSelectAll={handleSelectAll}
        onView={openView}
        onEdit={openEdit}
        onStatusToggle={(p) => toggleStatus.mutate(p)}
        focusedRowId={focusedRowIndex !== null ? (products[focusedRowIndex]?.id ?? null) : null}
        onDuplicate={(p) => toast({ type: 'info', title: 'Duplicate', description: `Duplicating "${p.name}"…` })}
        onPublish={(p) => toast({ type: 'info', title: 'Publish',   description: `Publishing "${p.name}"…` })}
        onDelete={(p) => deleteProduct.mutate(p.id, {
          onSuccess: () => toast({ type: 'success', title: 'Archived', description: `"${p.name}" has been archived.` }),
          onError:   () => toast({ type: 'error',   title: 'Error',    description: 'Failed to archive product.' }),
        })}
        hasFilters={hasFilters}
        onCreateProduct={openCreate}
        onImportProducts={handleImport}
        onClearFilters={clearFilters}
        visible={visible}
        widths={widths}
        onWidthChange={setWidth}
        density={density}
      />

      {/* ── Pagination ── */}
      {meta && products.length > 0 ? (
        <Pagination
          meta={{ page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page }}
          onPageChange={setPage}
        />
      ) : null}

      {/* ── Unified Product Drawer (Part 2 + 3) ── */}
      <ProductDetailDrawer
        product={drawerProduct}
        open={drawerOpen}
        onOpenChange={(open) => { setDrawerOpen(open); if (!open) setDrawerProduct(null); }}
        initialMode={drawerMode}
      />
    </div>
  );
}
