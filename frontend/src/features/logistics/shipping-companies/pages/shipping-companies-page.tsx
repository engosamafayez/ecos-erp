import { useState } from 'react';
import {
  Archive,
  Building2,
  CheckCircle,
  ChevronRight,
  FileText,
  Plus,
  Truck,
  Warehouse,
  XCircle,
} from 'lucide-react';

import { Pagination } from '@/components/crud';
import { WorkspaceHeader } from '@/components/workspace/header/workspace-header';
import { WorkspacePage } from '@/components/page/layout/workspace-page';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';

import {
  useShippingCompanies,
  useShippingCompanyStats,
} from '../hooks/use-shipping-companies';
import type {
  ShippingCompany,
  ShippingCompanyStatus,
  ShippingCompanyType,
} from '../types/shipping-company';
import { ShippingCompanyDrawer } from '../components/shipping-company-drawer';

// ── Table Skeleton ─────────────────────────────────────────────────────────────

function TableSkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/60">
            {['w-24', 'w-44', 'w-28', 'w-36', 'w-36', 'w-20', 'w-20', 'w-24', 'w-10'].map((w, i) => (
              <th key={i} className={`h-10 px-3 ${w}`} />
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {Array.from({ length: 6 }).map((_, i) => (
            <tr key={i}>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-16" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-36" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-5 w-20 rounded-full" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-28" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-28" /></td>
              <td className="px-3 py-2.5 text-center"><Skeleton className="mx-auto h-4 w-8" /></td>
              <td className="px-3 py-2.5 text-center"><Skeleton className="mx-auto h-4 w-8" /></td>
              <td className="px-3 py-2.5 text-center"><Skeleton className="mx-auto h-5 w-14 rounded-full" /></td>
              <td className="px-3 py-2.5" />
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ── Empty State ────────────────────────────────────────────────────────────────

function EmptyCompanies({
  hasFilter,
  onCreateFirst,
}: {
  hasFilter: boolean;
  onCreateFirst: () => void;
}) {
  if (hasFilter) {
    return (
      <div className="flex flex-col items-center justify-center rounded-lg border bg-card py-16 text-center">
        <Truck className="mb-3 size-10 text-muted-foreground/30" />
        <p className="text-sm font-medium">No shipping companies match your filters</p>
        <p className="mt-1 text-xs text-muted-foreground">
          Try a different keyword or clear your filters.
        </p>
      </div>
    );
  }

  return (
    <div className="flex flex-col items-center justify-center rounded-lg border bg-card py-16 text-center">
      <Truck className="mb-3 size-12 text-muted-foreground/20" />
      <p className="text-sm font-medium">No shipping companies yet</p>
      <p className="mt-1 text-xs text-muted-foreground">
        Register your internal fleet and external carriers to power deliveries.
      </p>
      <Button size="sm" className="mt-4 gap-1.5" onClick={onCreateFirst}>
        <Plus className="size-3.5" />
        Add First Shipping Company
      </Button>
    </div>
  );
}

// ── Badges ─────────────────────────────────────────────────────────────────────

function TypeBadge({ type }: { type: ShippingCompanyType }) {
  return type === 'internal' ? (
    <Badge variant="secondary" className="gap-1 text-xs">
      <Warehouse className="size-3" />
      Internal
    </Badge>
  ) : (
    <Badge variant="outline" className="gap-1 text-xs">
      <Truck className="size-3" />
      External
    </Badge>
  );
}

function StatusBadge({ status }: { status: ShippingCompanyStatus }) {
  if (status === 'active') {
    return <Badge className="bg-emerald-600 text-xs hover:bg-emerald-600">Active</Badge>;
  }
  if (status === 'inactive') {
    return <Badge variant="secondary" className="text-xs">Inactive</Badge>;
  }
  return (
    <Badge variant="outline" className="gap-1 text-xs text-muted-foreground">
      <Archive className="size-3" />
      Archived
    </Badge>
  );
}

// ── Companies Table ────────────────────────────────────────────────────────────

function CompaniesTable({
  rows,
  isLoading,
  hasFilter,
  onRowClick,
  onCreateFirst,
}: {
  rows: ShippingCompany[];
  isLoading: boolean;
  hasFilter: boolean;
  onRowClick: (company: ShippingCompany) => void;
  onCreateFirst: () => void;
}) {
  if (isLoading) return <TableSkeleton />;

  if (rows.length === 0) {
    return <EmptyCompanies hasFilter={hasFilter} onCreateFirst={onCreateFirst} />;
  }

  return (
    <div className="overflow-hidden rounded-lg border bg-card">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/60">
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">Code</th>
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">Company</th>
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">Type</th>
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">Contact</th>
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">Active Contract</th>
              <th className="h-10 w-24 px-3 text-center text-xs font-medium text-muted-foreground">Contracts</th>
              <th className="h-10 w-24 px-3 text-center text-xs font-medium text-muted-foreground">Companies</th>
              <th className="h-10 w-24 px-3 text-center text-xs font-medium text-muted-foreground">Status</th>
              <th className="h-10 w-10 px-3" />
            </tr>
          </thead>
          <tbody className="divide-y">
            {rows.map((company) => (
              <tr
                key={company.id}
                className={`group cursor-pointer transition-colors hover:bg-accent/40 ${
                  company.status === 'archived' ? 'opacity-60' : ''
                }`}
                onClick={() => onRowClick(company)}
              >
                <td className="px-3 py-2.5">
                  <span className="font-mono text-xs font-medium tracking-wider text-muted-foreground">
                    {company.code}
                  </span>
                </td>

                <td className="px-3 py-2.5">
                  <p className="font-medium">{company.name}</p>
                  {company.email && (
                    <p className="text-xs text-muted-foreground">{company.email}</p>
                  )}
                </td>

                <td className="px-3 py-2.5">
                  <TypeBadge type={company.type} />
                </td>

                <td className="px-3 py-2.5">
                  {company.contact_person || company.phone ? (
                    <>
                      {company.contact_person && <p className="text-sm">{company.contact_person}</p>}
                      {company.phone && (
                        <p className="font-mono text-xs text-muted-foreground">{company.phone}</p>
                      )}
                    </>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </td>

                <td className="px-3 py-2.5">
                  {company.active_contract ? (
                    <div className="flex items-center gap-1.5">
                      <FileText className="size-3.5 shrink-0 text-emerald-600" />
                      <span className="truncate text-xs">{company.active_contract.name}</span>
                    </div>
                  ) : (
                    <span className="text-xs text-muted-foreground">No active contract</span>
                  )}
                </td>

                <td className="w-24 px-3 py-2.5 text-center tabular-nums">
                  {company.contracts_count ?? 0}
                </td>

                <td className="w-24 px-3 py-2.5 text-center tabular-nums">
                  {company.companies_count ?? 0}
                </td>

                <td className="w-24 px-3 py-2.5 text-center">
                  <StatusBadge status={company.status} />
                </td>

                <td className="w-10 p-0">
                  <div className="flex h-full items-center justify-center py-2.5">
                    <ChevronRight className="size-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

const STATUS_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'inactive', label: 'Inactive' },
  { key: 'archived', label: 'Archived' },
] as const;

type StatusFilterKey = (typeof STATUS_FILTERS)[number]['key'];

const TYPE_FILTERS = [
  { key: 'all', label: 'All Types' },
  { key: 'internal', label: 'Internal' },
  { key: 'external', label: 'External' },
] as const;

type TypeFilterKey = (typeof TYPE_FILTERS)[number]['key'];

export function ShippingCompaniesPage() {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilterKey>('all');
  const [typeFilter, setTypeFilter] = useState<TypeFilterKey>('all');
  const [page, setPage] = useState(1);

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [editCompany, setEditCompany] = useState<ShippingCompany | null>(null);

  const params = {
    search: search || undefined,
    // 'all' keeps the backend default (archived hidden); pick Archived to view archived.
    status: statusFilter === 'all' ? undefined : statusFilter,
    type: typeFilter === 'all' ? undefined : typeFilter,
    page,
    per_page: 20,
  };

  const { data: stats } = useShippingCompanyStats();
  const { data, isFetching, refetch } = useShippingCompanies(params);

  const companies = data?.data ?? [];
  const meta = data?.meta;
  const hasFilter = !!(search || statusFilter !== 'all' || typeFilter !== 'all');

  function openCreate() {
    setEditCompany(null);
    setDrawerOpen(true);
  }

  function openEdit(company: ShippingCompany) {
    setEditCompany(company);
    setDrawerOpen(true);
  }

  const metrics = [
    { id: 'total',    icon: Truck,       label: 'Total Shipping Companies', value: stats?.total_companies    ?? 0, isLoading: !stats },
    { id: 'active',   icon: CheckCircle, label: 'Active Companies',         value: stats?.active_companies   ?? 0, isLoading: !stats, colorClass: 'text-emerald-600' },
    { id: 'internal', icon: Warehouse,   label: 'Internal Companies',       value: stats?.internal_companies ?? 0, isLoading: !stats },
    { id: 'external', icon: Building2,   label: 'External Companies',       value: stats?.external_companies ?? 0, isLoading: !stats },
  ];

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: 'Logistics OS' }, { label: 'Shipping Companies' }]}
        title="Shipping Companies"
        description="Manage internal fleet and external carriers, contracts and company assignments"
        metrics={metrics}
      />

      <WorkspacePage
        toolbar={
          <div className="px-4 sm:px-6">
            <SmartToolbar
              primaryAction={{ label: 'New Shipping Company', icon: Plus, onClick: openCreate }}
              onRefresh={() => refetch()}
              isFetching={isFetching}
            />
          </div>
        }
        quickFilters={
          <div className="flex flex-wrap items-center gap-2 px-4 py-2 sm:px-6">
            <Input
              placeholder="Search by name, code, contact or phone…"
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
              className="h-8 max-w-xs text-sm"
            />
            {STATUS_FILTERS.map((s) => (
              <Button
                key={s.key}
                size="sm"
                variant={statusFilter === s.key ? 'secondary' : 'ghost'}
                className="h-8 text-xs"
                onClick={() => { setStatusFilter(s.key); setPage(1); }}
              >
                {s.key === 'active' && <CheckCircle className="mr-1 h-3 w-3" />}
                {s.key === 'inactive' && <XCircle className="mr-1 h-3 w-3" />}
                {s.key === 'archived' && <Archive className="mr-1 h-3 w-3" />}
                {s.label}
              </Button>
            ))}
            <span className="mx-1 h-4 w-px bg-border" />
            {TYPE_FILTERS.map((t) => (
              <Button
                key={t.key}
                size="sm"
                variant={typeFilter === t.key ? 'secondary' : 'ghost'}
                className="h-8 text-xs"
                onClick={() => { setTypeFilter(t.key); setPage(1); }}
              >
                {t.key === 'internal' && <Warehouse className="mr-1 h-3 w-3" />}
                {t.key === 'external' && <Truck className="mr-1 h-3 w-3" />}
                {t.label}
              </Button>
            ))}
          </div>
        }
        pagination={
          meta && meta.last_page > 1 ? (
            <div className="px-4 pb-4 sm:px-6">
              <Pagination
                meta={{
                  page: meta.current_page,
                  perPage: meta.per_page,
                  total: meta.total,
                  lastPage: meta.last_page,
                }}
                onPageChange={setPage}
              />
            </div>
          ) : undefined
        }
      >
        <div className="px-4 sm:px-6">
          <CompaniesTable
            rows={companies}
            isLoading={isFetching && companies.length === 0}
            hasFilter={hasFilter}
            onRowClick={openEdit}
            onCreateFirst={openCreate}
          />
        </div>
      </WorkspacePage>

      <ShippingCompanyDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        editCompany={editCompany}
      />
    </>
  );
}
