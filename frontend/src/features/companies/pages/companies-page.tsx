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
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { CompanyFormDrawer } from '@/features/companies/components/company-form-drawer';
import { useCompaniesQuery, useDeleteCompany } from '@/features/companies/hooks/use-companies';
import type { Company, CompanySortField } from '@/features/companies/types/company';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

export function CompaniesPage() {
  const { t } = useTranslation('companies');
  const { t: tCommon } = useTranslation('common');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: CompanySortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerCompany, setDrawerCompany] = useState<Company | null>(null);
  const [deleting, setDeleting] = useState<Company | null>(null);

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

  const { data, isLoading, isError, isFetching, refetch } = useCompaniesQuery(params);
  const deleteCompany = useDeleteCompany();

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
            field: field as CompanySortField,
            direction: current.direction === 'asc' ? 'desc' : 'asc',
          }
        : { field: field as CompanySortField, direction: 'asc' },
    );
    setPage(1);
  };

  const openCreate = () => {
    setDrawerCompany(null);
    setDrawerOpen(true);
  };

  const openEdit = (company: Company) => {
    setDrawerCompany(company);
    setDrawerOpen(true);
  };

  const columns: ColumnDef<Company>[] = [
    {
      key: 'code',
      header: t('columns.code'),
      sortable: true,
      cell: (c) => <span className="font-medium">{c.code}</span>,
    },
    { key: 'name', header: t('columns.name'), sortable: true, cell: (c) => c.name },
    {
      key: 'phone',
      header: t('columns.phone'),
      cell: (c) => <span className="text-muted-foreground">{c.phone ?? '—'}</span>,
    },
    {
      key: 'email',
      header: t('columns.email'),
      cell: (c) => <span className="text-muted-foreground">{c.email ?? '—'}</span>,
    },
    { key: 'country', header: t('columns.country'), sortable: true, cell: (c) => c.country ?? '—' },
    {
      key: 'is_active',
      header: t('columns.status'),
      sortable: true,
      cell: (c) => <StatusBadge status={c.is_active ? 'active' : 'inactive'} />,
    },
  ];

  const confirmDelete = () => {
    if (!deleting) return;
    deleteCompany.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
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
          />

          <EntityTable<Company>
            columns={columns}
            data={items}
            getRowId={(company) => company.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(company) => (
              <ActionMenu
                label={`Actions for ${company.name}`}
                items={[
                  { key: 'view', label: tCommon('actions.view'), icon: Eye, onSelect: () => openEdit(company) },
                  { key: 'edit', label: tCommon('common.edit'), icon: Pencil, onSelect: () => openEdit(company) },
                  {
                    key: 'delete',
                    label: tCommon('common.delete'),
                    icon: Trash2,
                    variant: 'destructive',
                    onSelect: () => setDeleting(company),
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

      <CompanyFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) setDrawerCompany(null);
        }}
        company={drawerCompany}
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
        loading={deleteCompany.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
