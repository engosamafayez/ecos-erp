import { useMemo, useState } from 'react';
import { Eye, Pencil, Plus, SlidersHorizontal, Trash2 } from 'lucide-react';

import {
  ActionMenu,
  ConfirmDialog,
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
} from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { CompanySelect } from '@/features/branches/components/company-select';
import { BusinessAccountDetailDrawer } from '@/features/business-accounts/components/business-account-detail-drawer';
import { BusinessAccountFormDrawer } from '@/features/business-accounts/components/business-account-form-drawer';
import {
  useBusinessAccountsQuery,
  useDeleteBusinessAccount,
} from '@/features/business-accounts/hooks/use-business-accounts';
import type { BusinessAccount } from '@/features/business-accounts/types/business-account';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 15;

const OPTIONAL_COLS = [
  { key: 'code',       label: 'Code' },
  { key: 'company',    label: 'Company' },
  { key: 'brand',      label: 'Brand' },
  { key: 'provider',   label: 'Provider' },
  { key: 'updated_at', label: 'Updated At' },
] as const;

const STATUS_BADGE_VARIANT: Record<
  string,
  'default' | 'secondary' | 'destructive'
> = {
  active: 'default',
  inactive: 'secondary',
  suspended: 'destructive',
};

export function BusinessAccountsPage() {
  const [search, setSearch] = useState('');
  const [companyFilter, setCompanyFilter] = useState<string | null>(null);
  const [providerFilter, setProviderFilter] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [page, setPage] = useState(1);

  const [formDrawerOpen, setFormDrawerOpen] = useState(false);
  const [detailDrawerOpen, setDetailDrawerOpen] = useState(false);
  const [activeAccount, setActiveAccount] = useState<BusinessAccount | null>(null);
  const [deleting, setDeleting] = useState<BusinessAccount | null>(null);
  const [hiddenCols, setHiddenCols] = useState<Set<string>>(new Set());

  const params = useMemo(
    () => ({
      search: search || undefined,
      company_id: companyFilter || undefined,
      provider: providerFilter || undefined,
      status: statusFilter || undefined,
      page,
      per_page: PER_PAGE,
    }),
    [search, companyFilter, providerFilter, statusFilter, page],
  );

  const { data, isLoading, isError, isFetching, refetch } = useBusinessAccountsQuery(params);
  const deleteAccount = useDeleteBusinessAccount();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const totalCount = meta?.total ?? 0;
  const activeCount = items.filter((a) => a.status === 'active').length;
  const inactiveCount = items.filter((a) => a.status === 'inactive').length;
  const suspendedCount = items.filter((a) => a.status === 'suspended').length;

  function toggleCol(key: string) {
    setHiddenCols((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key); else next.add(key);
      return next;
    });
  }

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const openCreate = () => {
    setActiveAccount(null);
    setFormDrawerOpen(true);
  };

  const openEdit = (account: BusinessAccount) => {
    setActiveAccount(account);
    setFormDrawerOpen(true);
  };

  const openDetail = (account: BusinessAccount) => {
    setActiveAccount(account);
    setDetailDrawerOpen(true);
  };

  const columns: ColumnDef<BusinessAccount>[] = [
    {
      key: 'code',
      header: 'Code',
      cell: (a) => <span className="font-mono text-xs font-medium">{a.code}</span>,
    },
    {
      key: 'name',
      header: 'Name',
      cell: (a) => <span className="font-medium">{a.name}</span>,
    },
    {
      key: 'company',
      header: 'Company',
      cell: (a) => <span className="text-muted-foreground">{a.company?.name ?? '—'}</span>,
    },
    {
      key: 'brand',
      header: 'Brand',
      cell: (a) => <span className="text-muted-foreground">{a.brand?.name ?? '—'}</span>,
    },
    {
      key: 'provider',
      header: 'Provider',
      cell: (a) => <Badge variant="secondary">{a.provider}</Badge>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (a) => (
        <Badge variant={STATUS_BADGE_VARIANT[a.status] ?? 'secondary'}>
          {a.status.charAt(0).toUpperCase() + a.status.slice(1)}
        </Badge>
      ),
    },
    {
      key: 'updated_at',
      header: 'Updated At',
      cell: (a) => (
        <span className="text-muted-foreground text-xs">
          {a.updated_at ? new Date(a.updated_at).toLocaleDateString() : '—'}
        </span>
      ),
    },
  ];

  const confirmDelete = () => {
    if (!deleting) return;
    deleteAccount.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Business Accounts"
        subtitle="Manage platform and marketplace accounts connected to your organization."
        breadcrumbs={[
          { label: 'Home', to: ROUTES.dashboard },
          { label: 'Organization', to: ROUTES.organization },
          { label: 'Business Accounts' },
        ]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            New Business Account
          </Button>
        }
      />

      {/* KPI Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Total Accounts</div>
            <div className="text-2xl font-bold">{isLoading ? '—' : totalCount}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Active</div>
            <div className="text-2xl font-bold text-emerald-600">
              {isLoading ? '—' : activeCount}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Inactive</div>
            <div className="text-2xl font-bold text-slate-400">
              {isLoading ? '—' : inactiveCount}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Suspended</div>
            <div className="text-2xl font-bold text-red-500">
              {isLoading ? '—' : suspendedCount}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Connected</div>
            <div className="text-2xl font-bold text-slate-400">0</div>
            <div className="text-muted-foreground/60 text-xs">Integration metrics coming soon</div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search business accounts…"
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => {
              setCompanyFilter(null);
              setProviderFilter('');
              setStatusFilter('');
              setPage(1);
            }}
            filterPanel={
              <>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Company</span>
                  <CompanySelect
                    value={companyFilter}
                    onChange={(value) => {
                      setCompanyFilter(value);
                      setPage(1);
                    }}
                    placeholder="All Companies"
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Provider</span>
                  <select
                    value={providerFilter}
                    onChange={(e) => {
                      setProviderFilter(e.target.value);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="">All Providers</option>
                    {['Meta', 'WooCommerce', 'Shopify', 'Amazon', 'TikTok', 'Google', 'Noon', 'Snapchat', 'Custom'].map(
                      (p) => (
                        <option key={p} value={p}>
                          {p}
                        </option>
                      ),
                    )}
                  </select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Status</span>
                  <select
                    value={statusFilter}
                    onChange={(e) => {
                      setStatusFilter(e.target.value);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                  </select>
                </div>
              </>
            }
          >
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                  <SlidersHorizontal className="size-4" />
                  Columns
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuLabel>Show/Hide Columns</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {OPTIONAL_COLS.map(({ key, label }) => (
                  <DropdownMenuCheckboxItem
                    key={key}
                    checked={!hiddenCols.has(key)}
                    onCheckedChange={() => toggleCol(key)}
                  >
                    {label}
                  </DropdownMenuCheckboxItem>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          </EntityToolbar>

          <EntityTable<BusinessAccount>
            columns={columns.filter((c) => !hiddenCols.has(c.key))}
            data={items}
            getRowId={(a) => a.id}
            isLoading={isLoading}
            isError={isError}
            rowActions={(account) => (
              <ActionMenu
                label={`Actions for ${account.name}`}
                items={[
                  { key: 'view', label: 'View', icon: Eye, onSelect: () => openDetail(account) },
                  { key: 'edit', label: 'Edit', icon: Pencil, onSelect: () => openEdit(account) },
                  {
                    key: 'delete',
                    label: 'Delete',
                    icon: Trash2,
                    variant: 'destructive',
                    onSelect: () => setDeleting(account),
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

      <BusinessAccountFormDrawer
        open={formDrawerOpen}
        onOpenChange={(open) => {
          setFormDrawerOpen(open);
          if (!open) setActiveAccount(null);
        }}
        account={activeAccount}
      />

      <BusinessAccountDetailDrawer
        open={detailDrawerOpen}
        onOpenChange={(open) => {
          setDetailDrawerOpen(open);
          if (!open) setActiveAccount(null);
        }}
        account={activeAccount}
        onEdit={openEdit}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
        title="Delete Business Account"
        description={`Are you sure you want to delete "${deleting?.name ?? ''}"? This action can be undone.`}
        confirmLabel="Delete Business Account"
        variant="destructive"
        loading={deleteAccount.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
