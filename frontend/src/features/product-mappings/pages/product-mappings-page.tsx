import { useMemo, useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';

import {
  ActionMenu,
  ConfirmDialog,
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
} from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ProductMappingFormDrawer } from '@/features/product-mappings/components/product-mapping-form-drawer';
import { SyncStatusBadge } from '@/features/product-mappings/components/sync-status-badge';
import {
  useDeleteProductMapping,
  useProductMappingsQuery,
} from '@/features/product-mappings/hooks/use-product-mappings';
import type {
  ProductMapping,
  ProductMappingSortField,
  SyncStatus,
} from '@/features/product-mappings/types/product-mapping';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

export function ProductMappingsPage() {
  const [search, setSearch] = useState('');
  const [syncStatusFilter, setSyncStatusFilter] = useState<SyncStatus | ''>('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: ProductMappingSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerMapping, setDrawerMapping] = useState<ProductMapping | null>(null);
  const [deleting, setDeleting] = useState<ProductMapping | null>(null);

  const params = useMemo(
    () => ({
      search: search || undefined,
      sync_status: syncStatusFilter || undefined,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, syncStatusFilter, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useProductMappingsQuery(params);
  const deleteMapping = useDeleteProductMapping();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSort = (field: string) => {
    setSort((curr) =>
      curr.field === field
        ? {
            field: field as ProductMappingSortField,
            direction: curr.direction === 'asc' ? 'desc' : 'asc',
          }
        : { field: field as ProductMappingSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const openCreate = () => {
    setDrawerMapping(null);
    setDrawerOpen(true);
  };

  const openEdit = (mapping: ProductMapping) => {
    setDrawerMapping(mapping);
    setDrawerOpen(true);
  };

  const columns: ColumnDef<ProductMapping>[] = [
    {
      key: 'product',
      header: 'Product',
      cell: (m) => (
        <div className="flex flex-col">
          <span className="font-medium">{m.product?.name ?? '—'}</span>
          <span className="text-muted-foreground text-xs">{m.product?.sku ?? ''}</span>
        </div>
      ),
    },
    {
      key: 'channel',
      header: 'Channel',
      cell: (m) => (
        <div className="flex flex-col">
          <span>{m.channel?.name ?? '—'}</span>
          <span className="text-muted-foreground text-xs">{m.channel?.platform_label ?? ''}</span>
        </div>
      ),
    },
    {
      key: 'external_product_id',
      header: 'External Product ID',
      sortable: true,
      cell: (m) => <span className="font-mono text-sm">{m.external_product_id}</span>,
    },
    {
      key: 'external_sku',
      header: 'External SKU',
      sortable: true,
      cell: (m) => (
        <span className="text-muted-foreground font-mono text-sm">{m.external_sku ?? '—'}</span>
      ),
    },
    {
      key: 'sync_status',
      header: 'Sync Status',
      sortable: true,
      cell: (m) => <SyncStatusBadge status={m.sync_status} />,
    },
    {
      key: 'last_sync_at',
      header: 'Last Sync',
      sortable: true,
      cell: (m) => (
        <span className="text-muted-foreground">
          {m.last_sync_at ? new Date(m.last_sync_at).toLocaleDateString() : '—'}
        </span>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Product Mapping"
        subtitle="Map ECOS products to external channel products."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Product Mapping' }]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            New Mapping
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search by external ID or SKU…"
            onSearchChange={(v) => {
              setSearch(v);
              setPage(1);
            }}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => {
              setSyncStatusFilter('');
              setPage(1);
            }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">Sync Status</span>
                <select
                  value={syncStatusFilter}
                  onChange={(e) => {
                    setSyncStatusFilter(e.target.value as SyncStatus | '');
                    setPage(1);
                  }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="">All</option>
                  <option value="pending">Pending</option>
                  <option value="synced">Synced</option>
                  <option value="error">Error</option>
                </select>
              </div>
            }
          />

          <EntityTable<ProductMapping>
            columns={columns}
            data={items}
            getRowId={(m) => m.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(mapping) => (
              <ActionMenu
                label={`Actions for ${mapping.product?.name ?? mapping.external_product_id}`}
                items={[
                  { key: 'edit', label: 'Edit', icon: Pencil, onSelect: () => openEdit(mapping) },
                  {
                    key: 'delete',
                    label: 'Delete',
                    icon: Trash2,
                    variant: 'destructive' as const,
                    onSelect: () => setDeleting(mapping),
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

      <ProductMappingFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) setDrawerMapping(null);
        }}
        mapping={drawerMapping}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
        title="Delete product mapping"
        description={
          <>
            This will remove the mapping for{' '}
            <span className="text-foreground font-medium">
              {deleting?.product?.name ?? deleting?.external_product_id}
            </span>
            . It can be restored later.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteMapping.isPending}
        onConfirm={() => {
          if (deleting)
            deleteMapping.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />
    </div>
  );
}
