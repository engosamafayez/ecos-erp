import { useEffect, useRef, useState } from 'react';
import {
  AlertTriangle,
  Archive,
  Download,
  FileText,
  Loader2,
  Lock,
  Paperclip,
  Pencil,
  ShieldAlert,
  Trash2,
  Truck,
  Upload,
  UserRound,
  Wrench,
} from 'lucide-react';

import { PageDrawer } from '@/components/page/drawer/page-drawer';
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
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/ecos-select';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/components/ds/use-toast';

import { useShippingCompanies } from '@/features/logistics/shipping-companies/hooks/use-shipping-companies';
import {
  useAmendMaintenance,
  useCanManageMaintenance,
  useCreateVehicle,
  useDeleteMaintenance,
  useDeleteVehicleDocument,
  useNextVehicleCode,
  useRecordMaintenance,
  useSetVehicleStatus,
  useUpdateVehicle,
  useUploadVehicleDocument,
  useVehicle,
  useVehicleOptions,
} from '../hooks/use-vehicles';
import { vehicleService } from '../services/vehicle-service';
import type {
  MaintenanceType,
  Vehicle,
  VehicleDocument,
  VehiclePayload,
  VehicleStatus,
  VehicleType,
} from '../types/vehicle';
import { VehicleStatusBadge } from './vehicle-status-badge';

// ── Helpers ────────────────────────────────────────────────────────────────────

function apiErrorMessage(err: unknown, fallback: string): string {
  if (typeof err === 'object' && err !== null) {
    const res = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
    const firstFieldError = res?.data?.errors ? Object.values(res.data.errors)[0]?.[0] : undefined;
    return firstFieldError ?? res?.data?.message ?? fallback;
  }
  return fallback;
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '—';
  return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatMoney(amount: number, currency: string): string {
  return `${currency} ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// ── Form state ─────────────────────────────────────────────────────────────────

type VehicleFormState = {
  vehicle_code: string;
  plate_number: string;
  name: string;
  type: string;
  shipping_company_id: string;
  capacity_orders: string;
  capacity_weight_kg: string;
  capacity_volume_m3: string;
  fuel_type: string;
  manufacturer: string;
  model: string;
  year: string;
  color: string;
  vin: string;
  notes: string;
};

const EMPTY_FORM: VehicleFormState = {
  vehicle_code: '',
  plate_number: '',
  name: '',
  type: 'van',
  shipping_company_id: '',
  capacity_orders: '',
  capacity_weight_kg: '',
  capacity_volume_m3: '',
  fuel_type: '',
  manufacturer: '',
  model: '',
  year: '',
  color: '',
  vin: '',
  notes: '',
};

function toPayload(form: VehicleFormState): VehiclePayload {
  const text = (v: string) => (v.trim() === '' ? null : v.trim());
  const num = (v: string) => (v.trim() === '' ? null : Number(v));

  return {
    vehicle_code: form.vehicle_code.trim(),
    plate_number: form.plate_number.trim(),
    name: text(form.name),
    type: form.type as VehicleType,
    shipping_company_id: form.shipping_company_id ? Number(form.shipping_company_id) : null,
    capacity_orders: Number(form.capacity_orders || 0),
    capacity_weight_kg: num(form.capacity_weight_kg),
    capacity_volume_m3: num(form.capacity_volume_m3),
    fuel_type: (text(form.fuel_type) as VehiclePayload['fuel_type']) ?? null,
    manufacturer: text(form.manufacturer),
    model: text(form.model),
    year: num(form.year),
    color: text(form.color),
    vin: text(form.vin),
    notes: text(form.notes),
  };
}

// ── Details tab ────────────────────────────────────────────────────────────────

function DetailsFields({
  form,
  setForm,
  disabled,
}: {
  form: VehicleFormState;
  setForm: (fn: (prev: VehicleFormState) => VehicleFormState) => void;
  disabled?: boolean;
}) {
  const { data: options } = useVehicleOptions();
  const { data: carriers } = useShippingCompanies({ status: 'active', per_page: 100 });
  const set = (k: keyof VehicleFormState) => (v: string) => setForm((p) => ({ ...p, [k]: v }));

  /** Picking a type pre-fills a sensible order capacity when the field is empty. */
  function onTypeChange(value: string) {
    const preset = options?.types.find((t) => t.value === value)?.default_capacity_orders;
    setForm((p) => ({
      ...p,
      type: value,
      capacity_orders: p.capacity_orders === '' && preset ? String(preset) : p.capacity_orders,
    }));
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="vh-code">Vehicle Code *</Label>
          <Input
            id="vh-code"
            value={form.vehicle_code}
            disabled={disabled}
            className="font-mono"
            placeholder="VEH-001"
            onChange={(e) => set('vehicle_code')(e.target.value.toUpperCase())}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="vh-plate">Plate Number *</Label>
          <Input
            id="vh-plate"
            value={form.plate_number}
            disabled={disabled}
            className="font-mono"
            onChange={(e) => set('plate_number')(e.target.value.toUpperCase())}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="vh-name">Vehicle Name</Label>
          <Input
            id="vh-name"
            value={form.name}
            disabled={disabled}
            placeholder="e.g. Cairo Van 1"
            onChange={(e) => set('name')(e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label>Vehicle Type *</Label>
          <Select value={form.type} onValueChange={onTypeChange}>
            <SelectTrigger disabled={disabled}>
              <SelectValue placeholder="Select type" />
            </SelectTrigger>
            <SelectContent>
              {(options?.types ?? []).map((t) => (
                <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="space-y-1.5">
        <Label>Shipping Company</Label>
        <Select value={form.shipping_company_id} onValueChange={set('shipping_company_id')}>
          <SelectTrigger disabled={disabled}>
            <SelectValue placeholder="Select the owning carrier" />
          </SelectTrigger>
          <SelectContent>
            {(carriers?.data ?? []).map((c) => (
              <SelectItem key={c.id} value={String(c.id)}>
                {c.name} ({c.code})
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <Separator />
      <p className="text-sm font-semibold">Capacity</p>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className="space-y-1.5">
          <Label htmlFor="vh-cap-o">Orders *</Label>
          <Input
            id="vh-cap-o"
            type="number"
            min={1}
            value={form.capacity_orders}
            disabled={disabled}
            onChange={(e) => set('capacity_orders')(e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="vh-cap-w">Weight (kg)</Label>
          <Input
            id="vh-cap-w"
            type="number"
            min={0}
            step="0.01"
            value={form.capacity_weight_kg}
            disabled={disabled}
            onChange={(e) => set('capacity_weight_kg')(e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="vh-cap-v">Volume (m³)</Label>
          <Input
            id="vh-cap-v"
            type="number"
            min={0}
            step="0.001"
            value={form.capacity_volume_m3}
            disabled={disabled}
            onChange={(e) => set('capacity_volume_m3')(e.target.value)}
          />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">Capacity values must be greater than zero.</p>

      <Separator />
      <p className="text-sm font-semibold">Specification</p>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label>Fuel Type</Label>
          <Select value={form.fuel_type} onValueChange={set('fuel_type')}>
            <SelectTrigger disabled={disabled}>
              <SelectValue placeholder="Select fuel type" />
            </SelectTrigger>
            <SelectContent>
              {(options?.fuel_types ?? []).map((f) => (
                <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="vh-manu">Manufacturer</Label>
          <Input
            id="vh-manu"
            value={form.manufacturer}
            disabled={disabled}
            onChange={(e) => set('manufacturer')(e.target.value)}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className="space-y-1.5">
          <Label htmlFor="vh-model">Model</Label>
          <Input id="vh-model" value={form.model} disabled={disabled} onChange={(e) => set('model')(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="vh-year">Year</Label>
          <Input
            id="vh-year"
            type="number"
            min={1950}
            max={2100}
            value={form.year}
            disabled={disabled}
            onChange={(e) => set('year')(e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="vh-color">Color</Label>
          <Input id="vh-color" value={form.color} disabled={disabled} onChange={(e) => set('color')(e.target.value)} />
        </div>
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="vh-vin">VIN</Label>
        <Input
          id="vh-vin"
          value={form.vin}
          disabled={disabled}
          className="font-mono"
          placeholder="Optional"
          onChange={(e) => set('vin')(e.target.value.toUpperCase())}
        />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="vh-notes">Notes</Label>
        <Textarea id="vh-notes" rows={3} value={form.notes} disabled={disabled} onChange={(e) => set('notes')(e.target.value)} />
      </div>
    </div>
  );
}

// ── Maintenance tab ────────────────────────────────────────────────────────────

type MaintenanceFormState = {
  performed_on: string;
  type: string;
  description: string;
  cost: string;
  vendor: string;
  next_maintenance_date: string;
  notes: string;
};

const EMPTY_MAINTENANCE: MaintenanceFormState = {
  performed_on: '',
  type: 'routine',
  description: '',
  cost: '',
  vendor: '',
  next_maintenance_date: '',
  notes: '',
};

function MaintenanceTab({ vehicle }: { vehicle: Vehicle }) {
  const { toast } = useToast();
  const { data: options } = useVehicleOptions();
  const { data: canManage = false } = useCanManageMaintenance();
  const record = useRecordMaintenance();
  const amend = useAmendMaintenance();
  const remove = useDeleteMaintenance();

  const [formOpen, setFormOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<MaintenanceFormState>(EMPTY_MAINTENANCE);
  const [deleteTarget, setDeleteTarget] = useState<number | null>(null);

  const records = vehicle.maintenance_records ?? [];
  const saving = record.isPending || amend.isPending;
  const set = (k: keyof MaintenanceFormState) => (v: string) => setForm((p) => ({ ...p, [k]: v }));

  function openCreate() {
    setEditingId(null);
    setForm({ ...EMPTY_MAINTENANCE, performed_on: new Date().toISOString().slice(0, 10) });
    setFormOpen(true);
  }

  function openEdit(r: (typeof records)[number]) {
    setEditingId(r.id);
    setForm({
      performed_on: r.performed_on ?? '',
      type: r.type,
      description: r.description ?? '',
      cost: String(r.cost ?? ''),
      vendor: r.vendor ?? '',
      next_maintenance_date: r.next_maintenance_date ?? '',
      notes: r.notes ?? '',
    });
    setFormOpen(true);
  }

  async function handleSave() {
    if (!form.performed_on) {
      toast({ title: 'Service date is required.', variant: 'destructive' });
      return;
    }
    const payload = {
      performed_on: form.performed_on,
      type: form.type as MaintenanceType,
      description: form.description.trim() || null,
      cost: form.cost.trim() === '' ? null : Number(form.cost),
      vendor: form.vendor.trim() || null,
      next_maintenance_date: form.next_maintenance_date || null,
      notes: form.notes.trim() || null,
    };
    try {
      if (editingId !== null) {
        await amend.mutateAsync({ vehicleId: vehicle.id, recordId: editingId, payload });
        toast({ title: 'Maintenance record amended.' });
      } else {
        await record.mutateAsync({ vehicleId: vehicle.id, payload });
        toast({ title: 'Maintenance recorded.' });
      }
      setFormOpen(false);
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Saving the record failed.'), variant: 'destructive' });
    }
  }

  async function handleDelete() {
    if (deleteTarget === null) return;
    try {
      await remove.mutateAsync({ vehicleId: vehicle.id, recordId: deleteTarget });
      toast({ title: 'Maintenance record deleted.' });
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Delete failed.'), variant: 'destructive' });
    } finally {
      setDeleteTarget(null);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          {records.length} record{records.length !== 1 ? 's' : ''}, newest first.
          {!canManage && ' Records are immutable once saved.'}
        </p>
        {!formOpen && (
          <Button size="sm" className="shrink-0 gap-1.5" onClick={openCreate}>
            <Wrench className="size-3.5" />
            Record Service
          </Button>
        )}
      </div>

      {!canManage && (
        <Alert>
          <Lock className="size-4" />
          <AlertDescription className="text-sm">
            Maintenance records cannot be edited or removed without the maintenance-management
            permission. New records can still be added.
          </AlertDescription>
        </Alert>
      )}

      {formOpen && (
        <div className="space-y-3 rounded-lg border bg-muted/30 p-4">
          <p className="text-sm font-semibold">{editingId !== null ? 'Amend Record' : 'New Maintenance Record'}</p>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="mt-date">Date *</Label>
              <Input
                id="mt-date"
                type="date"
                max={new Date().toISOString().slice(0, 10)}
                value={form.performed_on}
                onChange={(e) => set('performed_on')(e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label>Type *</Label>
              <Select value={form.type} onValueChange={set('type')}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {(options?.maintenance_types ?? []).map((t) => (
                    <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="mt-desc">Description</Label>
            <Textarea id="mt-desc" rows={2} value={form.description} onChange={(e) => set('description')(e.target.value)} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="mt-cost">Cost</Label>
              <Input id="mt-cost" type="number" min={0} step="0.01" value={form.cost} onChange={(e) => set('cost')(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="mt-vendor">Vendor</Label>
              <Input id="mt-vendor" value={form.vendor} onChange={(e) => set('vendor')(e.target.value)} />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="mt-next">Next Maintenance Date</Label>
            <Input
              id="mt-next"
              type="date"
              min={form.performed_on || undefined}
              value={form.next_maintenance_date}
              onChange={(e) => set('next_maintenance_date')(e.target.value)}
            />
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" size="sm" onClick={() => setFormOpen(false)} disabled={saving}>Cancel</Button>
            <Button size="sm" className="gap-1.5" onClick={handleSave} disabled={saving}>
              {saving && <Loader2 className="size-3.5 animate-spin" />}
              {editingId !== null ? 'Save Amendment' : 'Record'}
            </Button>
          </div>
        </div>
      )}

      {records.length === 0 && !formOpen ? (
        <div className="flex flex-col items-center justify-center rounded-lg border py-12 text-center">
          <Wrench className="mb-2 size-8 text-muted-foreground/30" />
          <p className="text-sm font-medium">No maintenance history</p>
          <p className="mt-0.5 text-xs text-muted-foreground">Log services, repairs and inspections here.</p>
        </div>
      ) : (
        <div className="space-y-2">
          {records.map((r) => (
            <div key={r.id} className="rounded-lg border p-3">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="outline" className="text-xs">{r.type_label}</Badge>
                    <span className="text-sm font-medium">{formatDate(r.performed_on)}</span>
                    {r.cost > 0 && (
                      <span className="text-sm tabular-nums text-muted-foreground">
                        {formatMoney(r.cost, r.currency)}
                      </span>
                    )}
                    {r.is_next_service_due && (
                      <Badge variant="destructive" className="text-xs">Service due</Badge>
                    )}
                    {r.was_amended && <Badge variant="secondary" className="text-xs">Amended</Badge>}
                  </div>
                  {r.description && <p className="mt-1 text-xs text-muted-foreground">{r.description}</p>}
                  <p className="mt-1 text-xs text-muted-foreground">
                    {r.vendor ? `${r.vendor}` : 'No vendor'}
                    {r.next_maintenance_date ? ` · next ${formatDate(r.next_maintenance_date)}` : ''}
                    {r.recorded_by ? ` · by ${r.recorded_by}` : ''}
                  </p>
                  {r.was_amended && (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                      Amended by {r.amended_by ?? 'unknown'} on {formatDate(r.amended_at)}
                    </p>
                  )}
                </div>
                {canManage && (
                  <div className="flex shrink-0 items-center gap-1">
                    <Button variant="ghost" size="sm" className="h-7 w-7 p-0" onClick={() => openEdit(r)}>
                      <Pencil className="size-3.5" />
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-7 w-7 p-0 text-destructive hover:text-destructive"
                      onClick={() => setDeleteTarget(r.id)}
                    >
                      <Trash2 className="size-3.5" />
                    </Button>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      )}

      <AlertDialog open={deleteTarget !== null} onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Maintenance Record</AlertDialogTitle>
            <AlertDialogDescription>
              This permanently removes a maintenance record from the vehicle history. This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// ── Documents tab ──────────────────────────────────────────────────────────────

function DocumentsTab({ vehicle }: { vehicle: Vehicle }) {
  const { toast } = useToast();
  const { data: options } = useVehicleOptions();
  const upload = useUploadVehicleDocument();
  const remove = useDeleteVehicleDocument();
  const fileRef = useRef<HTMLInputElement>(null);

  const [type, setType] = useState<string>('license');
  const [reference, setReference] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<VehicleDocument | null>(null);

  const documents = vehicle.documents ?? [];
  const isArchived = vehicle.status === 'archived';

  async function handleUpload() {
    if (!file) {
      toast({ title: 'Choose a file to upload.', variant: 'destructive' });
      return;
    }
    const form = new FormData();
    form.append('file', file);
    form.append('type', type);
    if (reference.trim()) form.append('reference_number', reference.trim());
    if (expiresAt) form.append('expires_at', expiresAt);

    try {
      await upload.mutateAsync({ vehicleId: vehicle.id, form });
      toast({ title: 'Document uploaded.' });
      setFile(null);
      setReference('');
      setExpiresAt('');
      if (fileRef.current) fileRef.current.value = '';
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Upload failed.'), variant: 'destructive' });
    }
  }

  async function handleDownload(doc: VehicleDocument) {
    try {
      await vehicleService.downloadDocument(vehicle.id, doc);
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Download failed.'), variant: 'destructive' });
    }
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    try {
      await remove.mutateAsync({ vehicleId: vehicle.id, documentId: deleteTarget.id });
      toast({ title: 'Document deleted.' });
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Delete failed.'), variant: 'destructive' });
    } finally {
      setDeleteTarget(null);
    }
  }

  return (
    <div className="space-y-4">
      {vehicle.can_be_dispatched === false && (vehicle.blocking_expired_documents?.length ?? 0) > 0 && (
        <Alert className="border-destructive/40 bg-destructive/5">
          <ShieldAlert className="size-4" />
          <AlertDescription className="text-sm">
            This vehicle cannot be dispatched — its{' '}
            {vehicle.blocking_expired_documents!.map((d) => d.type.replace('_', ' ')).join(' and ')} has expired.
            Upload a current document to restore availability.
          </AlertDescription>
        </Alert>
      )}

      {isArchived ? (
        <Alert>
          <AlertDescription className="text-sm">
            This vehicle is archived — new documents cannot be uploaded.
          </AlertDescription>
        </Alert>
      ) : (
        <div className="space-y-3 rounded-lg border bg-muted/30 p-4">
          <p className="text-sm font-semibold">Upload a document</p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label>Type *</Label>
              <Select value={type} onValueChange={setType}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {(options?.document_types ?? []).map((t) => (
                    <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="vd-exp">Expires On</Label>
              <Input id="vd-exp" type="date" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="vd-ref">Reference Number</Label>
            <Input id="vd-ref" value={reference} onChange={(e) => setReference(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="vd-file">
              File * <span className="font-normal text-muted-foreground">(PDF or image, max 10 MB)</span>
            </Label>
            <Input
              id="vd-file"
              ref={fileRef}
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
          </div>
          <div className="flex justify-end">
            <Button size="sm" className="gap-1.5" onClick={handleUpload} disabled={upload.isPending || !file}>
              {upload.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <Upload className="size-3.5" />}
              Upload
            </Button>
          </div>
        </div>
      )}

      {documents.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-lg border py-12 text-center">
          <Paperclip className="mb-2 size-8 text-muted-foreground/30" />
          <p className="text-sm font-medium">No documents yet</p>
          <p className="mt-0.5 text-xs text-muted-foreground">Attach the licence, insurance and inspection.</p>
        </div>
      ) : (
        <div className="space-y-2">
          {documents.map((doc) => (
            <div key={doc.id} className="flex items-start justify-between gap-3 rounded-lg border p-3">
              <div className="flex min-w-0 items-start gap-2.5">
                <FileText className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-medium">{doc.title || doc.file_name}</p>
                    <Badge variant="outline" className="text-xs">{doc.type_label}</Badge>
                    {doc.is_expired && (
                      <Badge variant="destructive" className="gap-1 text-xs">
                        <AlertTriangle className="size-3" />
                        Expired
                      </Badge>
                    )}
                    {doc.is_expiring_soon && (
                      <Badge className="bg-amber-500 text-xs hover:bg-amber-500">
                        Expires in {doc.days_until_expiry}d
                      </Badge>
                    )}
                  </div>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {doc.file_name} · {formatBytes(doc.size_bytes)}
                    {doc.reference_number ? ` · ref ${doc.reference_number}` : ''}
                    {doc.expires_at ? ` · expires ${formatDate(doc.expires_at)}` : ''}
                  </p>
                </div>
              </div>
              <div className="flex shrink-0 items-center gap-1">
                <Button variant="ghost" size="sm" className="h-7 w-7 p-0" onClick={() => handleDownload(doc)}>
                  <Download className="size-3.5" />
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 w-7 p-0 text-destructive hover:text-destructive"
                  onClick={() => setDeleteTarget(doc)}
                >
                  <Trash2 className="size-3.5" />
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}

      <AlertDialog open={deleteTarget !== null} onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Document</AlertDialogTitle>
            <AlertDialogDescription>
              Delete <strong>{deleteTarget?.title || deleteTarget?.file_name}</strong>? The stored file is
              removed permanently.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// ── Assignment tab ─────────────────────────────────────────────────────────────

function AssignmentTab({ vehicle }: { vehicle: Vehicle }) {
  const driver = vehicle.current_driver ?? null;

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        A vehicle carries one driver at a time. Assignments are made from the driver's record, so the
        pairing has a single source of truth.
      </p>

      {driver ? (
        <div className="rounded-lg border border-blue-500/40 bg-blue-500/5 p-4">
          <div className="flex items-start gap-2.5">
            <UserRound className="mt-0.5 size-4 shrink-0 text-blue-600" />
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium">{driver.full_name}</p>
                <Badge variant="outline" className="font-mono text-xs">{driver.driver_code}</Badge>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">Assigned {formatDate(driver.assigned_at)}</p>
            </div>
          </div>
        </div>
      ) : (
        <div className="flex flex-col items-center justify-center rounded-lg border py-10 text-center">
          <UserRound className="mb-2 size-8 text-muted-foreground/30" />
          <p className="text-sm font-medium">No driver assigned</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Assign this vehicle from the Drivers workspace.
          </p>
        </div>
      )}

      <Separator />

      <div className="space-y-2">
        <p className="text-sm font-semibold">Dispatch readiness</p>
        <div className="flex items-center gap-2">
          {vehicle.can_be_dispatched ? (
            <Badge className="bg-emerald-600 text-xs hover:bg-emerald-600">Ready for dispatch</Badge>
          ) : (
            <Badge variant="destructive" className="gap-1 text-xs">
              <ShieldAlert className="size-3" />
              Not dispatchable
            </Badge>
          )}
          <span className="text-xs text-muted-foreground">
            Status: {vehicle.status_label}
            {vehicle.next_document_expiry ? ` · next document expiry ${formatDate(vehicle.next_document_expiry)}` : ''}
          </span>
        </div>
        {(vehicle.blocking_expired_documents?.length ?? 0) > 0 && (
          <p className="text-xs text-destructive">
            Blocked by expired{' '}
            {vehicle.blocking_expired_documents!.map((d) => d.type.replace('_', ' ')).join(' and ')}.
          </p>
        )}
      </div>
    </div>
  );
}

// ── Main drawer ────────────────────────────────────────────────────────────────

export function VehicleDrawer({
  open,
  onOpenChange,
  editVehicle,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editVehicle: Vehicle | null;
}) {
  const { toast } = useToast();
  const isCreate = editVehicle === null;

  const [form, setForm] = useState<VehicleFormState>(EMPTY_FORM);
  const [tab, setTab] = useState('details');
  const [statusTarget, setStatusTarget] = useState<VehicleStatus | null>(null);

  const { data: detail, isLoading } = useVehicle(open && editVehicle ? editVehicle.id : null);
  const { data: nextCode } = useNextVehicleCode(open && isCreate);
  const { data: options } = useVehicleOptions();

  const createVehicle = useCreateVehicle();
  const updateVehicle = useUpdateVehicle();
  const setStatus = useSetVehicleStatus();

  const vehicle = detail ?? editVehicle;
  const saving = createVehicle.isPending || updateVehicle.isPending;
  const readOnly = vehicle?.status === 'archived';

  useEffect(() => {
    if (!open) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setTab('details');
    if (editVehicle === null) {
      setForm(EMPTY_FORM);
      return;
    }
    const s = detail ?? editVehicle;
    setForm({
      vehicle_code: s.vehicle_code,
      plate_number: s.plate_number,
      name: s.name ?? '',
      type: s.type,
      shipping_company_id: s.shipping_company_id ? String(s.shipping_company_id) : '',
      capacity_orders: String(s.capacity_orders ?? ''),
      capacity_weight_kg: s.capacity_weight_kg != null ? String(s.capacity_weight_kg) : '',
      capacity_volume_m3: s.capacity_volume_m3 != null ? String(s.capacity_volume_m3) : '',
      fuel_type: s.fuel_type ?? '',
      manufacturer: s.manufacturer ?? '',
      model: s.model ?? '',
      year: s.year != null ? String(s.year) : '',
      color: s.color ?? '',
      vin: s.vin ?? '',
      notes: s.notes ?? '',
    });
  }, [open, editVehicle, detail]);

  useEffect(() => {
    if (open && isCreate && nextCode) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setForm((p) => (p.vehicle_code === '' ? { ...p, vehicle_code: nextCode } : p));
    }
  }, [open, isCreate, nextCode]);

  async function handleSave() {
    if (!form.vehicle_code.trim() || !form.plate_number.trim() || !form.capacity_orders) {
      toast({ title: 'Code, plate number and order capacity are required.', variant: 'destructive' });
      return;
    }
    try {
      if (isCreate) {
        await createVehicle.mutateAsync(toPayload(form));
        toast({ title: `Vehicle "${form.plate_number}" created.` });
        onOpenChange(false);
      } else {
        await updateVehicle.mutateAsync({ id: editVehicle.id, payload: toPayload(form) });
        toast({ title: 'Changes saved.' });
      }
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Saving failed.'), variant: 'destructive' });
    }
  }

  async function handleStatusChange(status: VehicleStatus) {
    if (!editVehicle) return;
    try {
      await setStatus.mutateAsync({ id: editVehicle.id, status });
      toast({ title: `Vehicle marked ${status.replace(/_/g, ' ')}.` });
      setStatusTarget(null);
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Status change failed.'), variant: 'destructive' });
      setStatusTarget(null);
    }
  }

  /** Only transitions the server would accept are offered. */
  const settable = new Set((options?.operator_settable_statuses ?? []).map((s) => s.value));
  const nextStates = (vehicle?.allowed_transitions ?? []).filter((t) => settable.has(t.value));

  return (
    <>
      <PageDrawer
        open={open}
        onOpenChange={onOpenChange}
        title={isCreate ? 'New Vehicle' : (vehicle?.label ?? 'Vehicle')}
        description={
          isCreate
            ? 'Register a vehicle in the enterprise fleet.'
            : `${vehicle?.vehicle_code ?? ''} — details, maintenance, documents and assignment.`
        }
        size="xl"
      >
        <div className="flex h-full flex-col">
          {!isCreate && vehicle && (
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <VehicleStatusBadge status={vehicle.status} />
              <Badge variant="outline" className="gap-1 text-xs">
                <Truck className="size-3" />
                {vehicle.type_label}
              </Badge>
              {vehicle.current_driver && (
                <Badge variant="outline" className="gap-1 text-xs">
                  <UserRound className="size-3" />
                  {vehicle.current_driver.full_name}
                </Badge>
              )}
              {vehicle.can_be_dispatched === false && (
                <Badge variant="destructive" className="gap-1 text-xs">
                  <ShieldAlert className="size-3" />
                  Not dispatchable
                </Badge>
              )}
            </div>
          )}

          {isCreate ? (
            <>
              <div className="min-h-0 flex-1 overflow-y-auto pr-1">
                <DetailsFields form={form} setForm={setForm} disabled={saving} />
              </div>
              <Separator className="my-4" />
              <div className="flex shrink-0 justify-end gap-2">
                <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={saving}>Cancel</Button>
                <Button onClick={handleSave} disabled={saving} className="gap-1.5">
                  {saving && <Loader2 className="size-4 animate-spin" />}
                  Create Vehicle
                </Button>
              </div>
            </>
          ) : isLoading && !vehicle ? (
            <div className="space-y-3">
              <Skeleton className="h-8 w-full" />
              <Skeleton className="h-32 w-full" />
              <Skeleton className="h-32 w-full" />
            </div>
          ) : (
            vehicle && (
              <Tabs value={tab} onValueChange={setTab} className="flex min-h-0 flex-1 flex-col">
                <TabsList className="grid w-full shrink-0 grid-cols-4">
                  <TabsTrigger value="details">Details</TabsTrigger>
                  <TabsTrigger value="maintenance" className="gap-1.5">
                    Service
                    {vehicle.maintenance_records_count != null && vehicle.maintenance_records_count > 0 && (
                      <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">
                        {vehicle.maintenance_records_count}
                      </Badge>
                    )}
                  </TabsTrigger>
                  <TabsTrigger value="documents" className="gap-1.5">
                    Docs
                    {vehicle.documents_count != null && vehicle.documents_count > 0 && (
                      <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">
                        {vehicle.documents_count}
                      </Badge>
                    )}
                  </TabsTrigger>
                  <TabsTrigger value="assignment">Driver</TabsTrigger>
                </TabsList>

                <div className="min-h-0 flex-1 overflow-y-auto pr-1 pt-4">
                  <TabsContent value="details" className="mt-0 space-y-4">
                    <DetailsFields form={form} setForm={setForm} disabled={saving || readOnly} />
                    <Separator />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="flex flex-wrap items-center gap-2">
                        {nextStates.map((t) => (
                          <Button
                            key={t.value}
                            variant="outline"
                            size="sm"
                            className={`gap-1.5 ${t.value === 'archived' ? 'text-destructive hover:text-destructive' : ''}`}
                            onClick={() => setStatusTarget(t.value as VehicleStatus)}
                            disabled={setStatus.isPending}
                          >
                            {t.value === 'archived' && <Archive className="size-3.5" />}
                            {t.value === 'maintenance' && <Wrench className="size-3.5" />}
                            Mark {t.label}
                          </Button>
                        ))}
                      </div>
                      {!readOnly && (
                        <Button onClick={handleSave} disabled={saving} className="gap-1.5">
                          {saving && <Loader2 className="size-4 animate-spin" />}
                          Save Changes
                        </Button>
                      )}
                    </div>
                  </TabsContent>

                  <TabsContent value="maintenance" className="mt-0">
                    <MaintenanceTab vehicle={vehicle} />
                  </TabsContent>

                  <TabsContent value="documents" className="mt-0">
                    <DocumentsTab vehicle={vehicle} />
                  </TabsContent>

                  <TabsContent value="assignment" className="mt-0">
                    <AssignmentTab vehicle={vehicle} />
                  </TabsContent>
                </div>
              </Tabs>
            )
          )}
        </div>
      </PageDrawer>

      <AlertDialog open={statusTarget !== null} onOpenChange={(o) => { if (!o) setStatusTarget(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Change Vehicle Status</AlertDialogTitle>
            <AlertDialogDescription>
              Move <strong>{vehicle?.plate_number}</strong> to{' '}
              <strong>{statusTarget?.replace(/_/g, ' ')}</strong>?
              {statusTarget === 'archived' && ' Archived vehicles accept no assignments or documents.'}
              {statusTarget === 'out_of_service' && ' It will need to pass through Maintenance before returning to service.'}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => statusTarget && handleStatusChange(statusTarget)}
              className={statusTarget === 'archived' ? 'bg-destructive text-destructive-foreground hover:bg-destructive/90' : ''}
            >
              Confirm
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
