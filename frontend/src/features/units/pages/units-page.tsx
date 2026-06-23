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
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { UnitFormDrawer } from '@/features/units/components/unit-form-drawer';
import { useUnitsQuery, useDeleteUnit } from '@/features/units/hooks/use-units';
import type { Unit, UnitSortField } from '@/features/units/types/unit';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

export function UnitsPage() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: UnitSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerUnit, setDrawerUnit] = useState<Unit | null>(null);
  const [deleting, setDeleting] = useState<Unit | null>(null);

  const params = useMemo(
    () => ({
      search: search || undefined,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useUnitsQuery(params);
  const deleteUnit = useDeleteUnit();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const handleSort = (field: string) => {
    setSort((current) =>
      current.field === field
        ? { field: field as UnitSortField, direction: current.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as UnitSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const openCreate = () => {
    setDrawerUnit(null);
    setDrawerOpen(true);
  };

  const openEdit = (unit: Unit) => {
    setDrawerUnit(unit);
    setDrawerOpen(true);
  };

  const columns: ColumnDef<Unit>[] = [
    {
      key: 'code',
      header: 'Code',
      sortable: true,
      cell: (u) => <span className="font-medium">{u.code}</span>,
    },
    { key: 'name', header: 'Name', sortable: true, cell: (u) => u.name },
    {
      key: 'symbol',
      header: 'Symbol',
      sortable: true,
      cell: (u) => <span className="text-muted-foreground">{u.symbol ?? '—'}</span>,
    },
    {
      key: 'description',
      header: 'Description',
      cell: (u) => <span className="text-muted-foreground">{u.description ?? '—'}</span>,
    },
    {
      key: 'is_active',
      header: 'Status',
      sortable: true,
      cell: (u) => <StatusBadge status={u.is_active ? 'active' : 'inactive'} />,
    },
  ];

  const confirmDelete = () => {
    if (!deleting) {
      return;
    }
    deleteUnit.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Units of Measure"
        subtitle="Manage the units used across the catalog."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Units' }]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            New Unit
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search units…"
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
          />

          <EntityTable<Unit>
            columns={columns}
            data={items}
            getRowId={(unit) => unit.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(unit) => (
              <ActionMenu
                label={`Actions for ${unit.name}`}
                items={[
                  { key: 'view', label: 'View', icon: Eye, onSelect: () => openEdit(unit) },
                  { key: 'edit', label: 'Edit', icon: Pencil, onSelect: () => openEdit(unit) },
                  {
                    key: 'delete',
                    label: 'Delete',
                    icon: Trash2,
                    variant: 'destructive',
                    onSelect: () => setDeleting(unit),
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

      <UnitFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) {
            setDrawerUnit(null);
          }
        }}
        unit={drawerUnit}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) {
            setDeleting(null);
          }
        }}
        title="Delete unit"
        description={
          <>
            This will soft-delete{' '}
            <span className="text-foreground font-medium">{deleting?.name}</span>. It can be
            restored later.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteUnit.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
