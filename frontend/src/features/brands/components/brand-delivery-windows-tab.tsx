import { useState } from 'react';
import { Clock, Pencil, Plus, Sparkles, Trash2 } from 'lucide-react';

import { Badge }   from '@/components/ui/badge';
import { Button }  from '@/components/ui/button';
import { Input }   from '@/components/ui/input';
import { Label }   from '@/components/ui/label';
import { Switch }  from '@/components/ui/switch';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import {
  ActionMenu,
  ConfirmDialog,
  EntityToolbar,
} from '@/components/crud';
import type { DataGridColumnDef } from '@/components/data-grid';
import { UniversalDataGrid } from '@/components/data-grid';
import { useToast } from '@/components/ds/use-toast';
import { WorkspaceMetricCard } from '@/components/workspace';
import type { BrandDeliveryTimeSlot, BrandDeliveryTimeSlotPayload } from '@/features/brands/types/brand';
import {
  useCreateDeliveryTimeSlot,
  useDeleteDeliveryTimeSlot,
  useDeliveryTimeSlots,
  useSeedDeliveryTimeSlots,
  useUpdateDeliveryTimeSlot,
} from '@/features/brands/hooks/use-brand-delivery';

// ── helpers ───────────────────────────────────────────────────────────────────

function fmtTime(t: string) {
  // "09:00:00" → "09:00"
  return t.slice(0, 5);
}

// ── Create / Edit drawer ──────────────────────────────────────────────────────

type SlotDrawerProps = {
  brandId: string;
  slot: BrandDeliveryTimeSlot | null;
  open: boolean;
  onOpenChange: (v: boolean) => void;
};

function SlotDrawer({ brandId, slot, open, onOpenChange }: SlotDrawerProps) {
  const { toast } = useToast();
  const isEdit = Boolean(slot);

  const [name,      setName]      = useState(slot?.name      ?? '');
  const [startTime, setStartTime] = useState(fmtTime(slot?.start_time ?? '09:00'));
  const [endTime,   setEndTime]   = useState(fmtTime(slot?.end_time   ?? '12:00'));
  const [isActive,  setIsActive]  = useState(slot?.is_active ?? true);

  const createMut = useCreateDeliveryTimeSlot(brandId);
  const updateMut = useUpdateDeliveryTimeSlot(brandId);
  const busy = createMut.isPending || updateMut.isPending;

  function resetAndClose() {
    setName('');
    setStartTime('09:00');
    setEndTime('12:00');
    setIsActive(true);
    onOpenChange(false);
  }

  function handleOpen(v: boolean) {
    if (v) {
      setName(slot?.name      ?? '');
      setStartTime(fmtTime(slot?.start_time ?? '09:00'));
      setEndTime(fmtTime(slot?.end_time     ?? '12:00'));
      setIsActive(slot?.is_active ?? true);
    }
    if (!v) resetAndClose();
    else onOpenChange(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload: BrandDeliveryTimeSlotPayload = {
      name: name.trim(),
      start_time: startTime,
      end_time:   endTime,
      is_active:  isActive,
    };
    try {
      if (isEdit && slot) {
        await updateMut.mutateAsync({ slotId: slot.id, payload });
        toast({ title: 'Time slot updated' });
      } else {
        await createMut.mutateAsync(payload);
        toast({ title: 'Time slot created' });
      }
      resetAndClose();
    } catch {
      toast({ title: 'Error saving time slot', variant: 'destructive' });
    }
  }

  return (
    <Sheet open={open} onOpenChange={handleOpen}>
      <SheetContent className="flex flex-col">
        <SheetHeader>
          <SheetTitle>{isEdit ? 'Edit Time Slot' : 'New Time Slot'}</SheetTitle>
          <SheetDescription>
            {isEdit ? 'Update delivery window details.' : 'Add a customer-facing delivery time window.'}
          </SheetDescription>
        </SheetHeader>

        <form onSubmit={handleSubmit} className="flex flex-col gap-4 flex-1 pt-4">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="slot-name">Name</Label>
            <Input
              id="slot-name"
              placeholder="e.g. Morning (9 AM – 12 PM)"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="slot-start">Start Time</Label>
              <Input
                id="slot-start"
                type="time"
                value={startTime}
                onChange={(e) => setStartTime(e.target.value)}
                required
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="slot-end">End Time</Label>
              <Input
                id="slot-end"
                type="time"
                value={endTime}
                onChange={(e) => setEndTime(e.target.value)}
                required
              />
            </div>
          </div>

          <div className="flex items-center justify-between rounded-md border px-3 py-2.5">
            <div>
              <p className="text-sm font-medium">Active</p>
              <p className="text-xs text-muted-foreground">Show to customers at checkout</p>
            </div>
            <Switch checked={isActive} onCheckedChange={setIsActive} />
          </div>

          <div className="flex gap-2 mt-auto">
            <Button type="button" variant="outline" className="flex-1" onClick={resetAndClose}>
              Cancel
            </Button>
            <Button type="submit" className="flex-1" disabled={busy}>
              {busy ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Slot'}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  );
}

// ── Main tab ──────────────────────────────────────────────────────────────────

/* eslint-disable ecos-i18n/no-hardcoded-ui-strings -- these column labels were
   pre-existing plain strings under the deprecated ColumnDef's `header` field
   (not a monitored key); `DataGridColumnDef.label` is the same pre-existing
   copy under its required field name. Converting this tab to full i18n is
   out of scope for the EntityTable -> UniversalDataGrid presentation migration. */
const COLUMNS: DataGridColumnDef<BrandDeliveryTimeSlot>[] = [
  {
    key:    'order',
    label: '#',
    cell:   (r) => <span className="text-muted-foreground text-xs">{r.display_order}</span>,
    align:  'center',
  },
  {
    key:    'name',
    label: 'Name',
    cardRole: 'title',
    cell:   (r) => <span className="font-medium text-sm">{r.name}</span>,
  },
  {
    key:    'times',
    label: 'Window',
    cell:   (r) => (
      <span className="font-mono text-xs text-muted-foreground">
        {fmtTime(r.start_time)} – {fmtTime(r.end_time)}
      </span>
    ),
  },
  {
    key:    'status',
    label: 'Status',
    cardRole: 'status',
    cell:   (r) => (
      <Badge variant={r.is_active ? 'default' : 'secondary'} className="text-[10px]">
        {r.is_active ? 'Active' : 'Inactive'}
      </Badge>
    ),
    align: 'center',
  },
];
/* eslint-enable ecos-i18n/no-hardcoded-ui-strings */

type Props = { brandId: string };

export function BrandDeliveryWindowsTab({ brandId }: Props) {
  const { toast }  = useToast();
  const { data: slots = [], isLoading, isError, refetch } = useDeliveryTimeSlots(brandId);

  const deleteMut = useDeleteDeliveryTimeSlot(brandId);
  const seedMut   = useSeedDeliveryTimeSlots(brandId);

  const [search,      setSearch]      = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [drawerOpen,  setDrawerOpen]  = useState(false);
  const [editSlot,    setEditSlot]    = useState<BrandDeliveryTimeSlot | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<BrandDeliveryTimeSlot | null>(null);

  const total    = slots.length;
  const active   = slots.filter((s) => s.is_active).length;
  const inactive = total - active;

  const filtered = slots.filter((s) => {
    const matchSearch = !search || s.name.toLowerCase().includes(search.toLowerCase());
    const matchStatus =
      statusFilter === 'all'
        ? true
        : statusFilter === 'active'
        ? s.is_active
        : !s.is_active;
    return matchSearch && matchStatus;
  });

  function openCreate() {
    setEditSlot(null);
    setDrawerOpen(true);
  }

  function openEdit(slot: BrandDeliveryTimeSlot) {
    setEditSlot(slot);
    setDrawerOpen(true);
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    try {
      await deleteMut.mutateAsync(deleteTarget.id);
      toast({ title: 'Time slot deleted' });
    } catch {
      toast({ title: 'Failed to delete time slot', variant: 'destructive' });
    } finally {
      setDeleteTarget(null);
    }
  }

  async function handleSeed() {
    try {
      await seedMut.mutateAsync();
      toast({ title: 'Default time slots seeded' });
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to seed defaults';
      toast({ title: msg, variant: 'destructive' });
    }
  }

  return (
    <div className="flex flex-col gap-4">
      {/* KPI row */}
      <div className="grid grid-cols-3 gap-2">
        <WorkspaceMetricCard
          id="total"
          icon={Clock}
          label="Total Slots"
          value={total}
          active={statusFilter === 'all'}
          onClick={() => setStatusFilter('all')}
        />
        <WorkspaceMetricCard
          id="active"
          icon={Clock}
          label="Active"
          value={active}
          active={statusFilter === 'active'}
          colorClass="text-emerald-600 bg-emerald-50 dark:bg-emerald-950"
          onClick={() => setStatusFilter('active')}
        />
        <WorkspaceMetricCard
          id="inactive"
          icon={Clock}
          label="Inactive"
          value={inactive}
          active={statusFilter === 'inactive'}
          colorClass="text-slate-500 bg-slate-100 dark:bg-slate-800"
          onClick={() => setStatusFilter('inactive')}
        />
      </div>

      {/* Toolbar */}
      <EntityToolbar
        searchPlaceholder="Search time slots…"
        onSearchChange={setSearch}
        onRefresh={() => refetch()}
      >
        {total === 0 && (
          <Button
            variant="outline"
            size="sm"
            onClick={handleSeed}
            disabled={seedMut.isPending}
          >
            <Sparkles className="size-3.5 mr-1" />
            Seed Defaults
          </Button>
        )}
        <Button size="sm" onClick={openCreate}>
          <Plus className="size-3.5 mr-1" />
          New Slot
        </Button>
      </EntityToolbar>

      {/* Table */}
      <UniversalDataGrid<BrandDeliveryTimeSlot>
        data={filtered}
        columns={[
          ...COLUMNS,
          {
            key: 'actions',
            label: '',
            align: 'end',
            alwaysVisible: true,
            cell: (slot) => (
              <ActionMenu
                label={`Actions for ${slot.name}`}
                items={[
                  {
                    key:      'edit',
                    label:    'Edit',
                    icon:     Pencil,
                    onSelect: () => openEdit(slot),
                  },
                  {
                    key:      'delete',
                    label:    'Delete',
                    icon:     Trash2,
                    variant:  'destructive',
                    onSelect: () => setDeleteTarget(slot),
                  },
                ]}
              />
            ),
          },
        ]}
        rowId={(r) => r.id}
        loading={isLoading}
        error={isError}
        skeletonRows={4}
      />

      {/* Create / Edit Drawer */}
      <SlotDrawer
        brandId={brandId}
        slot={editSlot}
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
      />

      {/* Delete Confirm */}
      <ConfirmDialog
        open={Boolean(deleteTarget)}
        onOpenChange={(v) => !v && setDeleteTarget(null)}
        title="Delete Time Slot"
        description={`Delete "${deleteTarget?.name}"? This cannot be undone.`}
        confirmLabel="Delete"
        variant="destructive"
        onConfirm={handleDelete}
        loading={deleteMut.isPending}
      />
    </div>
  );
}
