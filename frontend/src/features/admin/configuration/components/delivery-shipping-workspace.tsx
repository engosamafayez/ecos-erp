import { useState } from 'react';
import {
  ChevronDown,
  ChevronRight,
  Download,
  Loader2,
  MapPin,
  Pencil,
  Plus,
  Search,
  Trash2,
  Truck,
  X,
} from 'lucide-react';

import { Badge }    from '@/components/ui/badge';
import { Button }   from '@/components/ui/button';
import { Input }    from '@/components/ui/input';
import { Label }    from '@/components/ui/label';
import { Switch }   from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { useToast } from '@/components/ds/use-toast';

import { EGYPT_GOVERNORATES, EGYPT_DEFAULT_ZONES } from '../data/egypt-zones';
import {
  useDeliveryGeographies,
  useDeleteGeography,
  useDeleteZone,
  useCreateZoneWithRule,
  useEditZoneWithRule,
  useBulkImportEgyptZones,
  type ZoneWithRulePayload,
  type BulkImportProgress,
} from '../hooks/use-configuration';
import { useBrandDeliveryWindows } from '@/features/brands/hooks/use-brand-delivery';
import type { DeliveryGeography, DeliveryZone } from '../types/configuration';

// ── Types ─────────────────────────────────────────────────────────────────────

type DrawerState =
  | { mode: 'closed' }
  | { mode: 'create' }
  | { mode: 'edit'; zone: DeliveryZone; geo: DeliveryGeography };

type ZoneFormState = {
  governorateIndex: number;  // index into EGYPT_GOVERNORATES; -1 = custom
  zoneName:         string;
  shippingCost:     string;
  deliveryWindowId: string;
  isActive:         boolean;
  notes:            string;
};

const EMPTY_FORM: ZoneFormState = {
  governorateIndex: -1,
  zoneName:         '',
  shippingCost:     '',
  deliveryWindowId: '',
  isActive:         true,
  notes:            '',
};

// ── Main Component ────────────────────────────────────────────────────────────

export function DeliveryShippingWorkspace({ brandId }: { brandId: string }) {
  const { toast } = useToast();

  const { data: geos    = [], isLoading: geosLoading } = useDeliveryGeographies(brandId);
  const { data: windows = []                          } = useBrandDeliveryWindows(brandId);

  const createZoneWithRule = useCreateZoneWithRule(brandId);
  const editZoneWithRule   = useEditZoneWithRule(brandId);
  const bulkImport         = useBulkImportEgyptZones(brandId);

  const [search,  setSearch]  = useState('');
  const [drawer,  setDrawer]  = useState<DrawerState>({ mode: 'closed' });
  const [form,    setForm]    = useState<ZoneFormState>(EMPTY_FORM);
  const [importProgress, setImportProgress] = useState<BulkImportProgress | null>(null);

  // ── KPIs ──

  const allZones    = geos.flatMap((g) => g.zones);
  const activeZones = allZones.filter((z) => z.is_active);
  const ruledZones  = allZones.filter((z) => z.shipping_rule);
  const avgCost     = ruledZones.length
    ? ruledZones.reduce((s, z) => s + (z.shipping_rule?.shipping_cost ?? 0), 0) / ruledZones.length
    : 0;

  // ── Filter ──

  const filteredGeos = search
    ? geos.filter(
        (g) =>
          g.name.toLowerCase().includes(search.toLowerCase()) ||
          g.zones.some((z) => z.name.toLowerCase().includes(search.toLowerCase())),
      )
    : geos;

  // ── Drawer helpers ──

  function openCreate() {
    setForm(EMPTY_FORM);
    setDrawer({ mode: 'create' });
  }

  function openEdit(zone: DeliveryZone, geo: DeliveryGeography) {
    setForm({
      governorateIndex: -1,
      zoneName:         zone.name,
      shippingCost:     String(zone.shipping_rule?.shipping_cost ?? ''),
      deliveryWindowId: zone.shipping_rule?.delivery_window_id ?? '',
      isActive:         zone.is_active,
      notes:            zone.shipping_rule?.notes ?? '',
    });
    setDrawer({ mode: 'edit', zone, geo });
  }

  function closeDrawer() {
    setDrawer({ mode: 'closed' });
    setForm(EMPTY_FORM);
  }

  // ── Save handlers ──

  async function handleCreate() {
    const govIndex = form.governorateIndex;
    const gov = govIndex >= 0 ? EGYPT_GOVERNORATES[govIndex] : null;

    if (!gov) {
      toast({ title: 'Select a governorate', description: 'Choose from the list.', type: 'error' });
      return;
    }
    if (!form.zoneName.trim()) {
      toast({ title: 'Zone name required', type: 'error' });
      return;
    }
    const cost = parseFloat(form.shippingCost);
    if (isNaN(cost) || cost < 0) {
      toast({ title: 'Enter a valid shipping cost', type: 'error' });
      return;
    }

    // Find existing geography for this governorate (by name match)
    const existingGeo = geos.find(
      (g) => g.name.toLowerCase() === gov.name.toLowerCase(),
    );

    const payload: ZoneWithRulePayload = {
      geoId:             existingGeo?.id ?? null,
      governorateName:   gov.name,
      governorateNameAr: gov.nameAr,
      governorateCode:   gov.code,
      zoneName:          form.zoneName.trim(),
      shippingCost:      cost,
      deliveryWindowId:  form.deliveryWindowId || null,
      isActive:          form.isActive,
      notes:             form.notes,
    };

    await createZoneWithRule.mutateAsync(payload);
    toast({ title: 'Zone created', description: `${gov.name} / ${form.zoneName}`, type: 'success' });
    closeDrawer();
  }

  async function handleEdit() {
    if (drawer.mode !== 'edit') return;
    const { zone, geo } = drawer;

    if (!form.zoneName.trim()) {
      toast({ title: 'Zone name required', type: 'error' });
      return;
    }
    const cost = parseFloat(form.shippingCost);
    if (isNaN(cost) || cost < 0) {
      toast({ title: 'Enter a valid shipping cost', type: 'error' });
      return;
    }

    await editZoneWithRule.mutateAsync({
      zone: {
        id:       zone.id,
        geoId:    geo.id,
        name:     form.zoneName.trim(),
        isActive: form.isActive,
      },
      rule: {
        id:               zone.shipping_rule?.id ?? null,
        shippingCost:     cost,
        deliveryWindowId: form.deliveryWindowId || null,
        notes:            form.notes,
        isEnabled:        form.isActive,
      },
    });

    toast({ title: 'Zone updated', type: 'success' });
    closeDrawer();
  }

  // ── Bulk import ──

  async function handleBulkImport() {
    if (!confirm(`Import Egypt default zones for this brand?\n\nExisting governorates and zones will be preserved — only new ones are added.`)) return;

    setImportProgress({ total: 0, done: 0, current: 'Starting…', errors: [] });

    const result = await bulkImport.mutateAsync({
      defaultZones: EGYPT_DEFAULT_ZONES,
      existingGeos: [...geos],
      onProgress:   (p) => setImportProgress(p),
    });

    setImportProgress(null);
    toast({
      title:       'Import complete',
      description: result.errors.length
        ? `${result.done} zones processed. ${result.errors.length} errors.`
        : `${result.done} zones processed successfully.`,
      type: result.errors.length ? 'error' : 'success',
    });
  }

  // ── Render ──

  return (
    <div className="p-6 space-y-5 max-w-4xl">

      {/* KPI cards */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <KpiCard icon={<MapPin className="h-4 w-4" />}  label="Governorates"     value={geos.length} />
        <KpiCard icon={<Truck  className="h-4 w-4" />}  label="Zones"            value={allZones.length} />
        <KpiCard icon={<Badge  className="h-4 w-4 text-green-600" />} label="Active Zones" value={activeZones.length} />
        <KpiCard
          icon={<span className="text-xs font-bold">EGP</span>}
          label="Avg Shipping Cost"
          value={ruledZones.length ? `${avgCost.toFixed(0)} EGP` : '—'}
        />
      </div>

      {/* Bulk import progress */}
      {importProgress && (
        <div className="rounded-lg border border-border/60 bg-muted/20 px-4 py-3 space-y-2">
          <div className="flex items-center gap-2">
            <Loader2 className="h-3.5 w-3.5 animate-spin text-primary shrink-0" />
            <p className="text-xs font-medium">
              Importing Egypt default zones… {importProgress.done}/{importProgress.total}
            </p>
          </div>
          {importProgress.current && (
            <p className="text-xs text-muted-foreground pl-5">{importProgress.current}</p>
          )}
          <div className="h-1.5 bg-muted rounded-full overflow-hidden">
            <div
              className="h-full bg-primary transition-all"
              style={{
                width: importProgress.total
                  ? `${Math.round((importProgress.done / importProgress.total) * 100)}%`
                  : '0%',
              }}
            />
          </div>
        </div>
      )}

      {/* Toolbar */}
      <div className="flex items-center gap-3">
        <div className="relative flex-1 max-w-xs">
          <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
          <Input
            placeholder="Search governorates or zones…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="h-8 pl-8 text-xs"
          />
          {search && (
            <button
              onClick={() => setSearch('')}
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
            >
              <X className="h-3 w-3" />
            </button>
          )}
        </div>
        <Button
          size="sm"
          variant="outline"
          onClick={handleBulkImport}
          disabled={bulkImport.isPending || !!importProgress}
          className="gap-1.5 text-xs"
        >
          <Download className="h-3.5 w-3.5" />
          Import Egypt Defaults
        </Button>
        <Button size="sm" onClick={openCreate} className="gap-1.5 text-xs">
          <Plus className="h-3.5 w-3.5" />
          Add Zone
        </Button>
      </div>

      {/* Governorate accordion */}
      {geosLoading ? (
        <LoadingBlock />
      ) : filteredGeos.length === 0 ? (
        <EmptyBlock
          message={
            search
              ? 'No results match your search.'
              : 'No governorates yet. Click "Add Zone" to start or use "Import Egypt Defaults".'
          }
        />
      ) : (
        <div className="space-y-2">
          {filteredGeos.map((geo) => (
            <GovernorateBand
              key={geo.id}
              brandId={brandId}
              geo={geo}
              search={search}
              windows={windows}
              onEditZone={(zone) => openEdit(zone, geo)}
            />
          ))}
        </div>
      )}

      {/* Zone Drawer */}
      <Sheet open={drawer.mode !== 'closed'} onOpenChange={(open) => { if (!open) closeDrawer(); }}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>
              {drawer.mode === 'create' ? 'Add New Zone' : 'Edit Zone'}
            </SheetTitle>
          </SheetHeader>

          <div className="mt-6 space-y-4 overflow-y-auto max-h-[calc(100vh-10rem)] pr-1">
            {drawer.mode === 'create' ? (
              <div className="space-y-1">
                <Label className="text-xs">Governorate *</Label>
                <select
                  value={form.governorateIndex}
                  onChange={(e) => setForm({ ...form, governorateIndex: parseInt(e.target.value) })}
                  className="w-full h-9 text-sm rounded-md border border-input bg-background px-2 focus:outline-none focus:ring-1 focus:ring-ring"
                >
                  <option value={-1}>Select governorate…</option>
                  {EGYPT_GOVERNORATES.map((g, i) => (
                    <option key={g.code} value={i}>
                      {g.name} ({g.nameAr})
                    </option>
                  ))}
                </select>
              </div>
            ) : (
              <div className="rounded-md bg-muted/40 px-3 py-2 text-sm">
                <span className="text-xs text-muted-foreground">Governorate: </span>
                <span className="font-medium">
                  {drawer.mode === 'edit' ? drawer.geo.name : ''}
                </span>
              </div>
            )}

            <div className="space-y-1">
              <Label className="text-xs">Zone Name *</Label>
              <Input
                value={form.zoneName}
                onChange={(e) => setForm({ ...form, zoneName: e.target.value })}
                placeholder="e.g. Nasr City"
                className="h-9 text-sm"
              />
            </div>

            <div className="space-y-1">
              <Label className="text-xs">Shipping Cost (EGP) *</Label>
              <Input
                type="number"
                min="0"
                step="0.01"
                value={form.shippingCost}
                onChange={(e) => setForm({ ...form, shippingCost: e.target.value })}
                placeholder="0.00"
                className="h-9 text-sm"
              />
            </div>

            <div className="space-y-1">
              <Label className="text-xs">Delivery Window</Label>
              <select
                value={form.deliveryWindowId}
                onChange={(e) => setForm({ ...form, deliveryWindowId: e.target.value })}
                className="w-full h-9 text-sm rounded-md border border-input bg-background px-2 focus:outline-none focus:ring-1 focus:ring-ring"
              >
                <option value="">No specific window</option>
                {windows.map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.label} ({w.starts_at}–{w.ends_at})
                  </option>
                ))}
              </select>
            </div>

            <div className="flex items-center gap-3">
              <Switch
                checked={form.isActive}
                onCheckedChange={(v) => setForm({ ...form, isActive: v })}
              />
              <Label className="text-xs cursor-pointer" onClick={() => setForm({ ...form, isActive: !form.isActive })}>
                Active zone
              </Label>
            </div>

            <div className="space-y-1">
              <Label className="text-xs">Notes</Label>
              <Textarea
                value={form.notes}
                onChange={(e) => setForm({ ...form, notes: e.target.value })}
                placeholder="Optional notes…"
                className="text-sm min-h-20 resize-none"
              />
            </div>
          </div>

          <div className="mt-6 flex gap-3 border-t border-border/60 pt-4">
            <Button
              className="flex-1"
              onClick={drawer.mode === 'create' ? handleCreate : handleEdit}
              disabled={createZoneWithRule.isPending || editZoneWithRule.isPending}
            >
              {(createZoneWithRule.isPending || editZoneWithRule.isPending)
                ? <Loader2 className="h-4 w-4 animate-spin mr-2" />
                : null}
              {drawer.mode === 'create' ? 'Create Zone' : 'Save Changes'}
            </Button>
            <Button variant="outline" onClick={closeDrawer}>
              Cancel
            </Button>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}

// ── Governorate Band ──────────────────────────────────────────────────────────

function GovernorateBand({
  brandId,
  geo,
  search,
  onEditZone,
}: {
  brandId:    string;
  geo:        DeliveryGeography;
  search:     string;
  windows?:   { id: string; label: string }[];
  onEditZone: (zone: DeliveryZone) => void;
}) {
  const [expanded, setExpanded] = useState(!!search);
  const deleteGeo = useDeleteGeography(brandId);
  const { toast } = useToast();

  const visibleZones = search
    ? geo.zones.filter((z) => z.name.toLowerCase().includes(search.toLowerCase()))
    : geo.zones;

  async function handleDeleteGeo() {
    if (!confirm(`Delete "${geo.name}" and all its zones? This cannot be undone.`)) return;
    await deleteGeo.mutateAsync(geo.id);
    toast({ title: 'Governorate deleted', type: 'success' });
  }

  return (
    <div className="rounded-lg border border-border/60 bg-card overflow-hidden">
      {/* Governorate header */}
      <div className="flex items-center gap-2 px-4 py-2.5">
        <button
          onClick={() => setExpanded(!expanded)}
          className="text-muted-foreground hover:text-foreground transition-colors"
        >
          {expanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
        </button>

        <div className="flex-1 flex items-center gap-2 min-w-0">
          <span className="text-sm font-medium">{geo.name}</span>
          {geo.name_ar && (
            <span className="text-xs text-muted-foreground">{geo.name_ar}</span>
          )}
          {geo.code && (
            <Badge variant="outline" className="text-[10px] py-0 h-4">{geo.code}</Badge>
          )}
          <Badge className="text-[10px] py-0 h-4 bg-muted text-muted-foreground border-0">
            {geo.zones.length} zones
          </Badge>
          {!geo.is_active && (
            <Badge className="text-[10px] py-0 h-4 bg-amber-50 text-amber-700 border-0">
              Inactive
            </Badge>
          )}
        </div>

        <button
          onClick={handleDeleteGeo}
          disabled={deleteGeo.isPending}
          className="text-muted-foreground hover:text-destructive transition-colors shrink-0"
          title="Delete governorate"
        >
          {deleteGeo.isPending
            ? <Loader2 className="h-3.5 w-3.5 animate-spin" />
            : <Trash2 className="h-3.5 w-3.5" />
          }
        </button>
      </div>

      {/* Zone list */}
      {expanded && (
        <div className="border-t border-border/40 bg-muted/10 divide-y divide-border/30">
          {visibleZones.length === 0 ? (
            <p className="px-6 py-3 text-xs text-muted-foreground">No zones match your search.</p>
          ) : (
            visibleZones.map((zone) => (
              <ZoneRowItem
                key={zone.id}
                brandId={brandId}
                geoId={geo.id}
                zone={zone}
                onEdit={() => onEditZone(zone)}
              />
            ))
          )}
        </div>
      )}
    </div>
  );
}

// ── Zone Row ──────────────────────────────────────────────────────────────────

function ZoneRowItem({
  brandId,
  geoId,
  zone,
  onEdit,
}: {
  brandId: string;
  geoId:   string;
  zone:    DeliveryZone;
  onEdit:  () => void;
}) {
  const deleteZone = useDeleteZone(brandId, geoId);
  const { toast }  = useToast();

  async function handleDelete() {
    if (!confirm(`Delete zone "${zone.name}"?`)) return;
    await deleteZone.mutateAsync(zone.id);
    toast({ title: 'Zone deleted', type: 'success' });
  }

  return (
    <div className="flex items-center gap-3 px-6 py-2.5 hover:bg-muted/20 transition-colors">
      <div className="flex-1 flex items-center gap-2 min-w-0">
        <span className="text-sm truncate">{zone.name}</span>
        {!zone.is_active && (
          <Badge className="text-[10px] py-0 h-4 bg-amber-50 text-amber-700 border-0 shrink-0">
            Inactive
          </Badge>
        )}
      </div>

      <div className="flex items-center gap-2 shrink-0">
        {zone.shipping_rule ? (
          <Badge className="text-[10px] py-0 h-5 bg-green-50 text-green-700 border-green-200 font-mono">
            {zone.shipping_rule.shipping_cost.toFixed(0)} EGP
          </Badge>
        ) : (
          <Badge className="text-[10px] py-0 h-5 bg-muted text-muted-foreground border-0">
            No cost set
          </Badge>
        )}

        {zone.shipping_rule?.delivery_window_id && (
          <Badge className="text-[10px] py-0 h-5 bg-blue-50 text-blue-700 border-blue-200">
            {zone.shipping_rule.delivery_window_id}
          </Badge>
        )}

        <button
          onClick={onEdit}
          className="text-muted-foreground hover:text-foreground transition-colors"
          title="Edit zone"
        >
          <Pencil className="h-3.5 w-3.5" />
        </button>
        <button
          onClick={handleDelete}
          disabled={deleteZone.isPending}
          className="text-muted-foreground hover:text-destructive transition-colors"
          title="Delete zone"
        >
          {deleteZone.isPending
            ? <Loader2 className="h-3 w-3 animate-spin" />
            : <Trash2 className="h-3.5 w-3.5" />
          }
        </button>
      </div>
    </div>
  );
}

// ── Shared primitives ─────────────────────────────────────────────────────────

function KpiCard({
  icon,
  label,
  value,
}: {
  icon:  React.ReactNode;
  label: string;
  value: number | string;
}) {
  return (
    <div className="rounded-lg border border-border/60 bg-card p-3 flex items-center gap-2.5">
      <span className="text-muted-foreground shrink-0">{icon}</span>
      <div className="min-w-0">
        <div className="text-base font-bold leading-none">{value}</div>
        <div className="text-[10px] text-muted-foreground mt-0.5 truncate">{label}</div>
      </div>
    </div>
  );
}

function LoadingBlock() {
  return (
    <div className="flex items-center justify-center py-16 gap-2 text-muted-foreground">
      <Loader2 className="h-4 w-4 animate-spin" />
      <span className="text-sm">Loading…</span>
    </div>
  );
}

function EmptyBlock({ message }: { message: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-12 rounded-lg border border-dashed border-border/60 gap-2">
      <MapPin className="h-8 w-8 text-muted-foreground/30" />
      <p className="text-sm text-muted-foreground text-center max-w-xs">{message}</p>
    </div>
  );
}
