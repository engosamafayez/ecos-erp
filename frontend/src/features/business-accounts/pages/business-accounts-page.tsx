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
  { key: 'code',       label: 'الكود' },
  { key: 'company',    label: 'الشركة' },
  { key: 'brand',      label: 'العلامة التجارية' },
  { key: 'provider',   label: 'المزوّد' },
  { key: 'updated_at', label: 'تاريخ التحديث' },
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
      header: 'الكود',
      cell: (a) => <span className="font-mono text-xs font-medium">{a.code}</span>,
    },
    {
      key: 'name',
      header: 'الاسم',
      cell: (a) => <span className="font-medium">{a.name}</span>,
    },
    {
      key: 'company',
      header: 'الشركة',
      cell: (a) => <span className="text-muted-foreground">{a.company?.name ?? '—'}</span>,
    },
    {
      key: 'brand',
      header: 'العلامة التجارية',
      cell: (a) => <span className="text-muted-foreground">{a.brand?.name ?? '—'}</span>,
    },
    {
      key: 'provider',
      header: 'المزوّد',
      cell: (a) => <Badge variant="secondary">{a.provider}</Badge>,
    },
    {
      key: 'status',
      header: 'الحالة',
      cell: (a) => (
        <Badge variant={STATUS_BADGE_VARIANT[a.status] ?? 'secondary'}>
          {a.status.charAt(0).toUpperCase() + a.status.slice(1)}
        </Badge>
      ),
    },
    {
      key: 'updated_at',
      header: 'تاريخ التحديث',
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
        title="الحسابات التجارية"
        subtitle="إدارة حسابات المنصات والأسواق المتصلة بمؤسستك."
        breadcrumbs={[
          { label: 'الرئيسية', to: ROUTES.dashboard },
          { label: 'المؤسسة', to: ROUTES.organization },
          { label: 'الحسابات التجارية' },
        ]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            حساب تجاري جديد
          </Button>
        }
      />

      {/* KPI Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">إجمالي الحسابات</div>
            <div className="text-2xl font-bold">{isLoading ? '—' : totalCount}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">نشط</div>
            <div className="text-2xl font-bold text-emerald-600">
              {isLoading ? '—' : activeCount}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">غير نشط</div>
            <div className="text-2xl font-bold text-slate-400">
              {isLoading ? '—' : inactiveCount}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">موقوف</div>
            <div className="text-2xl font-bold text-red-500">
              {isLoading ? '—' : suspendedCount}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">متصل</div>
            <div className="text-2xl font-bold text-slate-400">0</div>
            <div className="text-muted-foreground/60 text-xs">مقاييس التكامل قادمة قريبًا</div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="ابحث عن حساب تجاري…"
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
                  <span className="text-sm font-medium">الشركة</span>
                  <CompanySelect
                    value={companyFilter}
                    onChange={(value) => {
                      setCompanyFilter(value);
                      setPage(1);
                    }}
                    placeholder="جميع الشركات"
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">المزوّد</span>
                  <select
                    value={providerFilter}
                    onChange={(e) => {
                      setProviderFilter(e.target.value);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="">جميع المزوّدين</option>
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
                  <span className="text-sm font-medium">الحالة</span>
                  <select
                    value={statusFilter}
                    onChange={(e) => {
                      setStatusFilter(e.target.value);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="">جميع الحالات</option>
                    <option value="active">نشط</option>
                    <option value="inactive">غير نشط</option>
                    <option value="suspended">موقوف</option>
                  </select>
                </div>
              </>
            }
          >
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                  <SlidersHorizontal className="size-4" />
                  الأعمدة
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuLabel>إظهار/إخفاء الأعمدة</DropdownMenuLabel>
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
                  { key: 'view', label: 'عرض', icon: Eye, onSelect: () => openDetail(account) },
                  { key: 'edit', label: 'تعديل', icon: Pencil, onSelect: () => openEdit(account) },
                  {
                    key: 'delete',
                    label: 'حذف',
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
        title="حذف الحساب التجاري"
        description={`هل أنت متأكد من حذف "${deleting?.name ?? ''}"؟ يمكن التراجع عن هذا الإجراء.`}
        confirmLabel="حذف الحساب التجاري"
        variant="destructive"
        loading={deleteAccount.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
