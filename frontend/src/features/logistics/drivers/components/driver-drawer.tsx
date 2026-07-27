import { useEffect, useRef, useState } from 'react';
import {
  AlertTriangle,
  Archive,
  ArchiveRestore,
  CheckCircle,
  Clock,
  Download,
  FileText,
  History,
  Loader2,
  Paperclip,
  Trash2,
  Truck,
  Upload,
  XCircle,
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
  useAssignVehicle,
  useCreateDriver,
  useDeleteDriverDocument,
  useDriver,
  useNextDriverCode,
  useReleaseVehicle,
  useSetDriverStatus,
  useUpdateDriver,
  useUploadDriverDocument,
  useVehicles,
} from '../hooks/use-drivers';
import { driverService } from '../services/driver-service';
import type {
  Driver,
  DriverDocument,
  DriverDocumentType,
  DriverPayload,
} from '../types/driver';
import { LicenseStatusBadge } from './license-status-badge';

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
  return new Date(value).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

const DOCUMENT_TYPES: { value: DriverDocumentType; label: string }[] = [
  { value: 'license', label: 'Driver Licence' },
  { value: 'national_id', label: 'National ID' },
  { value: 'employment_contract', label: 'Employment Contract' },
  { value: 'medical_certificate', label: 'Medical Certificate' },
  { value: 'other', label: 'Other Document' },
];

const DOCUMENT_TYPE_LABEL = Object.fromEntries(
  DOCUMENT_TYPES.map((t) => [t.value, t.label]),
) as Record<DriverDocumentType, string>;

// ── Form state ─────────────────────────────────────────────────────────────────

type DriverFormState = {
  driver_code: string;
  full_name: string;
  mobile: string;
  national_id: string;
  date_of_birth: string;
  address: string;
  employment_date: string;
  shipping_company_id: string;
  license_number: string;
  license_type: string;
  license_issue_date: string;
  license_expiry_date: string;
  license_issuing_authority: string;
  notes: string;
};

const EMPTY_FORM: DriverFormState = {
  driver_code: '',
  full_name: '',
  mobile: '',
  national_id: '',
  date_of_birth: '',
  address: '',
  employment_date: '',
  shipping_company_id: '',
  license_number: '',
  license_type: '',
  license_issue_date: '',
  license_expiry_date: '',
  license_issuing_authority: '',
  notes: '',
};

function toPayload(form: DriverFormState): DriverPayload {
  const blankToNull = (v: string) => (v.trim() === '' ? null : v.trim());
  return {
    driver_code: form.driver_code.trim(),
    full_name: form.full_name.trim(),
    mobile: form.mobile.trim(),
    national_id: form.national_id.trim(),
    date_of_birth: blankToNull(form.date_of_birth),
    address: blankToNull(form.address),
    employment_date: blankToNull(form.employment_date),
    shipping_company_id: form.shipping_company_id ? Number(form.shipping_company_id) : null,
    license_number: blankToNull(form.license_number),
    license_type: blankToNull(form.license_type),
    license_issue_date: blankToNull(form.license_issue_date),
    license_expiry_date: blankToNull(form.license_expiry_date),
    license_issuing_authority: blankToNull(form.license_issuing_authority),
    notes: blankToNull(form.notes),
  };
}

// ── Details tab ────────────────────────────────────────────────────────────────

function DetailsFields({
  form,
  setForm,
  disabled,
}: {
  form: DriverFormState;
  setForm: (fn: (prev: DriverFormState) => DriverFormState) => void;
  disabled?: boolean;
}) {
  const { data: carriers } = useShippingCompanies({ status: 'active', per_page: 100 });
  const set = (k: keyof DriverFormState) => (v: string) => setForm((p) => ({ ...p, [k]: v }));

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="dv-code">Driver Code *</Label>
          <Input
            id="dv-code"
            value={form.driver_code}
            disabled={disabled}
            className="font-mono"
            placeholder="DRV-001"
            onChange={(e) => set('driver_code')(e.target.value.toUpperCase())}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dv-name">Full Name *</Label>
          <Input
            id="dv-name"
            value={form.full_name}
            disabled={disabled}
            onChange={(e) => set('full_name')(e.target.value)}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="dv-mobile">Mobile Number *</Label>
          <Input
            id="dv-mobile"
            value={form.mobile}
            disabled={disabled}
            placeholder="01xxxxxxxxx"
            onChange={(e) => set('mobile')(e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dv-nid">National ID *</Label>
          <Input
            id="dv-nid"
            value={form.national_id}
            disabled={disabled}
            className="font-mono"
            onChange={(e) => set('national_id')(e.target.value)}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="dv-dob">Date of Birth</Label>
          <Input
            id="dv-dob"
            type="date"
            value={form.date_of_birth}
            disabled={disabled}
            onChange={(e) => set('date_of_birth')(e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="dv-emp">Employment Date</Label>
          <Input
            id="dv-emp"
            type="date"
            value={form.employment_date}
            disabled={disabled}
            onChange={(e) => set('employment_date')(e.target.value)}
          />
        </div>
      </div>

      <div className="space-y-1.5">
        <Label>Shipping Company</Label>
        <Select
          value={form.shipping_company_id}
          onValueChange={(v) => set('shipping_company_id')(v)}
        >
          <SelectTrigger disabled={disabled}>
            <SelectValue placeholder="Select the employing carrier" />
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

      <div className="space-y-1.5">
        <Label htmlFor="dv-address">Address</Label>
        <Input
          id="dv-address"
          value={form.address}
          disabled={disabled}
          onChange={(e) => set('address')(e.target.value)}
        />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="dv-notes">Notes</Label>
        <Textarea
          id="dv-notes"
          rows={3}
          value={form.notes}
          disabled={disabled}
          onChange={(e) => set('notes')(e.target.value)}
        />
      </div>
    </div>
  );
}

// ── Licence tab ────────────────────────────────────────────────────────────────

function LicenseFields({
  form,
  setForm,
  driver,
  disabled,
}: {
  form: DriverFormState;
  setForm: (fn: (prev: DriverFormState) => DriverFormState) => void;
  driver: Driver | null;
  disabled?: boolean;
}) {
  const set = (k: keyof DriverFormState) => (v: string) => setForm((p) => ({ ...p, [k]: v }));
  const status = driver?.license_status;

  return (
    <div className="space-y-4">
      {driver && status && status !== 'valid' && (
        <Alert
          className={
            status === 'expired'
              ? 'border-destructive/40 bg-destructive/5'
              : status === 'expiring_soon'
                ? 'border-amber-500/40 bg-amber-500/5'
                : undefined
          }
        >
          <AlertTriangle className="size-4" />
          <AlertDescription className="text-sm">
            {status === 'expired' && (
              <>
                This licence <strong>expired</strong> on {formatDate(driver.license_expiry_date)}. The
                driver cannot start deliveries until it is renewed.
              </>
            )}
            {status === 'expiring_soon' && (
              <>
                This licence expires on <strong>{formatDate(driver.license_expiry_date)}</strong>
                {driver.license_days_remaining != null && (
                  <> — {driver.license_days_remaining === 0 ? 'today' : `in ${driver.license_days_remaining} days`}</>
                )}
                . Renew it before it lapses.
              </>
            )}
            {status === 'missing' && <>No licence details recorded. Add them to clear this driver for deliveries.</>}
          </AlertDescription>
        </Alert>
      )}

      {driver && (
        <div className="flex items-center gap-2">
          <span className="text-sm text-muted-foreground">Current status</span>
          <LicenseStatusBadge
            status={driver.license_status}
            daysRemaining={driver.license_days_remaining}
            showCountdown
          />
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="lic-no">Licence Number</Label>
          <Input
            id="lic-no"
            value={form.license_number}
            disabled={disabled}
            className="font-mono"
            onChange={(e) => set('license_number')(e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="lic-type">Licence Type</Label>
          <Input
            id="lic-type"
            value={form.license_type}
            disabled={disabled}
            placeholder="Private / Commercial"
            onChange={(e) => set('license_type')(e.target.value)}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="lic-issue">Issue Date</Label>
          <Input
            id="lic-issue"
            type="date"
            value={form.license_issue_date}
            disabled={disabled}
            onChange={(e) => set('license_issue_date')(e.target.value)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="lic-exp">Expiry Date</Label>
          <Input
            id="lic-exp"
            type="date"
            value={form.license_expiry_date}
            min={form.license_issue_date || undefined}
            disabled={disabled}
            onChange={(e) => set('license_expiry_date')(e.target.value)}
          />
        </div>
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="lic-auth">Issuing Authority</Label>
        <Input
          id="lic-auth"
          value={form.license_issuing_authority}
          disabled={disabled}
          placeholder="e.g. Cairo Traffic Department"
          onChange={(e) => set('license_issuing_authority')(e.target.value)}
        />
      </div>
    </div>
  );
}

// ── Documents tab ──────────────────────────────────────────────────────────────

function DocumentsTab({ driver }: { driver: Driver }) {
  const { toast } = useToast();
  const upload = useUploadDriverDocument();
  const remove = useDeleteDriverDocument();
  const fileRef = useRef<HTMLInputElement>(null);

  const [type, setType] = useState<DriverDocumentType>('license');
  const [title, setTitle] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<DriverDocument | null>(null);

  const documents = driver.documents ?? [];
  const isArchived = driver.status === 'archived';

  async function handleUpload() {
    if (!file) {
      toast({ title: 'Choose a file to upload.', variant: 'destructive' });
      return;
    }
    const form = new FormData();
    form.append('file', file);
    form.append('type', type);
    if (title.trim()) form.append('title', title.trim());
    if (expiresAt) form.append('expires_at', expiresAt);

    try {
      await upload.mutateAsync({ driverId: driver.id, form });
      toast({ title: 'Document uploaded.' });
      setFile(null);
      setTitle('');
      setExpiresAt('');
      if (fileRef.current) fileRef.current.value = '';
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Upload failed.'), variant: 'destructive' });
    }
  }

  async function handleDownload(doc: DriverDocument) {
    try {
      await driverService.downloadDocument(driver.id, doc);
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Download failed.'), variant: 'destructive' });
    }
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    try {
      await remove.mutateAsync({ driverId: driver.id, documentId: deleteTarget.id });
      toast({ title: 'Document deleted.' });
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Delete failed.'), variant: 'destructive' });
    } finally {
      setDeleteTarget(null);
    }
  }

  return (
    <div className="space-y-4">
      {isArchived ? (
        <Alert>
          <AlertDescription className="text-sm">
            This driver is archived — new documents cannot be uploaded.
          </AlertDescription>
        </Alert>
      ) : (
        <div className="space-y-3 rounded-lg border bg-muted/30 p-4">
          <p className="text-sm font-semibold">Upload a document</p>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label>Type *</Label>
              <Select value={type} onValueChange={(v) => setType(v as DriverDocumentType)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {DOCUMENT_TYPES.map((t) => (
                    <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="doc-exp">Expires On</Label>
              <Input
                id="doc-exp"
                type="date"
                value={expiresAt}
                onChange={(e) => setExpiresAt(e.target.value)}
              />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="doc-title">Title</Label>
            <Input
              id="doc-title"
              value={title}
              placeholder="Optional label, e.g. Licence renewal 2026"
              onChange={(e) => setTitle(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="doc-file">File * <span className="font-normal text-muted-foreground">(PDF or image, max 10 MB)</span></Label>
            <Input
              id="doc-file"
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
          <p className="mt-0.5 text-xs text-muted-foreground">
            Attach the licence, national ID, contract or medical certificate.
          </p>
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
                    <Badge variant="outline" className="text-xs">{DOCUMENT_TYPE_LABEL[doc.type]}</Badge>
                    {doc.is_expired && <Badge variant="destructive" className="text-xs">Expired</Badge>}
                  </div>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {doc.file_name} · {formatBytes(doc.size_bytes)}
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
              Delete <strong>{deleteTarget?.title || deleteTarget?.file_name}</strong>? The stored file
              is removed permanently.
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

// ── Vehicle tab ────────────────────────────────────────────────────────────────

function VehicleTab({ driver }: { driver: Driver }) {
  const { toast } = useToast();
  const assign = useAssignVehicle();
  const release = useReleaseVehicle();

  const [vehicleId, setVehicleId] = useState('');
  const [releaseOpen, setReleaseOpen] = useState(false);

  const isArchived = driver.status === 'archived';
  const current = driver.current_vehicle ?? null;

  // Only vehicles free to take are offered, so BR-7 can't be tripped from the UI.
  const { data: vehicles, isLoading } = useVehicles({ available: 1, per_page: 100 }, !isArchived);
  const options = vehicles?.data ?? [];

  async function handleAssign() {
    if (!vehicleId) return;
    try {
      await assign.mutateAsync({ driverId: driver.id, vehicleId: Number(vehicleId) });
      toast({ title: current ? 'Vehicle changed.' : 'Vehicle assigned.' });
      setVehicleId('');
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Assignment failed.'), variant: 'destructive' });
    }
  }

  async function handleRelease() {
    try {
      await release.mutateAsync({ driverId: driver.id });
      toast({ title: 'Vehicle released.' });
      setReleaseOpen(false);
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Release failed.'), variant: 'destructive' });
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        A driver holds one vehicle at a time. Assigning a different vehicle releases the current one
        automatically and both are kept in the assignment history.
      </p>

      {current ? (
        <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-4">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-start gap-2.5">
              <Truck className="mt-0.5 size-4 shrink-0 text-emerald-600" />
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  <p className="font-medium">{current.plate_number}</p>
                  <Badge className="gap-1 bg-emerald-600 text-xs hover:bg-emerald-600">
                    <CheckCircle className="size-3" />
                    Assigned
                  </Badge>
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">{current.label}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Since {formatDate(current.assigned_at)}
                </p>
              </div>
            </div>
            {!isArchived && (
              <Button
                variant="outline"
                size="sm"
                className="h-7 gap-1 text-xs text-destructive hover:text-destructive"
                onClick={() => setReleaseOpen(true)}
                disabled={release.isPending}
              >
                <XCircle className="size-3" />
                Release
              </Button>
            )}
          </div>
        </div>
      ) : (
        <div className="flex flex-col items-center justify-center rounded-lg border py-10 text-center">
          <Truck className="mb-2 size-8 text-muted-foreground/30" />
          <p className="text-sm font-medium">No vehicle assigned</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            This driver cannot start deliveries until a vehicle is assigned.
          </p>
        </div>
      )}

      {isArchived ? (
        <Alert>
          <AlertDescription className="text-sm">
            This driver is archived — vehicles cannot be assigned.
          </AlertDescription>
        </Alert>
      ) : (
        <div className="flex items-end gap-2">
          <div className="flex-1 space-y-1.5">
            <Label>{current ? 'Change to another vehicle' : 'Assign a vehicle'}</Label>
            <Select value={vehicleId} onValueChange={setVehicleId}>
              <SelectTrigger disabled={isLoading || options.length === 0}>
                <SelectValue
                  placeholder={
                    isLoading
                      ? 'Loading vehicles…'
                      : options.length === 0
                        ? 'No unassigned vehicles available'
                        : 'Select an available vehicle'
                  }
                />
              </SelectTrigger>
              <SelectContent>
                {options.map((v) => (
                  <SelectItem key={v.id} value={String(v.id)}>
                    {v.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Button className="gap-1.5" onClick={handleAssign} disabled={!vehicleId || assign.isPending}>
            {assign.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <Truck className="size-3.5" />}
            {current ? 'Change' : 'Assign'}
          </Button>
        </div>
      )}

      <AlertDialog open={releaseOpen} onOpenChange={setReleaseOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Release Vehicle</AlertDialogTitle>
            <AlertDialogDescription>
              Release <strong>{current?.plate_number}</strong> from {driver.full_name}? The vehicle
              returns to the available pool and the assignment is kept in history.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleRelease}>Release</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// ── History tab ────────────────────────────────────────────────────────────────

function HistoryTab({ driver }: { driver: Driver }) {
  const history = driver.assignments ?? [];

  if (history.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center rounded-lg border py-12 text-center">
        <History className="mb-2 size-8 text-muted-foreground/30" />
        <p className="text-sm font-medium">No assignment history</p>
        <p className="mt-0.5 text-xs text-muted-foreground">
          Vehicle assignments will be recorded here permanently.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        {history.length} assignment{history.length !== 1 ? 's' : ''}, newest first. History is never
        removed.
      </p>
      <ol className="relative space-y-3 border-s ps-5">
        {history.map((a) => (
          <li key={a.id} className="relative">
            <span
              className={`absolute -start-[1.6rem] mt-1.5 size-2.5 rounded-full ring-4 ring-background ${
                a.is_active ? 'bg-emerald-600' : 'bg-muted-foreground/40'
              }`}
            />
            <div className="rounded-lg border p-3">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-medium">{a.vehicle_plate ?? `Vehicle #${a.vehicle_id}`}</p>
                {a.is_active ? (
                  <Badge className="gap-1 bg-emerald-600 text-xs hover:bg-emerald-600">
                    <CheckCircle className="size-3" />
                    Active
                  </Badge>
                ) : (
                  <Badge variant="secondary" className="text-xs">Released</Badge>
                )}
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                  <Clock className="size-3" />
                  {a.duration_days}d
                </span>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                {formatDate(a.assigned_at)} → {a.released_at ? formatDate(a.released_at) : 'present'}
              </p>
              {a.release_reason && (
                <p className="mt-1 text-xs text-muted-foreground">Reason: {a.release_reason}</p>
              )}
              {(a.assigned_by || a.released_by) && (
                <p className="mt-1 text-xs text-muted-foreground">
                  {a.assigned_by ? `Assigned by ${a.assigned_by}` : ''}
                  {a.assigned_by && a.released_by ? ' · ' : ''}
                  {a.released_by ? `Released by ${a.released_by}` : ''}
                </p>
              )}
            </div>
          </li>
        ))}
      </ol>
    </div>
  );
}

// ── Main drawer ────────────────────────────────────────────────────────────────

export function DriverDrawer({
  open,
  onOpenChange,
  editDriver,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editDriver: Driver | null;
}) {
  const { toast } = useToast();
  const isCreate = editDriver === null;

  const [form, setForm] = useState<DriverFormState>(EMPTY_FORM);
  const [tab, setTab] = useState('details');
  const [archiveConfirm, setArchiveConfirm] = useState(false);

  const { data: detail, isLoading } = useDriver(open && editDriver ? editDriver.id : null);
  const { data: nextCode } = useNextDriverCode(open && isCreate);

  const createDriver = useCreateDriver();
  const updateDriver = useUpdateDriver();
  const setStatus = useSetDriverStatus();

  const driver = detail ?? editDriver;
  const saving = createDriver.isPending || updateDriver.isPending;
  const readOnly = driver?.status === 'archived';

  useEffect(() => {
    if (!open) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setTab('details');
    if (editDriver === null) {
      setForm(EMPTY_FORM);
      return;
    }
    const s = detail ?? editDriver;
    setForm({
      driver_code: s.driver_code,
      full_name: s.full_name,
      mobile: s.mobile,
      national_id: s.national_id,
      date_of_birth: s.date_of_birth ?? '',
      address: s.address ?? '',
      employment_date: s.employment_date ?? '',
      shipping_company_id: s.shipping_company_id ? String(s.shipping_company_id) : '',
      license_number: s.license_number ?? '',
      license_type: s.license_type ?? '',
      license_issue_date: s.license_issue_date ?? '',
      license_expiry_date: s.license_expiry_date ?? '',
      license_issuing_authority: s.license_issuing_authority ?? '',
      notes: s.notes ?? '',
    });
  }, [open, editDriver, detail]);

  useEffect(() => {
    if (open && isCreate && nextCode) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setForm((p) => (p.driver_code === '' ? { ...p, driver_code: nextCode } : p));
    }
  }, [open, isCreate, nextCode]);

  async function handleSave() {
    if (!form.driver_code.trim() || !form.full_name.trim() || !form.mobile.trim() || !form.national_id.trim()) {
      toast({ title: 'Code, name, mobile and national ID are required.', variant: 'destructive' });
      return;
    }
    try {
      if (isCreate) {
        await createDriver.mutateAsync(toPayload(form));
        toast({ title: `Driver "${form.full_name}" created.` });
        onOpenChange(false);
      } else {
        await updateDriver.mutateAsync({ id: editDriver.id, payload: toPayload(form) });
        toast({ title: 'Changes saved.' });
      }
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Saving failed.'), variant: 'destructive' });
    }
  }

  async function handleSetStatus(status: 'active' | 'inactive' | 'archived') {
    if (!editDriver) return;
    try {
      await setStatus.mutateAsync({ id: editDriver.id, status });
      toast({
        title:
          status === 'archived'
            ? 'Driver archived. Any held vehicle was released.'
            : status === 'active'
              ? 'Driver activated.'
              : 'Driver deactivated.',
      });
      if (status === 'archived') setArchiveConfirm(false);
    } catch (err) {
      toast({ title: apiErrorMessage(err, 'Status change failed.'), variant: 'destructive' });
    }
  }

  const statusBadge =
    driver &&
    (driver.status === 'active' ? (
      <Badge className="bg-emerald-600 text-xs hover:bg-emerald-600">Active</Badge>
    ) : driver.status === 'inactive' ? (
      <Badge variant="secondary" className="text-xs">Inactive</Badge>
    ) : (
      <Badge variant="outline" className="gap-1 text-xs text-muted-foreground">
        <Archive className="size-3" />
        Archived
      </Badge>
    ));

  return (
    <>
      <PageDrawer
        open={open}
        onOpenChange={onOpenChange}
        title={isCreate ? 'New Driver' : (driver?.full_name ?? 'Driver')}
        description={
          isCreate
            ? 'Register a delivery driver and their licence details.'
            : `${driver?.driver_code ?? ''} — details, licence, documents and vehicle.`
        }
        size="xl"
      >
        <div className="flex h-full flex-col">
          {!isCreate && driver && (
            <div className="mb-3 flex flex-wrap items-center gap-2">
              {statusBadge}
              <LicenseStatusBadge
                status={driver.license_status}
                daysRemaining={driver.license_days_remaining}
                showCountdown
              />
              {driver.current_vehicle ? (
                <Badge variant="outline" className="gap-1 text-xs">
                  <Truck className="size-3" />
                  {driver.current_vehicle.plate_number}
                </Badge>
              ) : (
                <Badge variant="outline" className="gap-1 text-xs text-amber-600">
                  <Truck className="size-3" />
                  No vehicle
                </Badge>
              )}
            </div>
          )}

          {isCreate ? (
            <>
              <div className="min-h-0 flex-1 space-y-5 overflow-y-auto pr-1">
                <DetailsFields form={form} setForm={setForm} disabled={saving} />
                <Separator />
                <div>
                  <p className="mb-3 text-sm font-semibold">Licence</p>
                  <LicenseFields form={form} setForm={setForm} driver={null} disabled={saving} />
                </div>
              </div>
              <Separator className="my-4" />
              <div className="flex shrink-0 justify-end gap-2">
                <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={saving}>
                  Cancel
                </Button>
                <Button onClick={handleSave} disabled={saving} className="gap-1.5">
                  {saving && <Loader2 className="size-4 animate-spin" />}
                  Create Driver
                </Button>
              </div>
            </>
          ) : isLoading && !driver ? (
            <div className="space-y-3">
              <Skeleton className="h-8 w-full" />
              <Skeleton className="h-32 w-full" />
              <Skeleton className="h-32 w-full" />
            </div>
          ) : (
            driver && (
              <Tabs value={tab} onValueChange={setTab} className="flex min-h-0 flex-1 flex-col">
                <TabsList className="grid w-full shrink-0 grid-cols-5">
                  <TabsTrigger value="details">Details</TabsTrigger>
                  <TabsTrigger value="license">Licence</TabsTrigger>
                  <TabsTrigger value="documents" className="gap-1.5">
                    Docs
                    {driver.documents_count != null && driver.documents_count > 0 && (
                      <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">
                        {driver.documents_count}
                      </Badge>
                    )}
                  </TabsTrigger>
                  <TabsTrigger value="vehicle">Vehicle</TabsTrigger>
                  <TabsTrigger value="history" className="gap-1.5">
                    History
                    {driver.assignments_count != null && driver.assignments_count > 0 && (
                      <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">
                        {driver.assignments_count}
                      </Badge>
                    )}
                  </TabsTrigger>
                </TabsList>

                <div className="min-h-0 flex-1 overflow-y-auto pr-1 pt-4">
                  <TabsContent value="details" className="mt-0 space-y-4">
                    <DetailsFields form={form} setForm={setForm} disabled={saving || readOnly} />
                    <Separator />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="flex items-center gap-2">
                        {driver.status === 'archived' ? (
                          <Button
                            variant="outline"
                            size="sm"
                            className="gap-1.5"
                            onClick={() => handleSetStatus('inactive')}
                            disabled={setStatus.isPending}
                          >
                            <ArchiveRestore className="size-3.5" />
                            Restore from Archive
                          </Button>
                        ) : (
                          <>
                            {driver.status === 'active' ? (
                              <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5"
                                onClick={() => handleSetStatus('inactive')}
                                disabled={setStatus.isPending}
                              >
                                <XCircle className="size-3.5" />
                                Deactivate
                              </Button>
                            ) : (
                              <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5 text-emerald-700"
                                onClick={() => handleSetStatus('active')}
                                disabled={setStatus.isPending}
                              >
                                <CheckCircle className="size-3.5" />
                                Activate
                              </Button>
                            )}
                            <Button
                              variant="outline"
                              size="sm"
                              className="gap-1.5 text-destructive hover:text-destructive"
                              onClick={() => setArchiveConfirm(true)}
                              disabled={setStatus.isPending}
                            >
                              <Archive className="size-3.5" />
                              Archive
                            </Button>
                          </>
                        )}
                      </div>
                      {!readOnly && (
                        <Button onClick={handleSave} disabled={saving} className="gap-1.5">
                          {saving && <Loader2 className="size-4 animate-spin" />}
                          Save Changes
                        </Button>
                      )}
                    </div>
                  </TabsContent>

                  <TabsContent value="license" className="mt-0 space-y-4">
                    <LicenseFields form={form} setForm={setForm} driver={driver} disabled={saving || readOnly} />
                    {!readOnly && (
                      <>
                        <Separator />
                        <div className="flex justify-end">
                          <Button onClick={handleSave} disabled={saving} className="gap-1.5">
                            {saving && <Loader2 className="size-4 animate-spin" />}
                            Save Licence
                          </Button>
                        </div>
                      </>
                    )}
                  </TabsContent>

                  <TabsContent value="documents" className="mt-0">
                    <DocumentsTab driver={driver} />
                  </TabsContent>

                  <TabsContent value="vehicle" className="mt-0">
                    <VehicleTab driver={driver} />
                  </TabsContent>

                  <TabsContent value="history" className="mt-0">
                    <HistoryTab driver={driver} />
                  </TabsContent>
                </div>
              </Tabs>
            )
          )}
        </div>
      </PageDrawer>

      <AlertDialog open={archiveConfirm} onOpenChange={setArchiveConfirm}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Archive Driver</AlertDialogTitle>
            <AlertDialogDescription>
              Archive <strong>{driver?.full_name}</strong>? Any assigned vehicle is released back to
              the pool. Archived drivers are hidden from the default list and cannot be assigned
              vehicles or documents. Drivers are never deleted, so this can be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => handleSetStatus('archived')}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Archive
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
