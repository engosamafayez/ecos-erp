import { useEffect, useMemo, useState } from 'react';
import { Plus, Search } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { CompaniesTable } from '@/features/companies/components/companies-table';
import { CompanyFormDialog } from '@/features/companies/components/company-form-dialog';
import { DeleteCompanyDialog } from '@/features/companies/components/delete-company-dialog';
import { useCompaniesQuery } from '@/features/companies/hooks/use-companies';
import type { Company, CompanySortField } from '@/features/companies/types/company';

const PER_PAGE = 10;

export function CompaniesPage() {
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [page, setPage] = useState(1);
  const [sortBy, setSortBy] = useState<CompanySortField>('created_at');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<Company | null>(null);
  const [deleting, setDeleting] = useState<Company | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);

  const params = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      page,
      per_page: PER_PAGE,
      sort_by: sortBy,
      sort_dir: sortDir,
    }),
    [debouncedSearch, page, sortBy, sortDir],
  );

  const { data, isLoading, isError } = useCompaniesQuery(params);

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSort = (field: CompanySortField) => {
    if (field === sortBy) {
      setSortDir((dir) => (dir === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortBy(field);
      setSortDir('asc');
    }
    setPage(1);
  };

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Companies</h1>
          <p className="text-muted-foreground text-sm">Manage the organizations in your tenant.</p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>
          <Plus className="size-4" />
          New Company
        </Button>
      </div>

      <Card>
        <CardHeader>
          <div className="relative max-w-sm">
            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
            <Input
              type="search"
              placeholder="Search companies…"
              aria-label="Search companies"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              className="pl-8"
            />
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <CompaniesTable
            items={items}
            isLoading={isLoading}
            isError={isError}
            sortBy={sortBy}
            sortDir={sortDir}
            onSort={handleSort}
            onEdit={(company) => setEditing(company)}
            onDelete={(company) => setDeleting(company)}
          />

          {meta ? (
            <div className="text-muted-foreground flex flex-col items-center justify-between gap-2 text-sm sm:flex-row">
              <span>
                {meta.total === 0
                  ? 'No results'
                  : `Page ${meta.current_page} of ${meta.last_page} · ${meta.total} total`}
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={meta.current_page <= 1}
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setPage((current) => current + 1)}
                >
                  Next
                </Button>
              </div>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <CompanyFormDialog open={createOpen} onOpenChange={setCreateOpen} />
      <CompanyFormDialog
        open={editing !== null}
        onOpenChange={(open) => {
          if (!open) {
            setEditing(null);
          }
        }}
        company={editing}
      />
      <DeleteCompanyDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) {
            setDeleting(null);
          }
        }}
        company={deleting}
      />
    </div>
  );
}
