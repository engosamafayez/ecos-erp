import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Download, Plus, Search, Upload } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';

import { PageHeader } from '@/components/crud';
import {
  ColumnVisibilityMenu,
  SmartToolbar,
  useColumnVisibility,
  useRowSelection,
} from '@/components/data-grid';
import type { GridPaginationConfig, GridSortState } from '@/components/data-grid/types';
import { EmptyState } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ds/use-toast';

import type { DrawerMode } from '@/features/products/components/product-detail-drawer';
import { ProductDetailDrawer } from '@/features/products/components/product-detail-drawer';
import { ProductFilterBar, DEFAULT_FILTERS } from '@/features/products/components/product-filter-bar';
import type { ProductFilters } from '@/features/products/components/product-filter-bar';
import { PRODUCT_COLUMN_META } from '@/features/products/components/product-column-meta';
import {
  ProductQuickStats,
  type StatFilter,
  type ProductStatsData,
} from '@/features/products/components/product-quick-stats';
import { ProductTable } from '@/features/products/components/product-table';
import {
  useDeleteProduct,
  useProductsQuery,
  useToggleProductStatus,
} from '@/features/products/hooks/use-products';
import { productsService } from '@/features/products/services/products-service';
import type { Product, ProductSortField } from '@/features/products/types/product';
import { ROUTES } from '@/router/routes';

// ── Constants ─────────────────────────────────────────────────────────────────

const PER_PAGE = 20;
const COLUMN_STORAGE_KEY = 'ecos_products_cols_v2';

// ── Stat filter helpers ───────────────────────────────────────────────────────

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

// ── Stats (parallel queries) ──────────────────────────────────────────────────

function useProductStats(): ProductStatsData {
  const base = { product_type: 'finished_good' as const, per_page: 1, page: 1 };
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

// ── Page ──────────────────────────────────────────────────────────────────────

export function ProductsPage() {
  const { t } = useTranslation('products');
  const { t: tCommon } = useTranslation('common');
  const { toast } = useToast();
  const location = useLocation();
  const navigate = useNavigate();

  // ── Column visibility (framework hook with localStorage persistence) ───────
  const { visibility: columnVisibility, toggle: toggleColumn, reset: resetColumns } =
    useColumnVisibility(COLUMN_STORAGE_KEY, PRODUCT_COLUMN_META);

  // ── Search ────────────────────────────────────────────────────────────────
  const [search, setSearch]       = useState('');
  const [searchKey, setSearchKey] = useState(0);
  const searchRef = useRef<HTMLInputElement>(null);

  // ── Filters ───────────────────────────────────────────────────────────────
  const [filters, setFilters]   = useState<ProductFilters>(DEFAULT_FILTERS);
  const [activeStat, setActiveStat] = useState<StatFilter | null>(null);
  const [page, setPage]         = useState(1);
  const [sort, setSort]         = useState<GridSortState>({ field: 'updated_at', direction: 'desc' });

  // ── Row selection (framework hook) ────────────────────────────────────────
  const { data: productsForSelection } = useProductsQuery(
    useMemo(() => ({ product_type: 'finished_good' as const, per_page: PER_PAGE, page }), [page]),
  );
  const selection = useRowSelection({
    items: productsForSelection?.items ?? [],
    getId: (p) => p.id,
  });

  // ── Arrow-key row focus ───────────────────────────────────────────────────
  const [focusedRowIndex, setFocusedRowIndex] = useState<number | null>(null);

  // ── Drawer ────────────────────────────────────────────────────────────────
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

  // ── Stats ─────────────────────────────────────────────────────────────────
  const stats = useProductStats();

  // ── Main query ────────────────────────────────────────────────────────────
  const params = useMemo(() => ({
    search:       search || undefined,
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
    sort_by:   sort.field as ProductSortField,
    sort_dir:  sort.direction,
  }), [search, filters, page, sort]);

  const { data, isLoading, isError, isFetching, refetch } = useProductsQuery(params);
  const deleteProduct   = useDeleteProduct();
  const toggleStatus    = useToggleProductStatus();
  const products        = data?.items ?? [];
  const meta            = data?.meta;

  const hasFilters =
    Boolean(search) ||
    filters.category_id !== null || filters.warehouse_id !== null || filters.channel_id !== null ||
    filters.status !== 'all' || filters.product_type !== null || filters.is_published !== null ||
    filters.low_stock || filters.out_of_stock || filters.has_images !== null || filters.not_synced;

  // Reset row focus when list contents change
  useEffect(() => { setFocusedRowIndex(null); }, [search, filters, page]);

  // ── Handlers ──────────────────────────────────────────────────────────────
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

  const handleSortChange = (field: string) => {
    setSort((p) => p.field === field
      ? { field, direction: p.direction === 'asc' ? 'desc' : 'asc' }
      : { field, direction: 'asc' });
    setPage(1);
  };

  const handleBulkAction = (label: string) => {
    if (!selection.selectedCount) return;
    toast({ type: 'info', title: label, description: `${selection.selectedCount} product(s) queued.` });
    selection.clearSelection();
  };

  const handleImport = () => {
    toast({ type: 'info', title: 'Import', description: 'Import feature coming soon.' });
  };

  // ── Open-create navigation effect ─────────────────────────────────────────
  useEffect(() => {
    if ((location.state as Record<string, unknown> | null)?.openCreate) {
      openCreate();
      navigate(location.pathname, { replace: true, state: {} });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ── Keyboard shortcuts ────────────────────────────────────────────────────
  const stateRef = useRef({ products, drawerOpen, focusedRowIndex });
  useEffect(() => {
    stateRef.current = { products, drawerOpen, focusedRowIndex };
  }, [products, drawerOpen, focusedRowIndex]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      const inInput = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
      const { products: prods, drawerOpen: isOpen, focusedRowIndex: fi } = stateRef.current;

      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !inInput)) {
        e.preventDefault();
        searchRef.current?.focus();
        searchRef.current?.select();
        return;
      }
      if (e.key === 'n' && (e.ctrlKey || e.metaKey) && !inInput) {
        e.preventDefault();
        openCreate();
        return;
      }
      if (e.key === 'Escape' && !inInput) {
        if (isOpen) { setDrawerOpen(false); return; }
        clearFilters();
        setFocusedRowIndex(null);
        return;
      }
      if (e.key === 'ArrowDown' && !inInput && prods.length > 0) {
        e.preventDefault();
        setFocusedRowIndex(fi === null ? 0 : Math.min(fi + 1, prods.length - 1));
        return;
      }
      if (e.key === 'ArrowUp' && !inInput && prods.length > 0) {
        e.preventDefault();
        setFocusedRowIndex(fi === null ? 0 : Math.max(fi - 1, 0));
        return;
      }
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

  // ── Pagination config ─────────────────────────────────────────────────────
  const pagination: GridPaginationConfig | undefined = meta ? {
    meta: {
      page:     meta.current_page,
      perPage:  meta.per_page,
      total:    meta.total,
      lastPage: meta.last_page,
    },
    onPageChange: setPage,
  } : undefined;

  // ── Render ────────────────────────────────────────────────────────────────
  return (
    <div className="flex flex-col gap-5">

      {/* ── Page Header ── */}
      <PageHeader
        title={t('finishedGoods.title')}
        subtitle={t('finishedGoods.subtitle')}
        breadcrumbs={[
          { label: tCommon('home'), to: ROUTES.dashboard },
          { label: t('finishedGoods.title') },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <span className="hidden text-sm text-muted-foreground sm:block">
              {meta ? `${meta.total.toLocaleString()} products` : null}
            </span>
            <Button size="sm" onClick={openCreate}>
              <Plus className="size-3.5" />
              {t('actions.new')}
            </Button>
          </div>
        }
      />

      {/* ── Quick Stats ── */}
      <ProductQuickStats
        stats={stats}
        activeFilter={activeStat}
        onFilterChange={handleStatFilter}
      />

      {/* ── Smart Toolbar ── */}
      <SmartToolbar
        primaryAction={{
          label: t('actions.new'),
          onClick: openCreate,
          icon: Plus,
        }}
        secondaryActions={[
          {
            key: 'import',
            label: t('actions.import'),
            icon: Upload,
            onClick: handleImport,
            hideOnMobile: true,
          },
          {
            key: 'export',
            label: t('actions.export'),
            icon: Download,
            onClick: () => handleBulkAction('Export all'),
            hideOnMobile: true,
          },
        ]}
        bulkActions={[
          { key: 'activate',         label: 'Activate',             onClick: () => handleBulkAction('Activate') },
          { key: 'deactivate',       label: 'Deactivate',           onClick: () => handleBulkAction('Deactivate') },
          { key: 'publish',          label: 'Publish to channels',  onClick: () => handleBulkAction('Publish to channels') },
          { key: 'assign-category',  label: 'Assign category',      onClick: () => handleBulkAction('Assign category'), separator: true },
          { key: 'export-selected',  label: 'Export selected',      onClick: () => handleBulkAction('Export selected'), separator: true },
        ]}
        bulkActionsLabel="Bulk Actions"
        selectedCount={selection.selectedCount}
        onRefresh={() => void refetch()}
        isFetching={isFetching}
        viewControls={
          <div className="flex items-center gap-1.5">
            {/* Search — Ctrl+K or / focuses this */}
            <div className="relative hidden sm:block">
              <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
              <input
                key={searchKey}
                ref={searchRef}
                type="search"
                placeholder={`${t('search')} · /`}
                defaultValue={search}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') handleSearch(e.currentTarget.value);
                  if (e.key === 'Escape') { clearFilters(); e.currentTarget.blur(); }
                }}
                onBlur={(e) => handleSearch(e.currentTarget.value)}
                className="h-8 w-48 rounded-md border border-input bg-background ps-8 pe-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring lg:w-64"
              />
            </div>

            {/* Column Manager */}
            <ColumnVisibilityMenu
              columns={PRODUCT_COLUMN_META}
              visibility={columnVisibility}
              onToggle={toggleColumn}
              onReset={resetColumns}
            />
          </div>
        }
      />

      {/* Search (mobile — shown below toolbar on small screens) */}
      <div className="relative sm:hidden">
        <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
        <input
          key={`m-${searchKey}`}
          type="search"
          placeholder={t('search')}
          defaultValue={search}
          onKeyDown={(e) => {
            if (e.key === 'Enter') handleSearch(e.currentTarget.value);
          }}
          onBlur={(e) => handleSearch(e.currentTarget.value)}
          className="h-9 w-full rounded-md border border-input bg-background ps-8 pe-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
        />
      </div>

      {/* ── Filter Bar ── */}
      <ProductFilterBar
        filters={filters}
        onChange={handleFilterChange}
        onClear={clearFilters}
      />

      {/* ── Product Grid ── */}
      <ProductTable
        products={products}
        isLoading={isLoading}
        isError={isError}
        sort={sort}
        onSortChange={handleSortChange}
        selection={selection}
        onView={openView}
        onEdit={openEdit}
        onDelete={(p) =>
          deleteProduct.mutate(p.id, {
            onSuccess: () => toast({ type: 'success', title: 'Archived',  description: `"${p.name}" has been archived.` }),
            onError:   () => toast({ type: 'error',   title: 'Error',     description: 'Failed to archive product.' }),
          })
        }
        onStatusToggle={(p) => toggleStatus.mutate(p)}
        focusedRowId={focusedRowIndex !== null ? (products[focusedRowIndex]?.id ?? null) : null}
        columnVisibility={columnVisibility}
        pagination={pagination}
        emptyState={
          hasFilters ? (
            <EmptyState title="No products match your filters" />
          ) : (
            <EmptyState title="No products yet" />
          )
        }
      />

      {/* ── Product Detail Drawer ── */}
      <ProductDetailDrawer
        product={drawerProduct}
        open={drawerOpen}
        onOpenChange={(open) => { setDrawerOpen(open); if (!open) setDrawerProduct(null); }}
        initialMode={drawerMode}
      />
    </div>
  );
}
