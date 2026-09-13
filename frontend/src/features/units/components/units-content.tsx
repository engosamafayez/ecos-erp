import { useMemo, useState } from 'react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import {
  ActionMenu,
  ConfirmDialog,
  EntityToolbar,
  StatusBadge,
} from '@/components/crud';
import type { DataGridColumnDef, GridPaginationConfig } from '@/components/data-grid';
import { UniversalDataGrid } from '@/components/data-grid';
import { Button } from '@/components/ui/button';
import { UnitFormDrawer } from '@/features/units/components/unit-form-drawer';
import { useUnitsQuery, useDeleteUnit } from '@/features/units/hooks/use-units';
import type { Unit, UnitSortField } from '@/features/units/types/unit';

const PER_PAGE = 10;

/** Headless units table — no PageHeader or Card wrapper. Embed inside a tab CardContent. */
export function UnitsContent() {
  const { t } = useTranslation('units');
  const { t: tCommon } = useTranslation('common');

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

  const handleSort = (field: string) => {
    setSort((curr) =>
      curr.field === field
        ? { field: field as UnitSortField, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as UnitSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const columns: DataGridColumnDef<Unit>[] = [
    {
      key: 'code',
      label: t($ => $.columns.code),
      sortable: true,
      alwaysVisible: true,
      cardRole: 'title',
      cell: (u) => <span className="font-medium">{u.code}</span>,
    },
    { key: 'name', label: t($ => $.columns.name), sortable: true, cardRole: 'subtitle', cell: (u) => u.name },
    {
      key: 'symbol',
      label: t($ => $.columns.symbol),
      sortable: true,
      cell: (u) => <span className="text-muted-foreground">{u.symbol ?? '—'}</span>,
    },
    {
      key: 'description',
      label: t($ => $.columns.description),
      cell: (u) => <span className="text-muted-foreground">{u.description ?? '—'}</span>,
    },
    {
      key: 'is_active',
      label: t($ => $.columns.status),
      sortable: true,
      cardRole: 'status',
      cell: (u) => <StatusBadge status={u.is_active ? 'active' : 'inactive'} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'end',
      alwaysVisible: true,
      cell: (unit) => (
        <ActionMenu
          label={t($ => $.actions.ariaLabel, { name: unit.name })}
          items={[
            { key: 'view', label: tCommon($ => $.actions.view), icon: Eye, onSelect: () => { setDrawerUnit(unit); setDrawerOpen(true); } },
            { key: 'edit', label: tCommon($ => $.common.edit), icon: Pencil, onSelect: () => { setDrawerUnit(unit); setDrawerOpen(true); } },
            {
              key: 'delete',
              label: tCommon($ => $.common.delete),
              icon: Trash2,
              variant: 'destructive',
              onSelect: () => setDeleting(unit),
            },
          ]}
        />
      ),
    },
  ];

  const pagination: GridPaginationConfig | undefined = meta
    ? {
        meta: { page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page },
        onPageChange: setPage,
      }
    : undefined;

  return (
    <>
      <EntityToolbar
        searchPlaceholder={t($ => $.search)}
        onSearchChange={(v) => { setSearch(v); setPage(1); }}
        onRefresh={() => void refetch()}
        isRefreshing={isFetching}
        onExport={() => undefined}
      >
        <Button onClick={() => { setDrawerUnit(null); setDrawerOpen(true); }}>
          <Plus className="size-4" />
          {t($ => $.actions.new)}
        </Button>
      </EntityToolbar>

      <UniversalDataGrid<Unit>
        data={items}
        columns={columns}
        rowId={(unit) => unit.id}
        loading={isLoading}
        error={isError}
        sort={sort}
        onSortChange={handleSort}
        pagination={pagination}
      />

      <UnitFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => { setDrawerOpen(open); if (!open) setDrawerUnit(null); }}
        unit={drawerUnit}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title={t($ => $.delete.title)}
        description={tCommon($ => $.dialogs.softDeleteMessage, { name: deleting?.name ?? '' })}
        confirmLabel={t($ => $.delete.confirm)}
        variant="destructive"
        loading={deleteUnit.isPending}
        onConfirm={() => {
          if (!deleting) return;
          deleteUnit.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />
    </>
  );
}
