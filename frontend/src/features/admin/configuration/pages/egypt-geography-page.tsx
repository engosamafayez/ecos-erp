import { useState } from 'react';
import {
  Archive,
  CheckCircle2,
  ChevronRight,
  Clock,
  Map,
  Package,
  Plus,
  Search,
  Trash2,
  XCircle,
} from 'lucide-react';

import { Badge }  from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input }  from '@/components/ui/input';
import { Label }  from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/components/ds/use-toast';
import type { MasterGov, MasterGovPayload, MasterZoneDetail, MasterZonePayload } from '../types/configuration';
import {
  useArchiveMasterGov,
  useArchiveMasterZone,
  useCreateMasterGov,
  useCreateMasterZone,
  useDeleteMasterGov,
  useDeleteMasterZone,
  useMasterGovs,
  useMasterZones,
  useUpdateMasterGov,
  useUpdateMasterZone,
} from '../hooks/use-configuration';

// ── Types ─────────────────────────────────────────────────────────────────────

type GovDialogState =
  | { mode: 'closed' }
  | { mode: 'create' }
  | { mode: 'edit'; gov: MasterGov };

type ZoneDialogState =
  | { mode: 'closed' }
  | { mode: 'create'; govId: string }
  | { mode: 'edit'; zone: MasterZoneDetail; govId: string };

type ConfirmState =
  | { mode: 'closed' }
  | { mode: 'archive-gov'; gov: MasterGov }
  | { mode: 'delete-gov'; gov: MasterGov }
  | { mode: 'archive-zone'; zone: MasterZoneDetail; govId: string }
  | { mode: 'delete-zone'; zone: MasterZoneDetail; govId: string };

// ── Difficulty badge ──────────────────────────────────────────────────────────

const DIFFICULTY_COLORS: Record<string, string> = {
  easy:   'bg-emerald-100 text-emerald-700',
  medium: 'bg-amber-100 text-amber-700',
  hard:   'bg-red-100 text-red-700',
};

// ── Governorate Form Dialog ───────────────────────────────────────────────────

function GovDialog({
  state,
  onClose,
}: {
  state: GovDialogState;
  onClose: () => void;
}) {
  const { toast } = useToast();
  const createGov = useCreateMasterGov();
  const updateGov = useUpdateMasterGov();

  const isEdit  = state.mode === 'edit';
  const initial = isEdit ? state.gov : null;

  const [name,   setName]   = useState(initial?.name ?? '');
  const [nameAr, setNameAr] = useState(initial?.name_ar ?? '');
  const [code,   setCode]   = useState(initial?.code ?? '');

  const open = state.mode !== 'closed';

  const handleSubmit = async () => {
    if (!name.trim()) return;

    try {
      if (isEdit && state.mode === 'edit') {
        const payload: Partial<MasterGovPayload> = { name: name.trim(), name_ar: nameAr.trim() || null };
        await updateGov.mutateAsync({ id: state.gov.id, payload });
        toast({ title: 'Governorate updated' });
      } else {
        if (!code.trim()) return;
        const payload: MasterGovPayload = {
          name: name.trim(),
          name_ar: nameAr.trim() || null,
          code: code.trim().toUpperCase(),
        };
        await createGov.mutateAsync(payload);
        toast({ title: 'Governorate created' });
      }
      onClose();
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Failed to save';
      toast({ title: 'Error', description: msg, variant: 'destructive' });
    }
  };

  const isPending = createGov.isPending || updateGov.isPending;

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Edit Governorate' : 'Add Governorate'}</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label>Name (English) *</Label>
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Cairo" />
          </div>
          <div className="space-y-1.5">
            <Label>Name (Arabic)</Label>
            <Input value={nameAr} onChange={(e) => setNameAr(e.target.value)} placeholder="القاهرة" dir="rtl" />
          </div>
          {!isEdit && (
            <div className="space-y-1.5">
              <Label>Code * <span className="text-muted-foreground text-xs">(immutable, 2–6 chars, e.g. CAI)</span></Label>
              <Input
                value={code}
                onChange={(e) => setCode(e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''))}
                placeholder="CAI"
                maxLength={6}
                className="font-mono tracking-wider"
              />
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={handleSubmit} disabled={isPending || !name.trim() || (!isEdit && !code.trim())}>
            {isPending ? 'Saving…' : isEdit ? 'Save' : 'Create'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Zone Form Dialog ──────────────────────────────────────────────────────────

function ZoneDialog({
  state,
  onClose,
}: {
  state: ZoneDialogState;
  onClose: () => void;
}) {
  const { toast } = useToast();
  const govId = state.mode !== 'closed' ? state.govId : '';
  const createZone = useCreateMasterZone(govId);
  const updateZone = useUpdateMasterZone(govId);

  const isEdit  = state.mode === 'edit';
  const initial = isEdit ? state.zone : null;
  const open    = state.mode !== 'closed';

  const [name,       setName]       = useState(initial?.name ?? '');
  const [slaHours,   setSlaHours]   = useState(String(initial?.estimated_delivery_sla_hours ?? ''));
  const [hub,        setHub]        = useState(initial?.default_logistics_hub ?? '');
  const [difficulty, setDifficulty] = useState(initial?.delivery_difficulty ?? '');
  const [priority,   setPriority]   = useState(String(initial?.priority ?? ''));
  const [notes,      setNotes]      = useState(initial?.notes ?? '');

  const handleSubmit = async () => {
    if (!name.trim()) return;

    const payload: MasterZonePayload = {
      name: name.trim(),
      estimated_delivery_sla_hours: slaHours ? parseInt(slaHours) : null,
      default_logistics_hub: hub.trim() || null,
      delivery_difficulty: (difficulty as 'easy' | 'medium' | 'hard') || null,
      priority: priority ? parseInt(priority) : null,
      notes: notes.trim() || null,
    };

    try {
      if (isEdit && state.mode === 'edit') {
        await updateZone.mutateAsync({ id: state.zone.id, payload });
        toast({ title: 'Zone updated' });
      } else {
        await createZone.mutateAsync(payload);
        toast({ title: 'Zone created' });
      }
      onClose();
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Failed to save';
      toast({ title: 'Error', description: msg, variant: 'destructive' });
    }
  };

  const isPending = createZone.isPending || updateZone.isPending;

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Edit Zone' : 'Add Zone'}</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label>Zone Name *</Label>
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nasr City" />
          </div>
          {isEdit && initial?.code && (
            <div className="space-y-1.5">
              <Label className="text-muted-foreground">Code (immutable)</Label>
              <Input value={initial.code} readOnly className="font-mono bg-muted" />
            </div>
          )}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label>SLA Hours</Label>
              <Input
                type="number"
                min={1}
                max={168}
                value={slaHours}
                onChange={(e) => setSlaHours(e.target.value)}
                placeholder="24"
              />
            </div>
            <div className="space-y-1.5">
              <Label>Priority (1–10)</Label>
              <Input
                type="number"
                min={1}
                max={10}
                value={priority}
                onChange={(e) => setPriority(e.target.value)}
                placeholder="5"
              />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label>Difficulty</Label>
            <Select value={difficulty} onValueChange={setDifficulty}>
              <SelectTrigger>
                <SelectValue placeholder="Select difficulty" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="">— None —</SelectItem>
                <SelectItem value="easy">Easy</SelectItem>
                <SelectItem value="medium">Medium</SelectItem>
                <SelectItem value="hard">Hard</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label>Logistics Hub</Label>
            <Input value={hub} onChange={(e) => setHub(e.target.value)} placeholder="Hub name or location" />
          </div>
          <div className="space-y-1.5">
            <Label>Notes</Label>
            <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional notes…" />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={handleSubmit} disabled={isPending || !name.trim()}>
            {isPending ? 'Saving…' : isEdit ? 'Save' : 'Create'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Confirm Dialog ────────────────────────────────────────────────────────────

function ConfirmDialog({
  state,
  onClose,
}: {
  state: ConfirmState;
  onClose: () => void;
}) {
  const { toast } = useToast();
  const archiveGov  = useArchiveMasterGov();
  const deleteGov   = useDeleteMasterGov();
  const govId       = (state.mode === 'archive-zone' || state.mode === 'delete-zone') ? state.govId : '';
  const archiveZone = useArchiveMasterZone(govId);
  const deleteZone  = useDeleteMasterZone(govId);

  const open = state.mode !== 'closed';

  const handleConfirm = async () => {
    try {
      if (state.mode === 'archive-gov') {
        await archiveGov.mutateAsync(state.gov.id);
        toast({ title: `${state.gov.name} archived` });
      } else if (state.mode === 'delete-gov') {
        await deleteGov.mutateAsync(state.gov.id);
        toast({ title: `${state.gov.name} deleted` });
      } else if (state.mode === 'archive-zone') {
        await archiveZone.mutateAsync(state.zone.id);
        toast({ title: `${state.zone.name} archived` });
      } else if (state.mode === 'delete-zone') {
        await deleteZone.mutateAsync(state.zone.id);
        toast({ title: `${state.zone.name} deleted` });
      }
      onClose();
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? 'Action failed';
      toast({ title: 'Error', description: msg, variant: 'destructive' });
    }
  };

  const isDelete    = state.mode === 'delete-gov' || state.mode === 'delete-zone';
  const isArchive   = state.mode === 'archive-gov' || state.mode === 'archive-zone';
  const label       = state.mode !== 'closed'
    ? ('gov' in state ? state.gov.name : state.zone.name)
    : '';
  const isPending   = archiveGov.isPending || deleteGov.isPending || archiveZone.isPending || deleteZone.isPending;

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>{isDelete ? 'Delete?' : 'Archive?'}</DialogTitle>
        </DialogHeader>
        <p className="text-sm text-muted-foreground py-2">
          {isDelete
            ? `Permanently delete "${label}"? This cannot be undone.`
            : `Archive "${label}"? It will no longer be available for new records.`}
        </p>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button
            variant={isDelete ? 'destructive' : 'default'}
            onClick={handleConfirm}
            disabled={isPending}
          >
            {isPending ? 'Please wait…' : isArchive ? 'Archive' : 'Delete'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Zone Row ──────────────────────────────────────────────────────────────────

function ZoneRow({
  zone,
  onEdit,
  onArchive,
  onDelete,
}: {
  zone: MasterZoneDetail;
  govId?: string;
  onEdit: () => void;
  onArchive: () => void;
  onDelete: () => void;
}) {
  return (
    <div className={`flex items-start gap-3 px-4 py-3 border-b last:border-b-0 hover:bg-muted/30 transition-colors ${zone.is_archived ? 'opacity-50' : ''}`}>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          {zone.code && (
            <span className="font-mono text-xs bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded border border-slate-200 shrink-0">
              {zone.code}
            </span>
          )}
          <span className="font-medium text-sm truncate">{zone.name}</span>
          {zone.is_archived && <Badge variant="outline" className="text-xs shrink-0">Archived</Badge>}
          {!zone.is_active && !zone.is_archived && <Badge variant="secondary" className="text-xs shrink-0">Inactive</Badge>}
        </div>
        <div className="flex items-center gap-3 mt-1 text-xs text-muted-foreground">
          {zone.estimated_delivery_sla_hours && (
            <span className="flex items-center gap-1">
              <Clock className="h-3 w-3" />
              {zone.estimated_delivery_sla_hours}h SLA
            </span>
          )}
          {zone.delivery_difficulty && (
            <span className={`px-1.5 py-0.5 rounded capitalize ${DIFFICULTY_COLORS[zone.delivery_difficulty]}`}>
              {zone.delivery_difficulty}
            </span>
          )}
          {zone.default_logistics_hub && (
            <span className="flex items-center gap-1">
              <Package className="h-3 w-3" />
              {zone.default_logistics_hub}
            </span>
          )}
          {zone.dependency_count !== undefined && zone.dependency_count > 0 && (
            <span className="text-blue-600">{zone.dependency_count} brand zone(s)</span>
          )}
        </div>
      </div>
      <div className="flex items-center gap-1 shrink-0">
        <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={onEdit}>
          Edit
        </Button>
        {!zone.is_archived && (
          <Button size="sm" variant="ghost" className="h-7 w-7 p-0 text-amber-600 hover:text-amber-700" onClick={onArchive} title="Archive">
            <Archive className="h-3.5 w-3.5" />
          </Button>
        )}
        {(zone.dependency_count ?? 0) === 0 && (
          <Button size="sm" variant="ghost" className="h-7 w-7 p-0 text-red-500 hover:text-red-600" onClick={onDelete} title="Delete">
            <Trash2 className="h-3.5 w-3.5" />
          </Button>
        )}
      </div>
    </div>
  );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export function EgyptGeographyPage() {
  const [search,       setSearch]       = useState('');
  const [selectedGov,  setSelectedGov]  = useState<MasterGov | null>(null);
  const [govDialog,    setGovDialog]    = useState<GovDialogState>({ mode: 'closed' });
  const [zoneDialog,   setZoneDialog]   = useState<ZoneDialogState>({ mode: 'closed' });
  const [confirmState, setConfirmState] = useState<ConfirmState>({ mode: 'closed' });
  const [showArchived, setShowArchived] = useState(false);

  const { data: govs = [], isLoading: govsLoading } = useMasterGovs();
  const { data: zones = [], isLoading: zonesLoading } = useMasterZones(selectedGov?.id ?? null);

  const filteredGovs = govs.filter((g) => {
    const matchesSearch =
      !search ||
      g.name.toLowerCase().includes(search.toLowerCase()) ||
      g.code.toLowerCase().includes(search.toLowerCase()) ||
      (g.name_ar?.includes(search) ?? false);
    const matchesArchive = showArchived || !g.is_archived;
    return matchesSearch && matchesArchive;
  });

  const filteredZones = zones.filter((z) => {
    const matchesSearch =
      !search ||
      z.name.toLowerCase().includes(search.toLowerCase()) ||
      (z.code?.toLowerCase().includes(search.toLowerCase()) ?? false);
    const matchesArchive = showArchived || !z.is_archived;
    return matchesSearch && matchesArchive;
  });

  const activeGovCount    = govs.filter((g) => g.is_active && !g.is_archived).length;
  const archivedGovCount  = govs.filter((g) => g.is_archived).length;
  const totalZoneCount    = govs.reduce((n, g) => n + (g.zones_count ?? 0), 0);

  return (
    <div className="h-full flex flex-col">
      {/* ── Page Header ── */}
      <div className="border-b px-6 py-4 shrink-0">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="p-2 rounded-lg bg-slate-100">
              <Map className="h-5 w-5 text-slate-600" />
            </div>
            <div>
              <h1 className="text-lg font-semibold">Egypt Geography</h1>
              <p className="text-sm text-muted-foreground">
                {activeGovCount} governorates · {totalZoneCount} zones
                {archivedGovCount > 0 && ` · ${archivedGovCount} archived`}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant={showArchived ? 'secondary' : 'ghost'}
              size="sm"
              className="text-xs"
              onClick={() => setShowArchived((v) => !v)}
            >
              {showArchived ? <CheckCircle2 className="h-3.5 w-3.5 mr-1.5" /> : <XCircle className="h-3.5 w-3.5 mr-1.5" />}
              {showArchived ? 'Hiding archived' : 'Show archived'}
            </Button>
            <Button size="sm" onClick={() => setGovDialog({ mode: 'create' })}>
              <Plus className="h-4 w-4 mr-1.5" />
              Add Governorate
            </Button>
          </div>
        </div>

        {/* Search */}
        <div className="relative mt-3 max-w-sm">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name or code…"
            className="pl-8 h-8 text-sm"
          />
        </div>
      </div>

      {/* ── Two-panel layout ── */}
      <div className="flex-1 flex overflow-hidden">
        {/* Left: Governorate list */}
        <div className="w-80 shrink-0 border-r overflow-y-auto">
          {govsLoading ? (
            <div className="p-4 text-sm text-muted-foreground">Loading…</div>
          ) : filteredGovs.length === 0 ? (
            <div className="p-4 text-sm text-muted-foreground">No governorates found</div>
          ) : (
            filteredGovs.map((gov) => {
              const isSelected = selectedGov?.id === gov.id;
              return (
                <button
                  key={gov.id}
                  className={`w-full text-start px-4 py-3 border-b flex items-center gap-3 hover:bg-muted/40 transition-colors ${isSelected ? 'bg-muted border-s-2 border-s-primary' : ''} ${gov.is_archived ? 'opacity-50' : ''}`}
                  onClick={() => setSelectedGov(gov)}
                >
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-xs bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded border border-slate-200 shrink-0">
                        {gov.code}
                      </span>
                      <span className="font-medium text-sm truncate">{gov.name}</span>
                    </div>
                    {gov.name_ar && (
                      <div className="text-xs text-muted-foreground mt-0.5 truncate" dir="rtl">{gov.name_ar}</div>
                    )}
                    <div className="flex items-center gap-2 mt-1 text-xs text-muted-foreground">
                      <span>{gov.zones_count ?? 0} zones</span>
                      {gov.is_archived && <Badge variant="outline" className="text-xs py-0">Archived</Badge>}
                      {!gov.is_active && !gov.is_archived && <Badge variant="secondary" className="text-xs py-0">Inactive</Badge>}
                    </div>
                  </div>
                  <div className="flex items-center gap-1 shrink-0">
                    <Button
                      size="sm"
                      variant="ghost"
                      className="h-6 px-2 text-xs opacity-0 group-hover:opacity-100"
                      onClick={(e) => {
                        e.stopPropagation();
                        setGovDialog({ mode: 'edit', gov });
                      }}
                    >
                      Edit
                    </Button>
                    <ChevronRight className={`h-4 w-4 text-muted-foreground ${isSelected ? 'text-primary' : ''}`} />
                  </div>
                </button>
              );
            })
          )}
        </div>

        {/* Right: Zone list */}
        <div className="flex-1 overflow-y-auto">
          {!selectedGov ? (
            <div className="flex flex-col items-center justify-center h-full text-muted-foreground gap-2">
              <Map className="h-10 w-10 opacity-20" />
              <p className="text-sm">Select a governorate to view its zones</p>
            </div>
          ) : (
            <>
              {/* Zone panel header */}
              <div className="sticky top-0 bg-background border-b px-5 py-3 flex items-center justify-between gap-2 z-10">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-xs bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded border border-slate-200">
                      {selectedGov.code}
                    </span>
                    <span className="font-medium">{selectedGov.name}</span>
                  </div>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {filteredZones.length} zone{filteredZones.length !== 1 ? 's' : ''}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    size="sm"
                    variant="ghost"
                    className="text-xs"
                    onClick={() => setGovDialog({ mode: 'edit', gov: selectedGov })}
                  >
                    Edit Governorate
                  </Button>
                  {!selectedGov.is_archived && (
                    <Button
                      size="sm"
                      variant="ghost"
                      className="text-xs text-amber-600 hover:text-amber-700"
                      onClick={() => setConfirmState({ mode: 'archive-gov', gov: selectedGov })}
                    >
                      <Archive className="h-3.5 w-3.5 mr-1" />
                      Archive
                    </Button>
                  )}
                  {(selectedGov.brand_geo_count ?? 1) === 0 && (
                    <Button
                      size="sm"
                      variant="ghost"
                      className="text-xs text-red-500 hover:text-red-600"
                      onClick={() => setConfirmState({ mode: 'delete-gov', gov: selectedGov })}
                    >
                      <Trash2 className="h-3.5 w-3.5 mr-1" />
                      Delete
                    </Button>
                  )}
                  <Button
                    size="sm"
                    onClick={() => setZoneDialog({ mode: 'create', govId: selectedGov.id })}
                  >
                    <Plus className="h-4 w-4 mr-1.5" />
                    Add Zone
                  </Button>
                </div>
              </div>

              {/* Zone rows */}
              {zonesLoading ? (
                <div className="p-4 text-sm text-muted-foreground">Loading zones…</div>
              ) : filteredZones.length === 0 ? (
                <div className="p-6 text-sm text-muted-foreground text-center">No zones found</div>
              ) : (
                <div>
                  {filteredZones.map((zone) => (
                    <ZoneRow
                      key={zone.id}
                      zone={zone}
                      govId={selectedGov.id}
                      onEdit={() => setZoneDialog({ mode: 'edit', zone, govId: selectedGov.id })}
                      onArchive={() => setConfirmState({ mode: 'archive-zone', zone, govId: selectedGov.id })}
                      onDelete={() => setConfirmState({ mode: 'delete-zone', zone, govId: selectedGov.id })}
                    />
                  ))}
                </div>
              )}
            </>
          )}
        </div>
      </div>

      {/* ── Dialogs ── */}
      <GovDialog
        state={govDialog}
        onClose={() => setGovDialog({ mode: 'closed' })}
      />
      <ZoneDialog
        state={zoneDialog}
        onClose={() => setZoneDialog({ mode: 'closed' })}
      />
      <ConfirmDialog
        state={confirmState}
        onClose={() => setConfirmState({ mode: 'closed' })}
      />
    </div>
  );
}
