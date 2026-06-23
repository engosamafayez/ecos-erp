import { useMemo, useState } from 'react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

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
import { CategoryFormDrawer } from '@/features/categories/components/category-form-drawer';
import { useCategoriesQuery, useDeleteCategory } from '@/features/categories/hooks/use-categories';
import type {
  Category,
  CategorySortField,
  CategoryStatusFilter,
} from '@/features/categories/types/category';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

export function CategoriesPage() {
  const { t } = useTranslation('categories');
  const { t: tCommon } = useTranslation('common');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<CategoryStatusFilter>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: CategorySortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerCategory, setDrawerCategory] = useState<Category | null>(null);
  const [deleting, setDeleting] = useState<Category | null>(null);

  const params = useMemo(
    () => ({
      search: search || undefined,
      status: statusFilter,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, statusFilter, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useCategoriesQuery(params);
  const deleteCategory = useDeleteCategory();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const handleSort = (field: string) => {
    setSort((current) =>
      current.field === field
        ? { field: field as CategorySortField, direction: current.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as CategorySortField, direction: 'asc' },
    );
    setPage(1);
  };

  const openCreate = () => {
    setDrawerCategory(null);
    setDrawerOpen(true);
  };

  const openEdit = (category: Category) => {
    setDrawerCategory(category);
    setDrawerOpen(true);
  };

  const columns: ColumnDef<Category>[] = [
    {
      key: 'code',
      header: t('columns.code'),
      sortable: true,
      cell: (c) => <span className="font-medium">{c.code}</span>,
    },
    { key: 'name', header: t('columns.name'), sortable: true, cell: (c) => c.name },
    { key: 'parent', header: t('columns.parent'), cell: (c) => c.parent?.name ?? '—' },
    {
      key: 'level',
      header: t('columns.level'),
      sortable: true,
      cell: (c) => <Badge variant="secondary">L{c.level}</Badge>,
    },
    {
      key: 'sort_order',
      header: t('columns.sort'),
      sortable: true,
      cell: (c) => <span className="text-muted-foreground">{c.sort_order}</span>,
    },
    {
      key: 'is_active',
      header: t('columns.status'),
      sortable: true,
      cell: (c) => <StatusBadge status={c.is_active ? 'active' : 'inactive'} />,
    },
  ];

  const confirmDelete = () => {
    if (!deleting) return;
    deleteCategory.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('title')}
        subtitle={t('subtitle')}
        breadcrumbs={[{ label: tCommon('home'), to: ROUTES.dashboard }, { label: t('title') }]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            {t('actions.new')}
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder={t('search')}
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => {
              setStatusFilter('all');
              setPage(1);
            }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{tCommon('filters.status')}</span>
                <select
                  value={statusFilter}
                  onChange={(event) => {
                    setStatusFilter(event.target.value as CategoryStatusFilter);
                    setPage(1);
                  }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="all">{tCommon('status.all')}</option>
                  <option value="active">{tCommon('status.active')}</option>
                  <option value="inactive">{tCommon('status.inactive')}</option>
                </select>
              </div>
            }
          />

          <EntityTable<Category>
            columns={columns}
            data={items}
            getRowId={(category) => category.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(category) => (
              <ActionMenu
                label={`Actions for ${category.name}`}
                items={[
                  { key: 'view', label: tCommon('actions.view'), icon: Eye, onSelect: () => openEdit(category) },
                  { key: 'edit', label: tCommon('common.edit'), icon: Pencil, onSelect: () => openEdit(category) },
                  {
                    key: 'delete',
                    label: tCommon('common.delete'),
                    icon: Trash2,
                    variant: 'destructive',
                    onSelect: () => setDeleting(category),
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

      <CategoryFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) setDrawerCategory(null);
        }}
        category={drawerCategory}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
        title={t('delete.title')}
        description={tCommon('dialogs.softDeleteMessage', { name: deleting?.name ?? '' })}
        confirmLabel={t('delete.confirm')}
        variant="destructive"
        loading={deleteCategory.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
