import { useMemo, useState } from 'react';
import { Eye, Pencil, Plus, SlidersHorizontal, Trash2 } from 'lucide-react';

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
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { CompanySelect } from '@/features/branches/components/company-select';
import { TeamDetailDrawer } from '@/features/teams/components/team-detail-drawer';
import { TeamFormDrawer } from '@/features/teams/components/team-form-drawer';
import { useTeamsQuery, useDeleteTeam } from '@/features/teams/hooks/use-teams';
import type { Team } from '@/features/teams/types/team';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 15;

const OPTIONAL_COLS = [
  { key: 'code',        label: 'الكود' },
  { key: 'company',     label: 'الشركة' },
  { key: 'leader_name', label: 'القائد' },
] as const;

export function TeamsPage() {
  const [search, setSearch] = useState('');
  const [companyFilter, setCompanyFilter] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<string>('');
  const [page, setPage] = useState(1);

  const [formDrawerOpen, setFormDrawerOpen] = useState(false);
  const [detailDrawerOpen, setDetailDrawerOpen] = useState(false);
  const [activeTeam, setActiveTeam] = useState<Team | null>(null);
  const [deleting, setDeleting] = useState<Team | null>(null);
  const [hiddenCols, setHiddenCols] = useState<Set<string>>(new Set());

  const params = useMemo(
    () => ({
      search: search || undefined,
      company_id: companyFilter || undefined,
      status: statusFilter || undefined,
      page,
      per_page: PER_PAGE,
    }),
    [search, companyFilter, statusFilter, page],
  );

  const { data, isLoading, isError, isFetching, refetch } = useTeamsQuery(params);
  const deleteTeam = useDeleteTeam();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const totalCount = meta?.total ?? 0;
  const activeCount = items.filter((t) => t.is_active).length;
  const inactiveCount = items.filter((t) => !t.is_active).length;

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
    setActiveTeam(null);
    setFormDrawerOpen(true);
  };

  const openEdit = (team: Team) => {
    setActiveTeam(team);
    setFormDrawerOpen(true);
  };

  const openDetail = (team: Team) => {
    setActiveTeam(team);
    setDetailDrawerOpen(true);
  };

  const columns: ColumnDef<Team>[] = [
    {
      key: 'code',
      header: 'الكود',
      cell: (t) => <span className="font-mono text-xs font-medium">{t.code}</span>,
    },
    {
      key: 'name',
      header: 'الاسم',
      cell: (t) => <span className="font-medium">{t.name}</span>,
    },
    {
      key: 'company',
      header: 'الشركة',
      cell: (t) => <span className="text-muted-foreground">{t.company?.name ?? '—'}</span>,
    },
    {
      key: 'leader_name',
      header: 'القائد',
      cell: (t) => (
        <span className="text-muted-foreground">{t.leader_name ?? '—'}</span>
      ),
    },
    {
      key: 'is_active',
      header: 'الحالة',
      cell: (t) => <StatusBadge status={t.is_active ? 'active' : 'inactive'} />,
    },
  ];

  const confirmDelete = () => {
    if (!deleting) return;
    deleteTeam.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="الفرق"
        subtitle="إدارة فرق مؤسستك وتعيين القادة."
        breadcrumbs={[
          { label: 'الرئيسية', to: ROUTES.dashboard },
          { label: 'المؤسسة', to: ROUTES.organization },
          { label: 'الفرق' },
        ]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            فريق جديد
          </Button>
        }
      />

      {/* KPI Cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">إجمالي الفرق</div>
            <div className="text-2xl font-bold">{isLoading ? '—' : totalCount}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">الفرق النشطة</div>
            <div className="text-2xl font-bold text-emerald-600">
              {isLoading ? '—' : activeCount}
            </div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">الفرق غير النشطة</div>
            <div className="text-2xl font-bold text-slate-400">
              {isLoading ? '—' : inactiveCount}
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="ابحث عن فريق…"
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => {
              setCompanyFilter(null);
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
                  <span className="text-sm font-medium">الحالة</span>
                  <select
                    value={statusFilter}
                    onChange={(e) => {
                      setStatusFilter(e.target.value);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="">الكل</option>
                    <option value="active">نشط</option>
                    <option value="inactive">غير نشط</option>
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

          <EntityTable<Team>
            columns={columns.filter((c) => !hiddenCols.has(c.key))}
            data={items}
            getRowId={(t) => t.id}
            isLoading={isLoading}
            isError={isError}
            rowActions={(team) => (
              <ActionMenu
                label={`Actions for ${team.name}`}
                items={[
                  { key: 'view', label: 'عرض', icon: Eye, onSelect: () => openDetail(team) },
                  { key: 'edit', label: 'تعديل', icon: Pencil, onSelect: () => openEdit(team) },
                  {
                    key: 'delete',
                    label: 'حذف',
                    icon: Trash2,
                    variant: 'destructive',
                    onSelect: () => setDeleting(team),
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

      <TeamFormDrawer
        open={formDrawerOpen}
        onOpenChange={(open) => {
          setFormDrawerOpen(open);
          if (!open) setActiveTeam(null);
        }}
        team={activeTeam}
      />

      <TeamDetailDrawer
        open={detailDrawerOpen}
        onOpenChange={(open) => {
          setDetailDrawerOpen(open);
          if (!open) setActiveTeam(null);
        }}
        team={activeTeam}
        onEdit={openEdit}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null);
        }}
        title="حذف الفريق"
        description={`هل أنت متأكد من حذف "${deleting?.name ?? ''}"؟ يمكن التراجع عن هذا الإجراء.`}
        confirmLabel="حذف الفريق"
        variant="destructive"
        loading={deleteTeam.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}
