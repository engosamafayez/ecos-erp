import { useMemo, useState } from 'react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';

import {
  ActionMenu,
  ConfirmDialog,
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
  StatusBadge,
} from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { CategorySelect } from '@/features/products/components/category-select';
import { ProductFormDrawer } from '@/features/products/components/product-form-drawer';
import { UnitSelect } from '@/features/products/components/unit-select';
import { useProductsQuery, useDeleteProduct } from '@/features/products/hooks/use-products';
import type {
  Product,
  ProductSortField,
  ProductStatusFilter,
  ProductType,
} from '@/features/products/types/product';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

const TYPE_LABELS: Record<ProductType, string> = {
  finished_good: 'Finished Good',
  raw_material: 'Raw Material',
};

type ProductsViewProps = {
  /** Restricts the list and defaults new records to this product type. */
  productType: ProductType;
  title: string;
  subtitle: string;
  breadcrumbLabel: string;
  searchPlaceholder: string;
  createLabel: string;
  entityNoun: string;
};

/**
 * Reusable products list view. Both the Products and Raw Materials pages render
 * this with a different `productType` — a single source of CRUD logic.
 */
export function ProductsView({
  productType,
  title,
  subtitle,
  breadcrumbLabel,
  searchPlaceholder,
  createLabel,
  entityNoun,
}: ProductsViewProps) {
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState<string | null>(null);
  const [unitFilter, setUnitFilter] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<ProductStatusFilter>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: ProductSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerProduct, setDrawerProduct] = useState<Product | null>(null);
  const [deleting, setDeleting] = useState<Product | null>(null);

  const params = useMemo(
    () => ({
      search: search || undefined,
      product_type: productType,
      category_id: categoryFilter || undefined,
      unit_id: unitFilter || undefined,
      status: statusFilter,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, productType, categoryFilter, unitFilter, statusFilter, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useProductsQuery(params);
  const deleteProduct = useDeleteProduct();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const handleSort = (field: string) => {
    setSort((current) =>
      current.field === field
        ? {
            field: field as ProductSortField,
            direction: current.direction === 'asc' ? 'desc' : 'asc',
          }
        : { field: field as ProductSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const openCreate = () => {
    setDrawerProduct(null);
    setDrawerOpen(true);
  };

  const openEdit = (product: Product) => {
    setDrawerProduct(product);
    setDrawerOpen(true);
  };

  const columns: ColumnDef<Product>[] = [
    {
      key: 'sku',
      header: 'SKU',
      sortable: true,
      cell: (p) => <span className="font-medium">{p.sku}</span>,
    },
    {
      key: 'barcode',
      header: 'Barcode',
      cell: (p) => <span className="text-muted-foreground">{p.barcode ?? '—'}</span>,
    },
    { key: 'name', header: 'Name', sortable: true, cell: (p) => p.name },
    { key: 'category', header: 'Category', cell: (p) => p.category?.name ?? '—' },
    { key: 'unit', header: 'Unit', cell: (p) => p.unit?.name ?? '—' },
    {
      key: 'product_type',
      header: 'Type',
      sortable: true,
      cell: (p) => <Badge variant="outline">{TYPE_LABELS[p.product_type]}</Badge>,
    },
    {
      key: 'is_active',
      header: 'Status',
      sortable: true,
      cell: (p) => <StatusBadge status={p.is_active ? 'active' : 'inactive'} />,
    },
  ];

  const confirmDelete = () => {
    if (!deleting) {
      return;
    }
    deleteProduct.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={title}
        subtitle={subtitle}
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: breadcrumbLabel }]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            {createLabel}
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder={searchPlaceholder}
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => {
              setCategoryFilter(null);
              setUnitFilter(null);
              setStatusFilter('all');
              setPage(1);
            }}
            filterPanel={
              <>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Category</span>
                  <CategorySelect
                    value={categoryFilter}
                    onChange={(value) => {
                      setCategoryFilter(value);
                      setPage(1);
                    }}
                    placeholder="All categories"
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Unit</span>
                  <UnitSelect
                    value={unitFilter}
                    onChange={(value) => {
                      setUnitFilter(value);
                      setPage(1);
                    }}
                    placeholder="All units"
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Status</span>
                  <select
                    value={statusFilter}
                    onChange={(event) => {
                      setStatusFilter(event.target.value as ProductStatusFilter);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </>
            }
          />

          <EntityTable<Product>
            columns={columns}
            data={items}
            getRowId={(product) => product.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(product) => (
              <ActionMenu
                label={`Actions for ${product.name}`}
                items={[
                  { key: 'view', label: 'View', icon: Eye, onSelect: () => openEdit(product) },
                  { key: 'edit', label: 'Edit', icon: Pencil, onSelect: () => openEdit(product) },
                  {
                    key: 'delete',
                    label: 'Delete',
                    icon: Trash2,
                    variant: 'destructive',
                    onSelect: () => setDeleting(product),
                  },
                ]}
              />
            )}
          />

          {meta ? (
            <Pagination
              meta={{
                page: meta.current_page,
                perPage: meta.per_page,
                total: meta.total,
                lastPage: meta.last_page,
              }}
              onPageChange={setPage}
            />
          ) : null}
        </CardContent>
      </Card>

      <ProductFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) {
            setDrawerProduct(null);
          }
        }}
        product={drawerProduct}
        defaultType={productType}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) {
            setDeleting(null);
          }
        }}
        title={`Delete ${entityNoun}`}
        description={
          <>
            This will soft-delete{' '}
            <span className="text-foreground font-medium">{deleting?.name}</span>. It can be
            restored later.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteProduct.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
