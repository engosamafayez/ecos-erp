import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  CheckCircle,
  ChevronRight,
  Map,
  MapPin,
  Network,
  Plus,
  XCircle,
} from 'lucide-react';

import { Pagination }       from '@/components/crud';
import { WorkspaceHeader }  from '@/components/workspace/header/workspace-header';
import { useNavLabel }      from '@/components/layout/use-nav-label';
import { WorkspacePage }    from '@/components/page/layout/workspace-page';
import { SmartToolbar }     from '@/components/data-grid/smart-toolbar';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge }    from '@/components/ui/badge';
import { Button }   from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input }    from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ds/use-toast';

import {
  useDeleteDistributionZone,
  useDistributionZoneStats,
  useDistributionZones,
  useToggleDistributionZoneStatus,
} from '../hooks/use-distribution-zones';
import type { DistributionZone } from '../types/distribution-zone';
import { DistributionZoneDrawer } from '../components/distribution-zone-drawer';
import { AreaCountPopover }       from '../components/area-count-popover';

// ── Table Skeleton ─────────────────────────────────────────────────────────────

function TableSkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border bg-card">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/60">
            {['w-10', 'w-24', 'w-40', 'w-36', 'w-16', 'w-24', 'w-28', 'w-10'].map((w, i) => (
              <th key={i} className={`h-10 px-3 ${w}`} />
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">
          {Array.from({ length: 6 }).map((_, i) => (
            <tr key={i}>
              <td className="px-3 py-2.5"><Skeleton className="h-3.5 w-3.5" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-16" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-32" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-24" /></td>
              <td className="px-3 py-2.5 text-center"><Skeleton className="mx-auto h-4 w-8" /></td>
              <td className="px-3 py-2.5 text-center"><Skeleton className="mx-auto h-5 w-14 rounded-full" /></td>
              <td className="px-3 py-2.5"><Skeleton className="h-4 w-20" /></td>
              <td className="px-3 py-2.5" />
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// ── Empty State ────────────────────────────────────────────────────────────────

function EmptyZones({
  hasFilter,
  onCreateFirst,
}: {
  hasFilter:     boolean;
  onCreateFirst: () => void;
}) {
  const { t } = useTranslation('logistics');

  if (hasFilter) {
    return (
      <div className="flex flex-col items-center justify-center rounded-lg border bg-card py-16 text-center">
        <Network className="mb-3 size-10 text-muted-foreground/30" />
        <p className="text-sm font-medium">{t(($) => $.distributionZones.empty.noResultsTitle)}</p>
        <p className="mt-1 text-xs text-muted-foreground">
          {t(($) => $.distributionZones.empty.noResultsHint)}
        </p>
      </div>
    );
  }

  return (
    <div className="flex flex-col items-center justify-center rounded-lg border bg-card py-16 text-center">
      <Network className="mb-3 size-12 text-muted-foreground/20" />
      <p className="text-sm font-medium">{t(($) => $.distributionZones.empty.title)}</p>
      <p className="mt-1 text-xs text-muted-foreground">
        {t(($) => $.distributionZones.empty.hint)}
      </p>
      <Button size="sm" className="mt-4 gap-1.5" onClick={onCreateFirst}>
        <Plus className="size-3.5" />
        {t(($) => $.distributionZones.empty.createFirst)}
      </Button>
    </div>
  );
}

// ── Zones Table ────────────────────────────────────────────────────────────────

function ZonesTable({
  rows,
  isLoading,
  hasFilter,
  selected,
  onToggleSelect,
  onToggleSelectAll,
  onRowClick,
  onCreateFirst,
}: {
  rows:              DistributionZone[];
  isLoading:         boolean;
  hasFilter:         boolean;
  selected:          Set<number>;
  onToggleSelect:    (id: number) => void;
  onToggleSelectAll: () => void;
  onRowClick:        (zone: DistributionZone) => void;
  onCreateFirst:     () => void;
}) {
  const { t } = useTranslation('logistics');
  const allSelected = rows.length > 0 && rows.every((r) => selected.has(r.id));

  if (isLoading) return <TableSkeleton />;

  if (rows.length === 0) {
    return <EmptyZones hasFilter={hasFilter} onCreateFirst={onCreateFirst} />;
  }

  return (
    <div className="overflow-hidden rounded-lg border bg-card">
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/60">
              <th className="h-10 w-10 px-3">
                <Checkbox
                  checked={allSelected}
                  onCheckedChange={onToggleSelectAll}
                  className="size-3.5"
                />
              </th>
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">{t(($) => $.common.code)}</th>
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">{t(($) => $.distributionZones.table.arabicName)}</th>
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">{t(($) => $.distributionZones.table.englishName)}</th>
              <th className="h-10 w-16 px-3 text-center text-xs font-medium text-muted-foreground">{t(($) => $.distributionZones.table.areas)}</th>
              <th className="h-10 w-24 px-3 text-center text-xs font-medium text-muted-foreground">{t(($) => $.common.status)}</th>
              <th className="h-10 px-3 text-start text-xs font-medium text-muted-foreground">{t(($) => $.distributionZones.table.updatedBy)}</th>
              <th className="h-10 w-10 px-3" />
            </tr>
          </thead>
          <tbody className="divide-y">
            {rows.map((zone) => (
              <tr key={zone.id} className="group transition-colors hover:bg-accent/40">
                <td className="px-3 py-2.5">
                  <Checkbox
                    checked={selected.has(zone.id)}
                    onCheckedChange={() => onToggleSelect(zone.id)}
                    className="size-3.5"
                  />
                </td>

                <td className="px-3 py-2.5">
                  <div className="flex items-center gap-2">
                    {zone.color && (
                      <span
                        className="inline-block size-2.5 shrink-0 rounded-full"
                        style={{ backgroundColor: zone.color }}
                      />
                    )}
                    <span className="font-mono text-xs font-medium tracking-wider text-muted-foreground">
                      {zone.code}
                    </span>
                  </div>
                </td>

                <td className="px-3 py-2.5" dir="rtl">
                  <span className="font-medium">{zone.name_ar}</span>
                </td>

                <td className="px-3 py-2.5 text-muted-foreground">
                  {zone.name_en ?? '—'}
                </td>

                <td className="w-16 px-3 py-2.5 text-center">
                  <AreaCountPopover zoneId={zone.id} count={zone.areas_count} />
                </td>

                <td className="w-24 px-3 py-2.5 text-center">
                  <Badge
                    variant={zone.is_active ? 'default' : 'secondary'}
                    className="text-xs"
                  >
                    {zone.is_active ? t(($) => $.common.active) : t(($) => $.common.inactive)}
                  </Badge>
                </td>

                <td className="px-3 py-2.5 text-xs text-muted-foreground">
                  {zone.updated_by ?? zone.created_by ?? '—'}
                </td>

                <td className="w-10 p-0">
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-full w-full rounded-none py-2.5"
                    onClick={() => onRowClick(zone)}
                  >
                    <ChevronRight className="h-4 w-4" />
                  </Button>
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

export function DistributionZonesPage() {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const navLabel = useNavLabel();

  const [search,       setSearch]       = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [page,         setPage]         = useState(1);

  const [selected,      setSelected]      = useState<Set<number>>(new Set());
  const [drawerOpen,    setDrawerOpen]    = useState(false);
  const [editZone,      setEditZone]      = useState<DistributionZone | null>(null);
  const [deleteTarget,  setDeleteTarget]  = useState<DistributionZone | null>(null);
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);

  const params = {
    search:   search || undefined,
    status:   statusFilter === 'all' ? undefined : statusFilter,
    page,
    per_page: 20,
  };

  const { data: stats }               = useDistributionZoneStats();
  const { data, isFetching, refetch } = useDistributionZones(params);
  const deleteZone                    = useDeleteDistributionZone();
  const toggleStatus                  = useToggleDistributionZoneStatus();

  const zones    = data?.data ?? [];
  const meta     = data?.meta;
  const hasFilter = !!(search || statusFilter !== 'all');

  // ── Selection ──────────────────────────────────────────────────────────────

  function toggleSelect(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  function toggleSelectAll() {
    const allSelected = zones.length > 0 && zones.every((z) => selected.has(z.id));
    setSelected(allSelected ? new Set() : new Set(zones.map((z) => z.id)));
  }

  // ── Actions ────────────────────────────────────────────────────────────────

  function openCreate() {
    setEditZone(null);
    setDrawerOpen(true);
  }

  function openEdit(zone: DistributionZone) {
    setEditZone(zone);
    setDrawerOpen(true);
  }

  async function handleDeleteConfirm() {
    if (!deleteTarget) return;
    try {
      await deleteZone.mutateAsync(deleteTarget.id);
      toast({ title: t(($) => $.distributionZones.toast.zoneDeleted, { name: deleteTarget.name_ar }) });
      setSelected((prev) => { const next = new Set(prev); next.delete(deleteTarget.id); return next; });
    } catch {
      toast({ title: t(($) => $.distributionZones.toast.deleteFailed), variant: 'destructive' });
    } finally {
      setDeleteTarget(null);
    }
  }

  async function handleBulkDelete() {
    const ids   = Array.from(selected);
    let failed  = 0;
    for (const id of ids) {
      try { await deleteZone.mutateAsync(id); }
      catch { failed++; }
    }
    setSelected(new Set());
    setBulkDeleteOpen(false);
    if (failed === 0) {
      toast({ title: t(($) => $.distributionZones.toast.zonesDeleted, { count: ids.length }) });
    } else {
      toast({
        title: t(($) => $.distributionZones.toast.bulkDeletePartial, { success: ids.length - failed, failed }),
        variant: 'destructive',
      });
    }
  }

  async function handleBulkToggle(activate: boolean) {
    const ids   = Array.from(selected);
    let failed  = 0;
    for (const id of ids) {
      const zone = zones.find((z) => z.id === id);
      if (!zone || zone.is_active === activate) continue;
      try { await toggleStatus.mutateAsync(id); }
      catch { failed++; }
    }
    setSelected(new Set());

    if (failed === 0) {
      toast({ title: activate ? t(($) => $.distributionZones.toast.zonesActivated) : t(($) => $.distributionZones.toast.zonesDeactivated) });
    } else {
      toast({ title: t(($) => $.distributionZones.toast.bulkUpdateFailed, { count: failed }), variant: 'destructive' });
    }
  }

  // ── Metrics ────────────────────────────────────────────────────────────────

  const metrics = [
    { id: 'total',    icon: Network,    label: t(($) => $.distributionZones.metrics.total),          value: stats?.total_zones      ?? 0, isLoading: !stats },
    { id: 'active',   icon: CheckCircle,label: t(($) => $.distributionZones.metrics.active),         value: stats?.active_zones     ?? 0, isLoading: !stats, colorClass: 'text-emerald-600' },
    { id: 'assigned', icon: MapPin,     label: t(($) => $.distributionZones.metrics.assignedAreas),  value: stats?.assigned_areas   ?? 0, isLoading: !stats },
    { id: 'free',     icon: Map,        label: t(($) => $.distributionZones.metrics.unassignedAreas),value: stats?.unassigned_areas ?? 0, isLoading: !stats, colorClass: 'text-amber-600'   },
  ];

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[
          // Shipping -> Distribution Zones — Zones is a Geography capability under
          // Shipping (TASK-DISTRIBUTION-DAILY-TEMPLATE-GROUP-LIFECYCLE-AND-NAV-001
          // Part 1A), labelled from the navigation translation keys.
          { label: navLabel.group('shipping') },
          { label: navLabel.item('logistics-distribution-zones') },
        ]}
        title={t(($) => $.distributionZones.title)}
        description={t(($) => $.distributionZones.description)}
        metrics={metrics}
      />

      <WorkspacePage
        toolbar={
          <div className="px-4 sm:px-6">
            <SmartToolbar
              primaryAction={{ label: t(($) => $.distributionZones.newZone), icon: Plus, onClick: openCreate }}
              bulkActions={
                selected.size > 0
                  ? [
                      { key: 'activate',   label: t(($) => $.distributionZones.actions.activate),   onClick: () => handleBulkToggle(true)  },
                      { key: 'deactivate', label: t(($) => $.distributionZones.actions.deactivate), onClick: () => handleBulkToggle(false) },
                      { key: 'delete',     label: t(($) => $.common.delete),     onClick: () => setBulkDeleteOpen(true), destructive: true },
                    ]
                  : []
              }
              selectedCount={selected.size}
              onRefresh={() => refetch()}
              isFetching={isFetching}
            />
          </div>
        }
        quickFilters={
          <div className="flex flex-wrap items-center gap-2 px-4 py-2 sm:px-6">
            <Input
              placeholder={t(($) => $.distributionZones.searchPlaceholder)}
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
              className="h-8 max-w-xs text-sm"
            />
            {(['all', 'active', 'inactive'] as const).map((s) => (
              <Button
                key={s}
                size="sm"
                variant={statusFilter === s ? 'secondary' : 'ghost'}
                className="h-8 text-xs"
                onClick={() => { setStatusFilter(s); setPage(1); }}
              >
                {s === 'all' ? t(($) => $.common.all)
                  : s === 'active'
                    ? <><CheckCircle className="me-1 h-3 w-3" />{t(($) => $.common.active)}</>
                    : <><XCircle className="me-1 h-3 w-3" />{t(($) => $.common.inactive)}</>}
              </Button>
            ))}
          </div>
        }
        pagination={
          meta && meta.last_page > 1 ? (
            <div className="px-4 pb-4 sm:px-6">
              <Pagination
                meta={{
                  page:     meta.current_page,
                  perPage:  meta.per_page,
                  total:    meta.total,
                  lastPage: meta.last_page,
                }}
                onPageChange={setPage}
              />
            </div>
          ) : undefined
        }
      >
        <div className="px-4 sm:px-6">
          <ZonesTable
            rows={zones}
            isLoading={isFetching && zones.length === 0}
            hasFilter={hasFilter}
            selected={selected}
            onToggleSelect={toggleSelect}
            onToggleSelectAll={toggleSelectAll}
            onRowClick={openEdit}
            onCreateFirst={openCreate}
          />
        </div>
      </WorkspacePage>

      {/* Create / Edit Drawer */}
      <DistributionZoneDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        editZone={editZone}
      />

      {/* Single Delete Confirmation */}
      <AlertDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => { if (!open) setDeleteTarget(null); }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t(($) => $.distributionZones.deleteDialog.title)}</AlertDialogTitle>
            <AlertDialogDescription>
              {t(($) => $.distributionZones.deleteDialog.bodyPrefix)} <strong>{deleteTarget?.name_ar}</strong>
              {t(($) => $.distributionZones.deleteDialog.bodySuffix, { count: deleteTarget?.areas_count ?? 0 })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t(($) => $.common.cancel)}</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteConfirm}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t(($) => $.common.delete)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Bulk Delete Confirmation */}
      <AlertDialog open={bulkDeleteOpen} onOpenChange={setBulkDeleteOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t(($) => $.distributionZones.bulkDeleteDialog.title, { count: selected.size })}</AlertDialogTitle>
            <AlertDialogDescription>
              {t(($) => $.distributionZones.bulkDeleteDialog.body)}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t(($) => $.common.cancel)}</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleBulkDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t(($) => $.distributionZones.bulkDeleteDialog.confirm)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
