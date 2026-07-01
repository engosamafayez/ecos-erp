import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';

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
import { useDeleteRecipe, useRecipesQuery } from '@/features/recipes/hooks/use-recipes';
import type { Recipe, RecipeSortField } from '@/features/recipes/types/recipe';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 20;

export function RecipesPage() {
  const navigate = useNavigate();

  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: RecipeSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });
  const [deleting, setDeleting] = useState<Recipe | null>(null);

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

  const { data, isLoading, isError, isFetching, refetch } = useRecipesQuery(params);
  const deleteRecipe = useDeleteRecipe();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const handleSort = (field: string) => {
    setSort((current) =>
      current.field === field
        ? { field: field as RecipeSortField, direction: current.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as RecipeSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const columns: ColumnDef<Recipe>[] = [
    {
      key: 'bom_number',
      header: 'Recipe ID',
      sortable: true,
      cell: (r) => <span className="font-mono font-medium">{r.bom_number}</span>,
    },
    {
      key: 'product',
      header: 'Finished Good',
      cell: (r) => r.product?.name ?? '—',
    },
    {
      key: 'lines',
      header: 'Materials',
      cell: (r) => (
        <span className="text-muted-foreground">
          {r.lines?.length ?? 0} {(r.lines?.length ?? 0) === 1 ? 'material' : 'materials'}
        </span>
      ),
    },
    {
      key: 'created_at',
      header: 'Created',
      sortable: true,
      cell: (r) => (r.created_at ? r.created_at.slice(0, 10) : '—'),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Recipes"
        subtitle="Manage production recipes and their material components"
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Inventory', to: ROUTES.inventoryProducts },
          { label: 'Recipes' },
        ]}
        actions={
          <Button onClick={() => navigate(ROUTES.recipesNew)}>
            <Plus className="size-4" />
            New Recipe
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search recipes…"
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
          />

          <EntityTable<Recipe>
            columns={columns}
            data={items}
            getRowId={(r) => r.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(r) => (
              <ActionMenu
                label={`Actions for ${r.bom_number}`}
                items={[
                  {
                    key: 'view',
                    label: 'View',
                    icon: Eye,
                    onSelect: () => navigate(`${ROUTES.recipes}/${r.id}`),
                  },
                  {
                    key: 'edit',
                    label: 'Edit',
                    icon: Pencil,
                    onSelect: () => navigate(`${ROUTES.recipes}/${r.id}/edit`),
                  },
                  {
                    key: 'delete',
                    label: 'Delete',
                    icon: Trash2,
                    variant: 'destructive' as const,
                    onSelect: () => setDeleting(r),
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

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
        title="Delete Recipe"
        description={`Are you sure you want to delete recipe ${deleting?.bom_number ?? ''}? This action cannot be undone.`}
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteRecipe.isPending}
        onConfirm={() => {
          if (deleting)
            deleteRecipe.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />
    </div>
  );
}
